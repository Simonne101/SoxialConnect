<?php

namespace Tests\Feature\Config;

use App\Domain\AI\Enums\AiTask;
use App\Domain\Video\Enums\AspectRatio;
use Tests\TestCase;

/**
 * config/ai.php et config/video.php (ADR-042) : chargement, valeurs par défaut sûres sans .env,
 * interprétation des variables d'environnement, aucun secret en dur, FFmpeg non requis.
 */
class AiVideoConfigTest extends TestCase
{
    /** @var list<string> */
    private array $setVariables = [];

    protected function tearDown(): void
    {
        foreach ($this->setVariables as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
        }
        parent::tearDown();
    }

    /** Recharge un fichier de configuration avec des variables d'environnement données. */
    private function load(string $file, array $env = []): array
    {
        foreach ($env as $name => $value) {
            $_SERVER[$name] = $_ENV[$name] = $value;
            $this->setVariables[] = $name;
        }

        return require config_path($file);
    }

    public function test_ai_config_loads_without_any_provider_or_secret(): void
    {
        $config = $this->load('ai.php', ['AI_DEFAULT_PROVIDER' => '', 'OPENAI_API_KEY' => '', 'ANTHROPIC_API_KEY' => '', 'GEMINI_API_KEY' => '', 'AI_DEFAULT_MODEL' => '']);

        $this->assertNull($config['default_provider']);
        foreach (['openai', 'anthropic', 'gemini'] as $provider) {
            $this->assertNull($config['providers'][$provider]['api_key'], "{$provider} : aucune clé en dur.");
            $this->assertStringStartsWith('https://', $config['providers'][$provider]['base_url']);
        }
        foreach ($config['tasks'] as $task => $settings) {
            $this->assertNotNull(AiTask::tryFrom($task), "Tâche inconnue : {$task}");
            $this->assertNull($settings['model'], 'Aucun modèle imposé sans .env.');
            $this->assertGreaterThan(0, $settings['max_output_tokens']);
        }
        $this->assertArrayHasKey('adapt', $config['tasks']);
        $this->assertSame(60, $config['timeout']);
        $this->assertSame('ai', $config['queue']['name']);
        $this->assertNull($config['budget']['daily_micros_per_user'], 'Sans plafond configuré.');
        $this->assertSame([], $config['pricing'], 'Aucun prix inventé.');
        $this->assertTrue($config['cache']['enabled']);
        $this->assertSame(config('ai.queue.name'), 'ai', 'Configuration chargée par Laravel.');
    }

    public function test_ai_config_reads_the_environment(): void
    {
        $config = $this->load('ai.php', [
            'AI_DEFAULT_PROVIDER' => 'anthropic',
            'ANTHROPIC_API_KEY' => 'valeur-de-test',
            'AI_DEFAULT_MODEL' => 'modele-general',
            'AI_MODEL_SCRIPT' => 'modele-script',
            'AI_TIMEOUT' => '45',
            'AI_DAILY_BUDGET_MICROS_PER_USER' => '500000',
            'AI_CACHE_ENABLED' => 'false',
        ]);

        $this->assertSame('anthropic', $config['default_provider']);
        $this->assertSame('valeur-de-test', $config['providers']['anthropic']['api_key']);
        $this->assertSame('modele-script', $config['tasks']['script']['model']);
        $this->assertSame('modele-general', $config['tasks']['ideas']['model'], 'Modèle par défaut si la tâche n\'en a pas.');
        $this->assertSame(45, $config['timeout']);
        $this->assertSame(500000, $config['budget']['daily_micros_per_user']);
        $this->assertFalse($config['cache']['enabled']);
    }

    public function test_video_config_has_v1_limits_and_does_not_require_ffmpeg(): void
    {
        $config = $this->load('video.php', ['VIDEO_FFMPEG_BINARY' => '', 'VIDEO_FFPROBE_BINARY' => '', 'VIDEO_FONT_FILE' => '', 'VIDEO_TEMP_PATH' => '']);

        $this->assertSame('ffmpeg', $config['renderer']);
        $this->assertSame(90_000, $config['limits']['max_duration_ms']);
        $this->assertSame(20, $config['limits']['max_scenes']);
        $this->assertSame(8, $config['limits']['max_tracks']);
        $this->assertSame(100, $config['limits']['max_items_per_track']);
        $this->assertSame(100, $config['limits']['max_assets']);
        $this->assertSame(100, $config['limits']['item_min_duration_ms']);
        $this->assertSame([500, 30_000], [$config['limits']['scene_min_duration_ms'], $config['limits']['scene_max_duration_ms']], 'Mêmes bornes que le CHECK de video_scenes.');
        $this->assertSame(30, $config['fps']['default']);
        $this->assertContains($config['fps']['default'], $config['fps']['allowed']);
        $this->assertSame(1800, $config['render']['timeout_seconds']);
        $this->assertSame(storage_path('app/private/tmp/video'), $config['temp_path'], 'Dossier temporaire sur le disque du projet par défaut.');

        // Un format par ratio du domaine, dimensions paires (H.264) et dans les limites.
        $this->assertEqualsCanonicalizing(array_column(AspectRatio::cases(), 'value'), array_keys($config['formats']));
        $this->assertContains($config['default_aspect_ratio'], array_keys($config['formats']));
        foreach ($config['formats'] as $ratio => $size) {
            $this->assertSame(0, $size['width'] % 2, $ratio);
            $this->assertSame(0, $size['height'] % 2, $ratio);
            $this->assertLessThanOrEqual($config['limits']['max_width'], $size['width'], $ratio);
            $this->assertLessThanOrEqual($config['limits']['max_height'], $size['height'], $ratio);
        }

        // Binaires : simples réglages, jamais vérifiés au chargement de la configuration ;
        // une variable vide (.env recopié de .env.example) retombe sur le nom par défaut.
        $this->assertSame('ffmpeg', $config['binaries']['ffmpeg']);
        $this->assertSame('ffprobe', $config['binaries']['ffprobe']);
    }

    public function test_video_config_reads_the_environment(): void
    {
        $config = $this->load('video.php', [
            'VIDEO_FFMPEG_BINARY' => 'C:\\ffmpeg\\bin\\ffmpeg.exe',
            'VIDEO_TEMP_PATH' => 'D:\\rendus',
            'VIDEO_MAX_DURATION_MS' => '60000',
            'VIDEO_MAX_SCENES' => '12',
            'VIDEO_DEFAULT_FPS' => '25',
            'VIDEO_RENDER_TIMEOUT' => '900',
        ]);

        $this->assertSame('C:\\ffmpeg\\bin\\ffmpeg.exe', $config['binaries']['ffmpeg']);
        $this->assertSame('D:\\rendus', $config['temp_path']);
        $this->assertSame(60_000, $config['limits']['max_duration_ms']);
        $this->assertSame(12, $config['limits']['max_scenes']);
        $this->assertSame(25, $config['fps']['default']);
        $this->assertSame(900, $config['render']['timeout_seconds']);
    }
}
