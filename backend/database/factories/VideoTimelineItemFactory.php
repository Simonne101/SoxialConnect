<?php

namespace Database\Factories;

use App\Models\VideoTimelineItem;
use App\Models\VideoTrack;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VideoTimelineItem> */
class VideoTimelineItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'video_track_id' => VideoTrack::factory()->type('text'),
            'type' => 'text',
            'start_ms' => 0,
            'duration_ms' => 2_000,
            'properties' => ['text' => fake()->sentence(3)],
        ];
    }
}
