<?php

namespace Database\Factories;

use App\Models\SocialPlatform;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SocialPlatform> */
class SocialPlatformFactory extends Factory
{
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->lexify('plat????'),
            'name' => fake()->company(),
            'availability' => 'available',
            'capabilities' => ['text_post' => 'supported'],
        ];
    }
}
