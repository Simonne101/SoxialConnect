<?php

namespace Database\Factories;

use App\Models\VideoProject;
use App\Models\VideoTrack;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VideoTrack> */
class VideoTrackFactory extends Factory
{
    public function definition(): array
    {
        return [
            'video_project_id' => VideoProject::factory(),
            'type' => 'video',
            'name' => 'Vidéo',
            'position' => fake()->unique()->numberBetween(0, 10_000),
            'locked' => false,
            'muted' => false,
            'hidden' => false,
        ];
    }

    public function type(string $type): static
    {
        return $this->state(['type' => $type, 'name' => ucfirst($type)]);
    }
}
