<?php

namespace Database\Factories;

use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Media> */
class MediaFactory extends Factory
{
    public function definition(): array
    {
        $id = (string) Str::uuid7();

        return [
            'id' => $id,
            'user_id' => User::factory(),
            'type' => 'image',
            'status' => 'ready',
            'scan_status' => 'clean',
            'original_name' => fake()->word().'.jpg',
            'disk' => 'media',
            'path' => "users/test/media/{$id}/original.jpg",
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'size_bytes' => 204800,
            'width' => 1080,
            'height' => 1080,
        ];
    }

    public function processing(): static
    {
        return $this->state(fn () => ['status' => 'processing', 'scan_status' => 'pending']);
    }
}
