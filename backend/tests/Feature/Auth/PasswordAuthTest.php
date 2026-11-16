<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Login con correo y contraseña (personal), cierre de sesión y control de roles.
 * RF03, RF04, RF05, RNF09, RNF11.
 */
class PasswordAuthTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = User::ROLE_DOCENTE, array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'status' => 'active',
            'password' => 'secret-123',
        ], $attrs));
    }

    public function test_el_personal_inicia_sesion_y_recibe_un_token(): void
    {
        $user = $this->staff();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret-123'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'email', 'role']])
            ->assertJsonMissingPath('user.password');
    }

    public function test_credenciales_incorrectas_devuelven_401(): void
    {
        $user = $this->staff();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'otra'])
            ->assertUnauthorized();
    }

    public function test_un_estudiante_no_puede_entrar_con_contrasena(): void
    {
        $user = $this->staff(User::ROLE_ESTUDIANTE);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret-123'])
            ->assertForbidden();
    }

    public function test_una_cuenta_inactiva_no_puede_entrar(): void
    {
        $user = $this->staff(attrs: ['status' => 'inactive']);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret-123'])
            ->assertForbidden();
    }

    public function test_el_login_tiene_limite_de_intentos(): void
    {
        $user = $this->staff();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'mal']);
        }

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret-123'])
            ->assertStatus(429);
    }

    public function test_rutas_protegidas_exigen_token(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_el_logout_revoca_el_token(): void
    {
        $user = $this->staff();
        $token = $user->createToken('test')->plainTextToken;
        $headers = ['Authorization' => "Bearer $token"];

        $this->getJson('/api/user', $headers)->assertOk();
        $this->postJson('/api/auth/logout', [], $headers)->assertOk();

        // El guard guarda al usuario en memoria entre peticiones del mismo test.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/user', $headers)->assertUnauthorized();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_un_token_vencido_es_rechazado(): void
    {
        $user = $this->staff();
        $token = $user->createToken('test')->plainTextToken;

        $this->travel(config('sanctum.expiration') + 1)->minutes();

        $this->getJson('/api/user', ['Authorization' => "Bearer $token"])->assertUnauthorized();
    }

    public function test_solo_el_admin_registra_personal(): void
    {
        $payload = [
            'name' => 'Docente Nuevo',
            'email' => 'docente@cepre.test',
            'password' => 'password-123',
            'role' => User::ROLE_DOCENTE,
        ];

        $this->actingAs($this->staff(User::ROLE_DOCENTE))
            ->postJson('/api/auth/register', $payload)
            ->assertForbidden();

        $this->actingAs($this->staff(User::ROLE_ADMIN))
            ->postJson('/api/auth/register', $payload)
            ->assertCreated()
            ->assertJsonPath('role', User::ROLE_DOCENTE);
    }

    public function test_el_registro_no_permite_crear_estudiantes(): void
    {
        $this->actingAs($this->staff(User::ROLE_ADMIN))
            ->postJson('/api/auth/register', [
                'name' => 'Alumno',
                'email' => 'alumno@cepre.test',
                'password' => 'password-123',
                'role' => User::ROLE_ESTUDIANTE,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }
}
