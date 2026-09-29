<?php

namespace Database\Factories;

use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Post> */
class PostFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'status' => 'draft',
            'timezone' => 'Europe/Paris',
            'source' => 'manual',
        ];
    }

    public function scheduled(): static
    {
        return $this->state(fn () => ['status' => 'scheduled', 'scheduled_at' => now()->addDay()]);
    }

    public function published(): static
    {
        return $this->state(fn () => ['status' => 'published', 'scheduled_at' => now()->subDay(), 'published_at' => now()->subDay()]);
    }
}
