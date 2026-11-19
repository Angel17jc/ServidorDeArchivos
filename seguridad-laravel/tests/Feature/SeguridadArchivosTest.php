<?php

namespace Tests\Feature;

use App\Models\Archivo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class SeguridadArchivosTest extends TestCase
{
    use RefreshDatabase;

    private User $angel;

    private User $luis;

    private User $admin;

    private Archivo $deAngel;

    private Archivo $deLuis;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.l5.url' => 'http://l5.test', 'services.l5.key' => 'clave-ficticia-l5']);

        $this->angel = User::factory()->create(['name' => 'Angel']);
        $this->luis = User::factory()->create(['name' => 'Luis']);
        $this->admin = User::factory()->admin()->create(['name' => 'Admin']);
        $this->deAngel = $this->archivo($this->angel, 'camisa-azul.jpg');
        $this->deLuis = $this->archivo($this->luis, 'zapatos-cafe.png');
    }

    private function archivo(User $propietario, string $nombre): Archivo
    {
        return Archivo::create([
            'user_id' => $propietario->id, 'nombre_original' => $nombre,
            'storage_key' => fake()->uuid().'.jpg', 'mime_type' => 'image/jpeg', 'size' => 1234,
        ]);
    }

    private function comoToken(User $usuario): static
    {
        return $this->withToken($usuario->createToken('pruebas')->plainTextToken);
    }

    // ---------- P1 · acceso permitido (2xx) ----------

    public function test_p1_cliente_consulta_solo_sus_archivos(): void
    {
        $this->comoToken($this->angel)->getJson('/api/archivos')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.nombre_original', 'camisa-azul.jpg')
            ->assertJsonPath('0.propietario.id', $this->angel->id);
    }

    public function test_p1_administrador_consulta_todos_los_archivos(): void
    {
        $this->comoToken($this->admin)->getJson('/api/archivos')->assertOk()->assertJsonCount(2);
    }

    public function test_p1_cliente_consulta_un_archivo_propio(): void
    {
        $this->comoToken($this->angel)->getJson("/api/archivos/{$this->deAngel->id}")->assertOk()->assertJsonPath('id', $this->deAngel->id);
    }

    public function test_p1_cliente_sube_un_archivo_y_queda_como_propietario(): void
    {
        Http::fake(['l5.test/upload' => Http::response(['storage_key' => '0f8fad5b-d9cb-469f-a165-70867728950e.png', 'url' => '/files/x', 'mime_type' => 'image/png', 'size' => 70], 201)]);

        $this->comoToken($this->angel)
            ->post('/api/archivos', ['file' => UploadedFile::fake()->image('foto.png'), 'user_id' => $this->luis->id], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('propietario.id', $this->angel->id); // ignora el user_id enviado por el cliente

        // Laravel se identificó ante L5 con la clave de servicio de la configuración
        Http::assertSent(fn (PeticionHttp $r) => $r->url() === 'http://l5.test/upload' && $r->hasHeader('x-api-key', 'clave-ficticia-l5'));
        $this->assertDatabaseHas('archivos', ['storage_key' => '0f8fad5b-d9cb-469f-a165-70867728950e.png', 'user_id' => $this->angel->id]);
    }

    public function test_p1_administrador_elimina_un_archivo(): void
    {
        Http::fake(['l5.test/files/*' => Http::response(null, 204)]);

        $this->comoToken($this->admin)->deleteJson("/api/archivos/{$this->deLuis->id}")->assertNoContent();

        $this->assertDatabaseMissing('archivos', ['id' => $this->deLuis->id]);
        Http::assertSent(fn (PeticionHttp $r) => $r->method() === 'DELETE' && $r->hasHeader('x-api-key', 'clave-ficticia-l5'));
    }

    // ---------- P2 · sin autenticación (401) ----------

    public function test_p2_sin_token_todas_las_rutas_protegidas_responden_401(): void
    {
        foreach ([['get', '/api/archivos'], ['get', "/api/archivos/{$this->deAngel->id}"], ['post', '/api/archivos'], ['delete', "/api/archivos/{$this->deAngel->id}"], ['get', '/api/me']] as [$metodo, $ruta]) {
            $this->json($metodo, $ruta)->assertUnauthorized()->assertJsonPath('error', 'No autenticado');
        }
    }

    public function test_p2_token_invalido_responde_401(): void
    {
        $this->withToken('99|token-que-no-existe')->getJson('/api/archivos')->assertUnauthorized();
    }

    public function test_p2_token_revocado_con_logout_deja_de_funcionar(): void
    {
        $token = $this->angel->createToken('pruebas')->plainTextToken;

        $this->withToken($token)->postJson('/api/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/archivos')->assertUnauthorized();
    }

    public function test_p2_token_vencido_responde_401(): void
    {
        $token = $this->angel->createToken('pruebas', ['*'], now()->subMinute())->plainTextToken;

        $this->withToken($token)->getJson('/api/archivos')->assertUnauthorized();
    }

    // ---------- P3 · autenticado sin permisos (403) ----------

    public function test_p3_cliente_no_puede_consultar_un_archivo_ajeno(): void
    {
        $this->comoToken($this->angel)->getJson("/api/archivos/{$this->deLuis->id}")
            ->assertForbidden()
            ->assertJsonPath('error', 'Acceso denegado');
    }

    public function test_p3_cliente_no_puede_eliminar_archivos(): void
    {
        $this->comoToken($this->angel)->deleteJson("/api/archivos/{$this->deAngel->id}")->assertForbidden();

        $this->assertDatabaseHas('archivos', ['id' => $this->deAngel->id]); // no se borró
        Http::assertNothingSent(); // ni siquiera se contactó a L5
    }

    // ---------- Inicio de sesión y gestión de secretos ----------

    public function test_login_valido_entrega_un_token_bearer_sin_datos_sensibles(): void
    {
        $usuario = User::factory()->create(['email' => 'angel@tienda.test', 'password' => 'clave-de-prueba-123']);

        $respuesta = $this->postJson('/api/login', ['email' => 'angel@tienda.test', 'password' => 'clave-de-prueba-123'])
            ->assertOk()
            ->assertJsonPath('tipo', 'Bearer')
            ->assertJsonPath('usuario.rol', 'cliente');

        $this->assertStringNotContainsString('password', $respuesta->getContent());
        $this->assertStringNotContainsString($usuario->password, $respuesta->getContent()); // ni el hash
    }

    public function test_login_fallido_no_registra_la_contrasena(): void
    {
        Log::spy();
        User::factory()->create(['email' => 'angel@tienda.test', 'password' => 'clave-correcta-123']);

        $this->postJson('/api/login', ['email' => 'angel@tienda.test', 'password' => 'Intento-Secreto-999'])
            ->assertUnauthorized()
            ->assertExactJson(['error' => 'Credenciales inválidas']);

        Log::shouldHaveReceived('warning')->with('Inicio de sesión fallido', Mockery::on(
            fn (array $c) => $c['email'] === 'a***@tienda.test' && ! str_contains(json_encode($c), 'Intento-Secreto-999')
        ));
    }

    public function test_login_se_bloquea_tras_cinco_intentos(): void
    {
        foreach (range(1, 5) as $intento) {
            $this->postJson('/api/login', ['email' => 'nadie@tienda.test', 'password' => 'x'])->assertUnauthorized();
        }
        $this->postJson('/api/login', ['email' => 'nadie@tienda.test', 'password' => 'x'])->assertStatus(429);
    }

    public function test_el_registro_de_accesos_denegados_no_guarda_el_token_completo(): void
    {
        Log::spy();
        $token = $this->angel->createToken('pruebas')->plainTextToken;

        $this->withToken($token)->getJson("/api/archivos/{$this->deLuis->id}")->assertForbidden();

        $secreto = explode('|', $token, 2)[1];
        Log::shouldHaveReceived('warning')->with('Acceso denegado', Mockery::on(
            fn (array $c) => $c['estado'] === 403 && $c['usuario_id'] === $this->angel->id
                && str_contains($c['token'], '(oculto)') && ! str_contains(json_encode($c), $secreto)
        ));
    }

    public function test_el_registro_incluye_los_401_con_el_token_enmascarado(): void
    {
        Log::spy();

        $this->withToken('77|SecretoInventadoQueNoDebeAparecer')->getJson('/api/archivos')->assertUnauthorized();

        Log::shouldHaveReceived('warning')->with('Acceso denegado', Mockery::on(
            fn (array $c) => $c['estado'] === 401 && $c['usuario_id'] === null
                && $c['token'] === '77|Secr…(oculto)' && ! str_contains(json_encode($c), 'InventadoQueNoDebeAparecer')
        ));
    }

    public function test_si_l5_no_responde_la_carga_devuelve_502_y_no_registra_el_archivo(): void
    {
        Http::fake(['l5.test/upload' => Http::failedConnection()]);

        $this->comoToken($this->angel)
            ->post('/api/archivos', ['file' => UploadedFile::fake()->image('foto.png')], ['Accept' => 'application/json'])
            ->assertStatus(502)
            ->assertExactJson(['error' => 'Servicio de archivos no disponible']);

        $this->assertDatabaseCount('archivos', 2);
    }

    public function test_la_carga_rechaza_tipos_no_permitidos(): void
    {
        $this->comoToken($this->angel)
            ->post('/api/archivos', ['file' => UploadedFile::fake()->create('script.php', 1, 'application/x-php')], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        Http::assertNothingSent();
    }
}
