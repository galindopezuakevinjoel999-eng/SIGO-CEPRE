<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use Tests\TestCase;

/**
 * Inicio de sesión con Google OAuth y registro automático. RF01, RF02, RF05, RNF08.
 * Google se simula con Mockery: no se hacen llamadas reales.
 */
class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.frontend_url' => 'http://frontend.test']);
    }

    private function fakeGoogleUser(array $overrides = []): void
    {
        $data = array_merge([
            'id' => 'google-123',
            'name' => 'Ana Quispe',
            'email' => 'ana@gmail.com',
            'avatar' => 'https://lh3.googleusercontent.com/ana.jpg',
            'email_verified' => true,
        ], $overrides);

        $googleUser = (new GoogleUser)
            ->setRaw(['email_verified' => $data['email_verified']])
            ->map([
                'id' => $data['id'],
                'name' => $data['name'],
                'email' => $data['email'],
                'avatar' => $data['avatar'],
            ]);

        $provider = Mockery::mock();
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($googleUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    /** Ejecuta el callback y devuelve los parámetros con los que se redirigió al frontend. */
    private function runCallback(): array
    {
        $response = $this->get('/api/auth/google/callback');
        $response->assertRedirect();

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('http://frontend.test/auth/callback?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        return $query;
    }

    private function exchange(string $code): TestResponse
    {
        return $this->postJson('/api/auth/google/exchange', ['code' => $code]);
    }

    public function test_un_usuario_nuevo_se_registra_como_estudiante(): void
    {
        $this->fakeGoogleUser();

        $query = $this->runCallback();

        $this->assertArrayHasKey('code', $query);
        $this->assertArrayNotHasKey('token', $query, 'El token no debe viajar en la URL');

        $user = User::where('email', 'ana@gmail.com')->firstOrFail();
        $this->assertSame(User::ROLE_ESTUDIANTE, $user->role);
        $this->assertSame('google-123', $user->google_id);
        $this->assertSame('https://lh3.googleusercontent.com/ana.jpg', $user->photo_url);
    }

    public function test_el_codigo_se_canjea_por_un_token_valido(): void
    {
        $this->fakeGoogleUser();
        $code = $this->runCallback()['code'];

        $token = $this->exchange($code)
            ->assertOk()
            ->assertJsonPath('user.email', 'ana@gmail.com')
            ->json('token');

        $this->getJson('/api/user', ['Authorization' => "Bearer $token"])
            ->assertOk()
            ->assertJsonPath('role', User::ROLE_ESTUDIANTE);
    }

    public function test_el_codigo_solo_sirve_una_vez(): void
    {
        $this->fakeGoogleUser();
        $code = $this->runCallback()['code'];

        $this->exchange($code)->assertOk();
        $this->exchange($code)->assertUnprocessable();
    }

    public function test_el_codigo_expira(): void
    {
        $this->fakeGoogleUser();
        $code = $this->runCallback()['code'];

        $this->travel(2)->minutes();

        $this->exchange($code)->assertUnprocessable();
    }

    public function test_un_codigo_inventado_es_rechazado(): void
    {
        $this->exchange(str_repeat('a', 64))->assertUnprocessable();
    }

    public function test_una_cuenta_existente_se_vincula_por_email_sin_cambiar_su_rol(): void
    {
        $docente = User::factory()->create([
            'email' => 'ana@gmail.com',
            'role' => User::ROLE_DOCENTE,
            'status' => 'active',
        ]);
        $this->fakeGoogleUser();

        $this->runCallback();

        $docente->refresh();
        $this->assertSame('google-123', $docente->google_id);
        $this->assertSame(User::ROLE_DOCENTE, $docente->role);
        $this->assertSame(1, User::count(), 'No debe crear un usuario duplicado');
    }

    public function test_un_correo_no_verificado_es_rechazado(): void
    {
        $this->fakeGoogleUser(['email_verified' => false]);

        $this->assertSame(['error' => 'email_not_verified'], $this->runCallback());
        $this->assertSame(0, User::count());
    }

    public function test_un_estudiante_inhabilitado_no_puede_entrar(): void
    {
        User::factory()->create([
            'email' => 'ana@gmail.com',
            'role' => User::ROLE_ESTUDIANTE,
            'status' => 'active',
            'student_status' => 'inhabilitado',
        ]);
        $this->fakeGoogleUser();

        $this->assertSame(['error' => 'account_disabled'], $this->runCallback());
    }

    public function test_una_cuenta_inactiva_no_puede_entrar(): void
    {
        User::factory()->create(['email' => 'ana@gmail.com', 'status' => 'inactive']);
        $this->fakeGoogleUser();

        $this->assertSame(['error' => 'account_inactive'], $this->runCallback());
    }

    public function test_si_google_falla_se_redirige_con_error_sin_exponer_detalles(): void
    {
        $provider = Mockery::mock();
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andThrow(new \RuntimeException('detalle interno'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->assertSame(['error' => 'google_failed'], $this->runCallback());
    }
}
