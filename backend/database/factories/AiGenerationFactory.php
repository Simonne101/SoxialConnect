<?php

namespace Database\Factories;

use App\Models\AiGeneration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AiGeneration> */
class AiGenerationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'task' => 'script',
            'provider' => 'fake',
            'model' => 'fake-model',
            'status' => 'succeeded',
            'input' => ['topic' => fake()->sentence(3)],
        ];
    }
}
