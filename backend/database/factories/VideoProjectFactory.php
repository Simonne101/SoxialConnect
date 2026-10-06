<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\VideoProject;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VideoProject> */
class VideoProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'kind' => 'composition',
            'status' => 'draft',
            'title' => fake()->sentence(3),
            'aspect_ratio' => '9:16',
            'target_duration_ms' => 30_000,
            'width' => 1080,
            'height' => 1920,
            'fps' => 30,
        ];
    }

    /** Projet de découpage d'une vidéo longue (source obligatoire). */
    public function clipping(): static
    {
        return $this->state(['kind' => 'clipping', 'status' => 'pending', 'source_type' => 'url', 'source_url' => 'https://exemple.test/video.mp4', 'width' => null, 'height' => null, 'fps' => null]);
    }
}
