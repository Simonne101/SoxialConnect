<?php

namespace Database\Seeders;

use App\Models\SocialPlatform;
use Illuminate\Database\Seeder;

/**
 * Référentiel des plateformes (docs/api-integration.md). Les capacités sont des hypothèses de
 * départ : « to_verify » = désactivé en production tant que l'API officielle n'est pas vérifiée.
 * Idempotent (updateOrCreate sur la clé).
 */
class SocialPlatformSeeder extends Seeder
{
    public function run(): void
    {
        $caps = fn (array $supported, array $toVerify = [], array $review = []) => array_merge(
            array_fill_keys(['text_post', 'image_post', 'multi_image_post', 'video_post', 'story', 'reel_short', 'native_scheduling', 'account_analytics', 'post_analytics', 'webhooks'], 'unsupported'),
            array_fill_keys($supported, 'supported'),
            array_fill_keys($toVerify, 'to_verify'),
            array_fill_keys($review, 'requires_review'),
        );

        $platforms = [
            ['facebook', 'Facebook', 'available', $caps(['text_post', 'image_post', 'video_post', 'webhooks'], ['multi_image_post', 'story', 'reel_short', 'native_scheduling', 'account_analytics', 'post_analytics'])],
            ['instagram', 'Instagram', 'available', $caps(['image_post', 'multi_image_post', 'video_post', 'reel_short', 'account_analytics', 'post_analytics', 'webhooks'], ['story'])],
            ['tiktok', 'TikTok', 'available', $caps([], ['image_post', 'account_analytics', 'post_analytics', 'webhooks'], ['video_post'])],
            ['youtube', 'YouTube', 'available', $caps(['native_scheduling', 'account_analytics', 'post_analytics'], ['reel_short', 'webhooks'], ['video_post'])],
            ['linkedin', 'LinkedIn', 'available', $caps(['text_post', 'image_post', 'video_post'], ['multi_image_post', 'webhooks'], ['account_analytics', 'post_analytics'])],
            ['x', 'X (Twitter)', 'available', $caps(['text_post', 'image_post', 'video_post'], ['multi_image_post', 'account_analytics', 'post_analytics', 'webhooks'])],
            ['pinterest', 'Pinterest', 'available', $caps(['image_post', 'video_post', 'account_analytics', 'post_analytics'], ['multi_image_post', 'native_scheduling', 'webhooks'])],
            ['threads', 'Threads', 'coming_soon', $caps(['text_post', 'image_post', 'video_post', 'account_analytics', 'post_analytics'], ['multi_image_post', 'webhooks'])],
        ];

        foreach ($platforms as [$key, $name, $availability, $capabilities]) {
            SocialPlatform::updateOrCreate(['key' => $key], [
                'name' => $name,
                'availability' => $availability,
                'capabilities' => $capabilities,
            ]);
        }
    }
}
