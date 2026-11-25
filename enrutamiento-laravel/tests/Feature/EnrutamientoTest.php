<?php

namespace Tests\Feature;

use App\Consumidores\ConsumidorComprobantes;
use App\Consumidores\ConsumidorLimpieza;
use App\Consumidores\ConsumidorMiniaturas;
use App\Mensajeria\PublicadorMensajes;
use App\Models\Comprobante;
use App\Models\Evento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class EnrutamientoTest extends TestCase
{
    use RefreshDatabase;

    private const IMAGEN = 'products/81c70c21-c96c-41f7-b2b3-79e5690a918f.jpg';

    private const PDF = 'payments/5b0e7f6a-2c1d-4e8f-9a3b-6c7d8e9f0a1b.pdf';

    /** Publicador en memoria: guarda lo publicado en lugar de enviarlo a RabbitMQ. */
    private object $publicador;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['mensajeria.l5.url' => 'http://l5.test', 'mensajeria.l5.key' => 'clave-ficticia']);

        $this->publicador = new class implements PublicadorMensajes
        {
            public array $publicados = [];

            public bool $fallar = false;

            public function publicar(string $routingKey, array $mensaje, int $prioridad): void
            {
                if ($this->fallar) {
                    throw new RuntimeException('Conexión rechazada por el broker');
                }
                $this->publicados[] = ['routing_key' => $routingKey, 'mensaje' => $mensaje, 'prioridad' => $prioridad];
            }
        };
        $this->app->instance(PublicadorMensajes::class, $this->publicador);
    }

    private function evento(array $cambios = []): array
    {
        return array_replace_recursive([
            'id' => 'evt-1', 'tipo' => 'imagen_producto', 'prioridad' => 'normal', 'estado' => 'confirmado',
            'correlation_id' => 'prueba-1', 'datos' => ['storage_key' => self::IMAGEN, 'tamano_bytes' => 48000, 'referencia' => 'PROD-1'],
        ], $cambios);
    }

    private function enviar(array $cuerpo, array $cabeceras = [])
    {
        return $this->withHeaders($cabeceras)->postJson('/api/eventos', $cuerpo);
    }

    // ---------- Content-Based Router: tres destinos ----------

    public function test_imagen_de_producto_va_solo_a_la_cola_de_miniaturas(): void
    {
        $this->enviar($this->evento())
            ->assertStatus(202)
            ->assertJsonPath('resultado', 'enrutado')
            ->assertJsonPath('regla', 'R1')
            ->assertJsonPath('destino', 'miniaturas')
            ->assertJsonPath('cola', 'eventos.miniaturas')
            ->assertJsonPath('prioridad_amqp', 5);

        $this->assertCount(1, $this->publicador->publicados);
        $this->assertSame('miniaturas', $this->publicador->publicados[0]['routing_key']);
        $this->assertDatabaseHas('eventos', ['evento_id' => 'evt-1', 'resultado' => 'enrutado', 'destino' => 'miniaturas', 'procesamiento' => 'pendiente']);
    }

    public function test_comprobante_de_pago_va_a_comprobantes_con_prioridad_alta(): void
    {
        $this->enviar($this->evento(['tipo' => 'comprobante_pago', 'prioridad' => 'alta', 'datos' => ['storage_key' => self::PDF]]))
            ->assertStatus(202)
            ->assertJsonPath('regla', 'R2')
            ->assertJsonPath('destino', 'comprobantes');

        $this->assertSame('comprobantes', $this->publicador->publicados[0]['routing_key']);
        $this->assertSame(9, $this->publicador->publicados[0]['prioridad']);
    }

    public function test_archivo_eliminado_va_a_limpieza(): void
    {
        $this->enviar($this->evento(['tipo' => 'archivo_eliminado', 'prioridad' => 'baja']))
            ->assertStatus(202)
            ->assertJsonPath('regla', 'R3')
            ->assertJsonPath('destino', 'limpieza');

        $this->assertSame('limpieza', $this->publicador->publicados[0]['routing_key']);
        $this->assertSame(1, $this->publicador->publicados[0]['prioridad']);
    }

    // ---------- Mensajes no reconocidos: no se descartan ----------

    public function test_tipo_desconocido_se_publica_en_no_enrutables(): void
    {
        $this->enviar($this->evento(['tipo' => 'factura_proveedor']))
            ->assertStatus(202)
            ->assertJsonPath('resultado', 'no_enrutable')
            ->assertJsonPath('regla', 'R4')
            ->assertJsonPath('cola', 'eventos.no_enrutables');

        $this->assertSame('no_enrutables', $this->publicador->publicados[0]['routing_key']);
        $this->assertDatabaseHas('eventos', ['evento_id' => 'evt-1', 'resultado' => 'no_enrutable', 'destino' => 'no_enrutables']);
    }

    public function test_tipo_conocido_con_contenido_que_no_coincide_va_a_no_enrutables(): void
    {
        $this->enviar($this->evento(['datos' => ['storage_key' => self::PDF]]))
            ->assertStatus(202)
            ->assertJsonPath('resultado', 'no_enrutable')
            ->assertJsonPath('regla', 'R4');

        $this->assertSame('no_enrutables', $this->publicador->publicados[0]['routing_key']);
    }

    // ---------- Message Filter ----------

    public function test_operacion_cancelada_se_filtra_y_no_se_publica(): void
    {
        $this->enviar($this->evento(['tipo' => 'comprobante_pago', 'estado' => 'cancelado', 'datos' => ['storage_key' => self::PDF]]))
            ->assertStatus(200)
            ->assertJsonPath('resultado', 'filtrado')
            ->assertJsonPath('regla', 'F1')
            ->assertJsonPath('publicado', false);

        $this->assertSame([], $this->publicador->publicados);
        $this->assertDatabaseHas('eventos', ['evento_id' => 'evt-1', 'resultado' => 'filtrado', 'regla' => 'F1', 'destino' => null, 'procesamiento' => null]);
    }

    public function test_archivo_que_supera_10_mb_se_filtra(): void
    {
        $this->enviar($this->evento(['datos' => ['tamano_bytes' => 15 * 1024 * 1024]]))
            ->assertStatus(200)
            ->assertJsonPath('resultado', 'filtrado')
            ->assertJsonPath('regla', 'F2');

        $this->assertSame([], $this->publicador->publicados);
    }

    // ---------- Validación ----------

    public function test_mensaje_sin_campo_obligatorio_se_rechaza_con_422(): void
    {
        $evento = $this->evento();
        unset($evento['tipo']);

        $this->enviar($evento)
            ->assertStatus(422)
            ->assertJsonPath('resultado', 'invalido')
            ->assertJsonPath('errores.tipo.0', 'Falta el campo tipo.');

        $this->assertSame([], $this->publicador->publicados);
        $this->assertDatabaseHas('eventos', ['evento_id' => 'evt-1', 'resultado' => 'invalido', 'regla' => 'V']);
    }

    public function test_clave_de_archivo_fuera_del_formato_de_l5_se_rechaza(): void
    {
        $this->enviar($this->evento(['datos' => ['storage_key' => '../../etc/passwd']]))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('datos.storage_key', 'errores');

        $this->assertSame([], $this->publicador->publicados);
    }

    // ---------- Transporte y correlación ----------

    public function test_broker_caido_responde_503_como_fallo_de_transporte(): void
    {
        $this->publicador->fallar = true;

        $this->enviar($this->evento())
            ->assertStatus(503)
            ->assertJsonPath('resultado', 'fallo_transporte');

        $this->assertDatabaseHas('eventos', ['evento_id' => 'evt-1', 'resultado' => 'fallo_transporte', 'procesamiento' => null]);
    }

    public function test_conserva_el_correlation_id_de_la_cabecera_y_se_puede_consultar(): void
    {
        $this->enviar($this->evento(), ['X-Correlation-ID' => 'pedido-77'])
            ->assertHeader('X-Correlation-ID', 'pedido-77')
            ->assertJsonPath('correlation_id', 'pedido-77');

        $this->assertSame('pedido-77', $this->publicador->publicados[0]['mensaje']['correlation_id']);
        $this->getJson('/api/eventos?correlation_id=pedido-77')->assertOk()->assertJsonCount(1)->assertJsonPath('0.destino', 'miniaturas');
    }

    // ---------- Consumidores ----------

    private function mensaje(string $tipo, string $clave, string $destino): array
    {
        return ['message_id' => 'm-1', 'correlation_id' => 'prueba-1', 'evento_id' => 'evt-1', 'tipo' => $tipo,
            'datos' => ['storage_key' => $clave, 'referencia' => 'PED-1'], 'enrutamiento' => ['destino' => $destino]];
    }

    public function test_consumidor_de_miniaturas_descarga_la_imagen_y_sube_la_miniatura_a_l5(): void
    {
        $img = imagecreatetruecolor(800, 600);
        ob_start();
        imagejpeg($img);
        $jpeg = (string) ob_get_clean();
        Http::fake([
            'l5.test/files/products/*' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
            'l5.test/files?folder=products' => Http::response(['storage_key' => 'products/0f8fad5b-d9cb-469f-a165-70867728950e.jpg'], 201),
        ]);

        $r = app(ConsumidorMiniaturas::class)->procesar($this->mensaje('imagen_producto', self::IMAGEN, 'miniaturas'));

        $this->assertSame('products/0f8fad5b-d9cb-469f-a165-70867728950e.jpg', $r['archivo_generado']);
        $this->assertStringContainsString('200x150', $r['detalle']);
        Http::assertSent(fn (PeticionHttp $p) => $p->method() === 'POST' && $p->hasHeader('x-api-key', 'clave-ficticia'));
    }

    public function test_consumidor_de_comprobantes_registra_un_pdf_valido(): void
    {
        Http::fake(['l5.test/files/payments/*' => Http::response("%PDF-1.4\n...", 200)]);

        $r = app(ConsumidorComprobantes::class)->procesar($this->mensaje('comprobante_pago', self::PDF, 'comprobantes'));

        $this->assertStringContainsString('PDF 1.4 válido', $r['detalle']);
        $this->assertSame('por_revisar', Comprobante::where('storage_key', self::PDF)->value('estado'));
    }

    public function test_consumidor_de_comprobantes_rechaza_un_archivo_que_no_es_pdf(): void
    {
        Http::fake(['l5.test/files/payments/*' => Http::response('no soy un pdf', 200)]);

        $this->expectExceptionMessage('El archivo no es un PDF válido');
        app(ConsumidorComprobantes::class)->procesar($this->mensaje('comprobante_pago', self::PDF, 'comprobantes'));
    }

    public function test_consumidor_de_limpieza_elimina_el_archivo_y_es_idempotente(): void
    {
        Http::fakeSequence('l5.test/files/*')->push('', 204)->push(['error' => 'Archivo no encontrado'], 404);
        $consumidor = app(ConsumidorLimpieza::class);
        $mensaje = $this->mensaje('archivo_eliminado', self::IMAGEN, 'limpieza');

        $this->assertStringContainsString('eliminado de L5', $consumidor->procesar($mensaje)['detalle']);
        $this->assertStringContainsString('ya no existía', $consumidor->procesar($mensaje)['detalle']);
        $this->assertSame(0, Evento::count());
    }
}
