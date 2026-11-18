<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class OrquestacionTest extends TestCase
{
    private const CLAVE = '9791436a-39e9-40ea-83b5-74579dba854a.jpg';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests(); // ninguna prueba llama a servicios reales
    }

    private function servicios(array $reemplazos = []): void
    {
        Http::fake($reemplazos + [
            '*/usuarios/7' => Http::response(['id' => 7, 'nombre' => 'Ana']),
            '*/estados/101' => Http::response(['solicitud_id' => 101, 'estado' => 'pendiente']),
        ]);
    }

    // ---------- Flujo normal ----------

    public function test_compone_la_respuesta_unificada_y_propaga_el_cid(): void
    {
        $this->servicios();

        $this->withHeader('X-Correlation-ID', 'clase-101')
            ->getJson('/api/resumen-solicitud/101')
            ->assertOk()
            ->assertExactJson([
                'solicitud_id' => 101,
                'usuario' => ['id' => 7, 'nombre' => 'Ana'],
                'estado' => ['solicitud_id' => 101, 'estado' => 'pendiente'],
                'correlation_id' => 'clase-101',
            ])
            ->assertHeader('X-Correlation-ID', 'clase-101');

        // El orquestador llamó a los dos servicios, en orden, con el mismo cid
        Http::assertSentCount(2);
        Http::assertSentInOrder([
            fn (Request $r) => str_ends_with($r->url(), '/usuarios/7') && $r->hasHeader('X-Correlation-ID', 'clase-101'),
            fn (Request $r) => str_ends_with($r->url(), '/estados/101') && $r->hasHeader('X-Correlation-ID', 'clase-101'),
        ]);
    }

    public function test_genera_un_uuid_si_el_cliente_no_envia_cid(): void
    {
        $this->servicios();

        $respuesta = $this->getJson('/api/resumen-solicitud/101')->assertOk();

        $cid = $respuesta->json('correlation_id');
        $this->assertTrue(Str::isUuid($cid));
        $respuesta->assertHeader('X-Correlation-ID', $cid);
        Http::assertSent(fn (Request $r) => $r->hasHeader('X-Correlation-ID', $cid));
    }

    public function test_reemplaza_un_cid_invalido_por_un_uuid(): void
    {
        $this->servicios();

        $cid = $this->withHeader('X-Correlation-ID', '<script>alert(1)</script>')
            ->getJson('/api/resumen-solicitud/101')
            ->assertOk()
            ->json('correlation_id');

        $this->assertTrue(Str::isUuid($cid));
    }

    // ---------- Fallos: 502 controlado, sin trazas ----------

    public function test_servicio_que_no_responde_devuelve_502_controlado(): void
    {
        $this->servicios(['*/estados/101' => Http::failedConnection()]);

        $this->withHeader('X-Correlation-ID', 'clase-101')
            ->getJson('/api/resumen-solicitud/101')
            ->assertStatus(502)
            ->assertExactJson(['error' => 'Dependencia no disponible', 'correlation_id' => 'clase-101'])
            ->assertHeader('X-Correlation-ID', 'clase-101');
    }

    public function test_codigo_http_de_error_de_una_dependencia_devuelve_502(): void
    {
        $this->servicios(['*/estados/999' => Http::response(['error' => 'Solicitud no encontrada'], 404)]);

        $this->getJson('/api/resumen-solicitud/999')
            ->assertStatus(502)
            ->assertJsonPath('error', 'Dependencia no disponible');
    }

    public function test_servicio_con_error_interno_devuelve_502(): void
    {
        $this->servicios(['*/usuarios/7' => Http::response(['error' => 'falla'], 503)]);

        $this->getJson('/api/resumen-solicitud/101')->assertStatus(502);
        Http::assertSentCount(1); // si usuarios falla, no se consulta estados
    }

    public function test_respuesta_200_con_contenido_invalido_devuelve_502(): void
    {
        $this->servicios(['*/estados/101' => Http::response(['solicitud_id' => 555, 'estado' => 'pendiente'])]);

        $this->getJson('/api/resumen-solicitud/101')->assertStatus(502);
    }

    public function test_respuesta_que_no_es_json_devuelve_502(): void
    {
        $this->servicios(['*/usuarios/7' => Http::response('<html>error</html>', 200)]);

        $this->getJson('/api/resumen-solicitud/101')->assertStatus(502);
    }

    public function test_el_502_no_expone_trazas_ni_detalles_internos(): void
    {
        $this->servicios(['*/estados/101' => Http::failedConnection()]);

        $cuerpo = $this->getJson('/api/resumen-solicitud/101')->assertStatus(502)->getContent();

        foreach (['exception', 'trace', 'file', 'line', 'Connection', '8002', 'estados'] as $detalle) {
            $this->assertStringNotContainsString($detalle, $cuerpo);
        }
    }

    public function test_la_falla_se_registra_con_el_cid_y_el_servicio(): void
    {
        Log::spy();
        $this->servicios(['*/estados/101' => Http::failedConnection()]);

        $this->withHeader('X-Correlation-ID', 'clase-101')->getJson('/api/resumen-solicitud/101')->assertStatus(502);

        Log::shouldHaveReceived('withContext')->with(['cid' => 'clase-101']);
        Log::shouldHaveReceived('warning')->with('Falla de orquestación', Mockery::on(
            fn (array $c) => $c['servicio'] === 'estados' && $c['motivo'] === 'sin conexión o tiempo agotado'
        ));
    }

    public function test_la_ruta_solo_acepta_identificadores_numericos(): void
    {
        $this->getJson('/api/resumen-solicitud/abc')->assertNotFound();
        Http::assertNothingSent();
    }

    // ---------- Adaptación al proyecto: catálogo + Nodo L5 ----------

    private function catalogoYL5(array $reemplazos = []): void
    {
        Http::fake($reemplazos + [
            '*/productos/15' => Http::response(['id' => 15, 'nombre' => 'Camisa azul', 'precio' => 19.99, 'imagen' => self::CLAVE]),
            '*/files/'.self::CLAVE => Http::response('', 200, ['Content-Type' => 'image/jpeg', 'Content-Length' => '13406']),
        ]);
    }

    public function test_resumen_de_producto_compone_catalogo_e_imagen_del_nodo_l5(): void
    {
        $this->catalogoYL5();

        $this->withHeader('X-Correlation-ID', 'tienda-15')
            ->getJson('/api/resumen-producto/15')
            ->assertOk()
            ->assertExactJson([
                'producto_id' => 15,
                'producto' => ['id' => 15, 'nombre' => 'Camisa azul', 'precio' => 19.99],
                'imagen' => ['storage_key' => self::CLAVE, 'url' => '/files/'.self::CLAVE, 'mime_type' => 'image/jpeg', 'size' => 13406],
                'correlation_id' => 'tienda-15',
            ]);

        // La segunda consulta depende de la primera: usa la clave que devolvió el catálogo
        Http::assertSentInOrder([
            fn (Request $r) => str_ends_with($r->url(), '/productos/15'),
            fn (Request $r) => $r->method() === 'HEAD' && str_ends_with($r->url(), '/files/'.self::CLAVE) && $r->hasHeader('X-Correlation-ID', 'tienda-15'),
        ]);
    }

    public function test_resumen_de_producto_devuelve_502_si_l5_no_tiene_la_imagen(): void
    {
        $this->catalogoYL5(['*/files/'.self::CLAVE => Http::response('', 404)]);

        $this->getJson('/api/resumen-producto/15')
            ->assertStatus(502)
            ->assertJsonPath('error', 'Dependencia no disponible');
    }

    public function test_resumen_de_producto_rechaza_una_clave_de_imagen_peligrosa(): void
    {
        $this->catalogoYL5(['*/productos/15' => Http::response(['id' => 15, 'nombre' => 'Camisa azul', 'precio' => 19.99, 'imagen' => '../.env'])]);

        $this->getJson('/api/resumen-producto/15')->assertStatus(502);
        Http::assertSentCount(1); // no se llega a consultar L5
    }
}
