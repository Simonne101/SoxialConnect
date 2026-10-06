<?php

namespace Database\Factories;

use App\Models\VideoProject;
use App\Models\VideoRenderJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VideoRenderJob> */
class VideoRenderJobFactory extends Factory
{
    public function definition(): array
    {
        $spec = ['schema' => 'test', 'scenes' => []];

        return [
            'video_project_id' => VideoProject::factory(),
            // Propriétaire du projet (exigé par la clé étrangère composite).
            'user_id' => fn (array $attributes) => VideoProject::findOrFail($attributes['video_project_id'])->user_id,
            'status' => 'succeeded',
            'progress' => 100,
            'spec' => $spec,
            'spec_hash' => hash('sha256', json_encode($spec)),
        ];
    }

    public function queued(): static
    {
        return $this->state(['status' => 'queued', 'progress' => 0]);
    }
}
