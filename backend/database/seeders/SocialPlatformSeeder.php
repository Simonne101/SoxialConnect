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

        // Statistiques (account_analytics, post_analytics) : « to_verify » partout tant qu'aucun adaptateur de
        // collecte n'existe (étape 7, P0 « vérité des écrans ») ; « supported » seulement une fois la collecte livrée.
        $platforms = [
            ['facebook', 'Facebook', 'available', $caps(['text_post', 'image_post', 'video_post', 'webhooks'], ['multi_image_post', 'story', 'reel_short', 'native_scheduling', 'account_analytics', 'post_analytics'])],
            ['instagram', 'Instagram', 'available', $caps(['image_post', 'multi_image_post', 'video_post', 'reel_short', 'webhooks'], ['story', 'account_analytics', 'post_analytics'])],
            ['tiktok', 'TikTok', 'available', $caps([], ['image_post', 'account_analytics', 'post_analytics', 'webhooks'], ['video_post'])],
            ['youtube', 'YouTube', 'available', $caps(['native_scheduling'], ['reel_short', 'webhooks', 'account_analytics', 'post_analytics'], ['video_post'])],
            // V1 : profil personnel, texte seul (Share on LinkedIn). Images, vidéos, pages : à venir.
            ['linkedin', 'LinkedIn', 'available', $caps(['text_post'], ['image_post', 'video_post'])],
            // V1 : texte seul (POST /2/tweets). Images et vidéos (media.write) : à venir.
            ['x', 'X (Twitter)', 'available', $caps(['text_post'], ['image_post', 'multi_image_post', 'video_post', 'account_analytics', 'post_analytics', 'webhooks'])],
            ['pinterest', 'Pinterest', 'available', $caps(['image_post', 'video_post'], ['multi_image_post', 'native_scheduling', 'webhooks', 'account_analytics', 'post_analytics'])],
            // Bot API : publication immédiate (la programmation est faite par SoxialConnect) ; pas de statistiques pour les bots.
            ['telegram', 'Telegram', 'available', $caps(['text_post', 'image_post', 'multi_image_post', 'video_post', 'webhooks'])],
            ['threads', 'Threads', 'coming_soon', $caps(['text_post', 'image_post', 'video_post'], ['multi_image_post', 'webhooks', 'account_analytics', 'post_analytics'])],
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
