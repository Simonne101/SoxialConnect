<?php

namespace Database\Factories;

use App\Models\Media;
use App\Models\VideoProject;
use App\Models\VideoProjectAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VideoProjectAsset> */
class VideoProjectAssetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'video_project_id' => VideoProject::factory(),
            // Propriétaire du projet (exigé par les clés étrangères composites).
            'user_id' => fn (array $attributes) => VideoProject::findOrFail($attributes['video_project_id'])->user_id,
            'media_id' => fn (array $attributes) => Media::factory()->create(['user_id' => $attributes['user_id']])->id,
        ];
    }
}
