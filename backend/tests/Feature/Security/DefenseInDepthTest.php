<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Défense en profondeur (étape 8, lot 7) : corps JSON bornés (413) et tâches planifiées à portée globale exécutées
 * une seule fois même sur plusieurs serveurs (verrou Redis « onOneServer »).
 */
class DefenseInDepthTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_oversized_json_body_is_refused_with_413_before_reaching_the_application(): void
    {
        config(['security.max_json_kb' => 1]);
        $user = User::factory()->create();

        $this->actingAs($user)->fromSpa()->patchJson('/api/v1/users/me', ['bio' => str_repeat('a', 2048)])
            ->assertStatus(413)->assertJsonPath('message', 'Requête trop volumineuse.');
        $this->assertNotSame(str_repeat('a', 2048), $user->fresh()->bio);

        $this->actingAs($user)->fromSpa()->patchJson('/api/v1/users/me', ['bio' => 'Courte présentation'])->assertOk();
    }

    public function test_the_announced_length_is_enough_to_refuse_without_reading_the_body(): void
    {
        config(['security.max_json_kb' => 1]);

        $this->call('POST', '/api/v1/auth/login', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'CONTENT_LENGTH' => (string) (5 * 1024 * 1024),
        ], '{}')->assertStatus(413);
    }

    public function test_the_default_limit_is_one_megabyte(): void
    {
        $this->assertSame(1024, (int) config('security.max_json_kb'));
    }

    public function test_shared_scheduled_tasks_run_on_one_server_only_and_local_ones_on_each(): void
    {
        $events = collect(app(Schedule::class)->events())->mapWithKeys(fn ($e) => [trim(str_replace(["'", '"'], '', strstr((string) $e->command, 'artisan') ?: (string) $e->command), ' ') => $e]);
        $byCommand = fn (string $name) => $events->first(fn ($e, $command) => str_ends_with($command, $name));

        foreach (['publishing:dispatch-due', 'media:purge-deleted', 'clipper:expire-sources', 'clipper:retry-unavailable-sources', 'analytics:sync', 'analytics:purge'] as $command) {
            $this->assertNotNull($byCommand($command), $command);
            $this->assertTrue($byCommand($command)->onOneServer, "{$command} : une seule exécution pour tous les serveurs.");
        }
        foreach (['video:purge-temp', 'video:check-executables'] as $command) {
            $this->assertFalse($byCommand($command)->onOneServer, "{$command} : fichiers ou binaires locaux, sur chaque serveur.");
        }
    }
}
