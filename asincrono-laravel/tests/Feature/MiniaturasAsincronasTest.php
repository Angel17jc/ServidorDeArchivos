<?php

namespace Tests\Feature;

use App\Mensajeria\PublicadorMensajes;
use App\Models\Imagen;
use App\Models\Procesamiento;
use App\Services\ProcesadorMiniaturas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class MiniaturasAsincronasTest extends TestCase
{
    use RefreshDatabase;

    private const COLA = 'archivos.miniaturas';

    /** Publicador en memoria: guarda los mensajes en lugar de enviarlos a RabbitMQ. */
    private object $publicador;

    private Imagen $imagen;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['mensajeria.l5.url' => 'http://l5.test', 'mensajeria.l5.key' => 'clave-ficticia', 'mensajeria.miniaturas.retraso_ms' => 0]);

        $this->publicador = new class implements PublicadorMensajes
        {
            public array $publicados = [];

            public bool $fallar = false;

            public function publicar(string $cola, array $mensaje): void
            {
                if ($this->fallar) {
                    throw new RuntimeException('Conexión rechazada por el broker');
                }
                $this->publicados[] = ['cola' => $cola, 'mensaje' => $mensaje];
            }
        };
        $this->app->instance(PublicadorMensajes::class, $this->publicador);

        $this->imagen = Imagen::create(['producto' => 'Camisa azul', 'storage_key' => '81c70c21-c96c-41f7-b2b3-79e5690a918f.jpg', 'mime_type' => 'image/jpeg', 'size' => 1000]);
    }

    private function solicitar(array $cuerpo, array $cabeceras = [])
    {
        return $this->withHeaders($cabeceras)->postJson('/api/miniaturas', $cuerpo);
    }

    private function fotoJpeg(): string
    {
        $img = imagecreatetruecolor(800, 600);
        ob_start();
        imagejpeg($img);

        return (string) ob_get_clean();
    }

    private function l5Funcionando(): void
    {
        Http::fake([
            'l5.test/files/*' => Http::response($this->fotoJpeg(), 200, ['Content-Type' => 'image/jpeg']),
            'l5.test/upload' => Http::response(['storage_key' => '0f8fad5b-d9cb-469f-a165-70867728950e.jpg'], 201),
        ]);
    }

    // ---------- Productor ----------

    public function test_mensaje_valido_responde_202_y_publica_el_mensaje_en_la_cola(): void
    {
        $respuesta = $this->solicitar(['imagen_id' => $this->imagen->id, 'ancho' => 200], ['X-Correlation-ID' => 'prueba-1'])
            ->assertStatus(202)
            ->assertJsonPath('estado', 'pendiente')
            ->assertJsonPath('correlation_id', 'prueba-1')
            ->assertHeader('X-Correlation-ID', 'prueba-1');

        $this->assertCount(1, $this->publicador->publicados);
        ['cola' => $cola, 'mensaje' => $mensaje] = $this->publicador->publicados[0];
        $this->assertSame(self::COLA, $cola);
        $this->assertSame(['message_id', 'correlation_id', 'tipo', 'ocurrido_en', 'payload'], array_keys($mensaje));
        $this->assertTrue(Str::isUuid($mensaje['message_id']));
        $this->assertSame($respuesta->json('message_id'), $mensaje['message_id']);
        $this->assertSame('archivo.miniatura_solicitada', $mensaje['tipo']);
        $this->assertSame(['imagen_id' => $this->imagen->id, 'storage_key' => $this->imagen->storage_key, 'ancho' => 200], $mensaje['payload']);
        $this->assertDatabaseHas('procesamientos', ['message_id' => $mensaje['message_id'], 'estado' => 'pendiente', 'modo' => 'asincrono']);
    }

    public function test_conserva_el_correlation_id_enviado_en_el_cuerpo(): void
    {
        $this->solicitar(['imagen_id' => $this->imagen->id, 'correlation_id' => 'pedido-1001'])->assertStatus(202)->assertJsonPath('correlation_id', 'pedido-1001');
        $this->assertSame('pedido-1001', $this->publicador->publicados[0]['mensaje']['correlation_id']);
    }

    public function test_genera_un_correlation_id_si_el_cliente_no_lo_envia(): void
    {
        $cid = $this->solicitar(['imagen_id' => $this->imagen->id])->assertStatus(202)->json('correlation_id');

        $this->assertMatchesRegularExpression('/^img-[a-z0-9]{8}$/', $cid);
        $this->assertSame(200, $this->publicador->publicados[0]['mensaje']['payload']['ancho']); // ancho por defecto
    }

    public function test_mensaje_invalido_responde_422_y_no_publica_nada(): void
    {
        $this->solicitar(['ancho' => 200])->assertStatus(422)->assertJsonValidationErrors('imagen_id');
        $this->solicitar(['imagen_id' => 999])->assertStatus(422)->assertJsonValidationErrors('imagen_id');
        $this->solicitar(['imagen_id' => $this->imagen->id, 'ancho' => 20])->assertStatus(422)->assertJsonValidationErrors('ancho');
        $this->solicitar(['imagen_id' => $this->imagen->id, 'correlation_id' => '<script>'])->assertStatus(422)->assertJsonValidationErrors('correlation_id');

        $this->assertSame([], $this->publicador->publicados);
        $this->assertDatabaseCount('procesamientos', 0);
    }

    public function test_si_el_broker_no_responde_devuelve_503_y_marca_el_error(): void
    {
        $this->publicador->fallar = true;

        $this->solicitar(['imagen_id' => $this->imagen->id], ['X-Correlation-ID' => 'sin-broker'])
            ->assertStatus(503)
            ->assertJsonPath('correlation_id', 'sin-broker');
        $this->assertDatabaseHas('procesamientos', ['correlation_id' => 'sin-broker', 'estado' => 'error']);
    }

    // ---------- Consumidor ----------

    private function mensajePublicado(string $cid = 'prueba-1'): array
    {
        $this->solicitar(['imagen_id' => $this->imagen->id, 'ancho' => 200], ['X-Correlation-ID' => $cid])->assertStatus(202);

        return $this->publicador->publicados[0]['mensaje'];
    }

    public function test_el_consumidor_genera_la_miniatura_y_marca_completado(): void
    {
        $mensaje = $this->mensajePublicado();
        $this->l5Funcionando();

        $procesamiento = app(ProcesadorMiniaturas::class)->procesar($mensaje);

        $this->assertSame('completado', $procesamiento->estado);
        $this->assertSame('0f8fad5b-d9cb-469f-a165-70867728950e.jpg', $procesamiento->miniatura_key);
        $this->assertSame(1, $procesamiento->intentos);
        // El correlation_id viaja también al Nodo L5, junto con la credencial de servicio en la subida
        Http::assertSent(fn (PeticionHttp $r) => $r->method() === 'GET' && $r->hasHeader('X-Correlation-ID', 'prueba-1'));
        Http::assertSent(fn (PeticionHttp $r) => $r->method() === 'POST' && $r->hasHeader('x-api-key', 'clave-ficticia') && $r->hasHeader('X-Correlation-ID', 'prueba-1'));
    }

    public function test_el_consumidor_es_idempotente_ante_un_mensaje_duplicado(): void
    {
        $mensaje = $this->mensajePublicado();
        $this->l5Funcionando();

        app(ProcesadorMiniaturas::class)->procesar($mensaje);
        app(ProcesadorMiniaturas::class)->procesar($mensaje); // RabbitMQ puede reentregar

        Http::assertSentCount(2); // un GET y un POST: el duplicado no repite el trabajo
        $this->assertSame(1, Procesamiento::first()->intentos);
    }

    public function test_si_falla_el_consumidor_marca_error_y_lanza_para_rechazar_el_mensaje(): void
    {
        $mensaje = $this->mensajePublicado('falla-1');
        Http::fake(['l5.test/files/*' => Http::response('', 404)]);

        try {
            app(ProcesadorMiniaturas::class)->procesar($mensaje);
            $this->fail('Se esperaba una excepción para que el consumidor haga nack');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('HTTP 404', $e->getMessage());
        }
        $this->assertDatabaseHas('procesamientos', ['correlation_id' => 'falla-1', 'estado' => 'error', 'error' => 'L5 no entregó la imagen (HTTP 404)']);
    }

    public function test_el_consumidor_rechaza_mensajes_desconocidos(): void
    {
        $this->expectException(RuntimeException::class);
        app(ProcesadorMiniaturas::class)->procesar(['message_id' => (string) Str::uuid(), 'tipo' => 'archivo.miniatura_solicitada']);
    }

    // ---------- Síncrono y seguimiento ----------

    public function test_el_modo_sincrono_responde_200_cuando_la_miniatura_ya_existe(): void
    {
        $this->l5Funcionando();

        $this->postJson('/api/miniaturas/sincrono', ['imagen_id' => $this->imagen->id], ['X-Correlation-ID' => 'sinc-1'])
            ->assertOk()
            ->assertJsonPath('estado', 'completado')
            ->assertJsonPath('modo', 'sincrono')
            ->assertJsonPath('miniatura.storage_key', '0f8fad5b-d9cb-469f-a165-70867728950e.jpg');
        $this->assertSame([], $this->publicador->publicados); // no pasa por la cola
    }

    public function test_seguimiento_por_message_id_y_por_correlation_id(): void
    {
        $mensaje = $this->mensajePublicado('seguimiento-1');

        $this->getJson('/api/procesamientos/'.$mensaje['message_id'])->assertOk()->assertJsonPath('estado', 'pendiente');
        $this->getJson('/api/procesamientos?correlation_id=seguimiento-1')->assertOk()->assertJsonCount(1)->assertJsonPath('0.message_id', $mensaje['message_id']);
        $this->getJson('/api/procesamientos/'.Str::uuid())->assertNotFound();
    }
}
