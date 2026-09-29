<?php

namespace Database\Factories;

use App\Models\SocialAccount;
use App\Models\SocialPlatform;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SocialAccount> */
class SocialAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'social_platform_id' => SocialPlatform::factory(),
            'external_account_id' => (string) fake()->unique()->numberBetween(100000, 999999999),
            'account_type' => 'page',
            'display_name' => fake()->company(),
            'status' => 'connected',
            'connected_at' => now(),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['status' => 'expired', 'status_reason' => 'Autorisation expirée.']);
    }
}
