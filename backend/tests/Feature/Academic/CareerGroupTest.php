<?php

namespace Tests\Feature\Academic;

use App\Models\Career;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Grupos de carreras A (Ingenierías), B (Salud) y C (Ciencias de la empresa). */
class CareerGroupTest extends TestCase
{
    use RefreshDatabase;

    public function test_se_pueden_crear_carreras_del_grupo_c(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/careers', ['code' => 'CON', 'name' => 'Contabilidad', 'group' => 'C'])
            ->assertCreated()
            ->assertJsonPath('group', 'C');
    }

    public function test_un_grupo_inexistente_es_rechazado(): void
    {
        $admin = User::factory()->admin()->create();
        $career = Career::factory()->create(['group' => 'A']);

        $this->actingAs($admin)
            ->postJson('/api/careers', ['code' => 'XYZ', 'name' => 'Otra', 'group' => 'D'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('group');

        $this->actingAs($admin)
            ->putJson("/api/careers/{$career->id}", ['group' => 'D'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('group');
    }

    public function test_scope_group_filtra_por_cualquier_grupo(): void
    {
        Career::factory()->create(['code' => 'SIS', 'group' => 'A']);
        Career::factory()->create(['code' => 'PSI', 'group' => 'B']);
        Career::factory()->create(['code' => 'ADM', 'group' => 'C']);
        Career::factory()->create(['code' => 'CON', 'group' => 'C']);

        $this->assertSame(['SIS'], Career::group('A')->pluck('code')->all());
        $this->assertSame(['PSI'], Career::group('b')->pluck('code')->all());
        $this->assertSame(2, Career::group('C')->count());
    }
}
