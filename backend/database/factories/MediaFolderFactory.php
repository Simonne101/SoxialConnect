<?php

namespace Database\Factories;

use App\Models\MediaFolder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MediaFolder> */
class MediaFolderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'parent_id' => null,
            'name' => fake()->unique()->words(2, true),
        ];
    }
}
