<?php

namespace Tests\Feature\Users;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Fuseau horaire du profil : mêmes règles que Content (identifiants IANA et alias officiels). */
class ProfileTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private function patchTimezone(User $user, mixed $timezone): TestResponse
    {
        return $this->actingAs($user)->fromSpa()->patchJson('/api/v1/users/me', ['timezone' => $timezone]);
    }

    public function test_canonical_and_alias_identifiers_are_accepted(): void
    {
        $user = User::factory()->create();

        foreach (['Europe/Paris', 'America/Toronto', 'UTC', 'America/Montreal', 'Asia/Calcutta'] as $tz) {
            $this->patchTimezone($user, $tz)->assertOk()->assertJsonPath('data.timezone', $tz);
            $this->assertSame($tz, $user->fresh()->timezone);
        }
    }

    public function test_validation_stays_strict(): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/Paris']);

        foreach (['Mars/Olympus', 'europe/paris', 'UTC+2', 'Paris', '', null, 42, ['Europe/Paris']] as $invalid) {
            $this->patchTimezone($user, $invalid)->assertUnprocessable()->assertJsonValidationErrors(['timezone']);
        }
        $this->assertSame('Europe/Paris', $user->fresh()->timezone);
    }

    public function test_profile_and_content_accept_the_same_alias(): void
    {
        $user = User::factory()->create();
        $account = SocialAccount::factory()->for($user)->create();

        $this->patchTimezone($user, 'America/Montreal')->assertOk();
        $this->actingAs($user)->fromSpa()->postJson('/api/v1/content/posts', [
            'mode' => 'schedule',
            'body' => 'Test fuseau',
            'timezone' => 'America/Montreal',
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'targets' => [['social_account_id' => $account->id, 'format' => 'feed']],
        ])->assertCreated()->assertJsonPath('data.timezone', 'America/Montreal');
    }

    public function test_other_profile_fields_are_unchanged_by_a_timezone_update(): void
    {
        $user = User::factory()->create(['name' => 'Marie', 'timezone' => 'Europe/Paris']);

        $this->patchTimezone($user, 'Asia/Calcutta')->assertOk()
            ->assertJsonPath('data.name', 'Marie')
            ->assertJsonPath('data.email', $user->email);
    }
}
