<?php

namespace Database\Factories;

use App\Models\VideoProject;
use App\Models\VideoScene;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VideoScene> */
class VideoSceneFactory extends Factory
{
    public function definition(): array
    {
        return [
            'video_project_id' => VideoProject::factory(),
            'position' => fake()->unique()->numberBetween(0, 10_000),
            'role' => 'body',
            'duration_ms' => 3_000,
            'fit' => 'cover',
            'transition' => 'none',
            'on_screen_text' => fake()->sentence(4),
        ];
    }
}
