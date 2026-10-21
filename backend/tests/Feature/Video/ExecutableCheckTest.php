<?php

namespace Tests\Feature\Video;

use App\Domain\Video\Rendering\ExecutableCheck;
use App\Domain\Video\Rendering\FfmpegBinary;
use App\Domain\Video\VideoRenderService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Contrôle des exécutables externes (ExecutableCheck), incident du 2026-10-13 : sous la charge d'un rendu,
 * les requêtes de suivi de l'API dépassaient max_execution_time pendant `ffmpeg -version` + `ffprobe -version`
 * (erreur 500 → « Projet introuvable » à l'écran) et un délai dépassé était mis en cache PARTAGÉ comme
 * « FFmpeg absent », ce qui pouvait faire échouer un rendu du worker.
 *
 * SIMULÉ : FFmpeg/FFprobe remplacés par un faux exécutable (script PHP) lent, défaillant ou conforme.
 */
class ExecutableCheckTest extends TestCase
{
    use RefreshDatabase; // l'un des tests crée un utilisateur : jamais conservé dans la base de test

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/fake-ffmpeg');
        File::ensureDirectoryExists($this->dir);
        $ffmpeg = $this->fake('ffmpeg');
        $ffprobe = $this->fake('ffprobe');
        config(['video.binaries.ffmpeg' => $ffmpeg, 'video.binaries.ffprobe' => $ffprobe, 'video.binary_check_timeout_seconds' => 5]);
        app(ExecutableCheck::class)->forget('ffmpeg', $ffmpeg);
        app(ExecutableCheck::class)->forget('ffprobe', $ffprobe);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir ?? '');
        parent::tearDown();
    }

    public function test_a_slow_start_never_reports_ffmpeg_as_missing_nor_shares_a_failure(): void
    {
        $this->mode('slow');
        $started = microtime(true);

        $this->assertTrue((new FfmpegBinary)->isAvailable(), 'Démarrage lent : non vérifié, jamais « absent ».');
        $this->assertLessThan(20, microtime(true) - $started, 'Délai de vérification respecté.');
        $this->assertTrue(app(VideoRenderService::class)->rendererAvailable());
        $this->assertSame([], $this->sharedEntries(), 'Aucun état non vérifié partagé avec les autres processus.');
    }

    public function test_only_a_proven_failure_is_unavailable_and_a_repaired_binary_is_verified_again(): void
    {
        config(['video.binary_check_timeout_seconds' => 120]); // échec réel, quelle que soit la charge du poste
        $this->mode('broken');
        $this->assertFalse((new FfmpegBinary)->isAvailable());
        $this->assertSame([], $this->sharedEntries(), 'Échec jamais partagé : un autre processus revérifie.');

        $this->mode('ok');
        app(ExecutableCheck::class)->forget('ffmpeg', (string) config('video.binaries.ffmpeg')); // équivalent d'un nouveau processus
        $this->assertTrue((new FfmpegBinary)->isAvailable());
        $this->assertCount(1, $this->sharedEntries(), 'Succès partagé (lié au fichier vérifié).');
    }

    public function test_the_api_reports_the_renderer_available_while_ffmpeg_starts_slowly(): void
    {
        $this->mode('slow');
        $user = User::factory()->create();

        $this->actingAs($user)->fromSpa()->getJson('/api/v1/clipper/projects')->assertOk()->assertJsonPath('meta.renderer_available', true);
    }

    /** Faux exécutable (« .cmd » sous Windows) qui lit son mode dans mode.txt. */
    private function fake(string $name): string
    {
        $script = $this->dir.'/'.$name.'.php';
        File::put($script, sprintf(<<<'PHP'
            <?php
            $mode = trim((string) @file_get_contents(__DIR__.'/mode.txt')) ?: 'ok';
            if ($mode === 'slow') { sleep(12); }
            if ($mode === 'broken') { fwrite(STDERR, "boom\n"); exit(1); }
            echo "%s version 9.0.2-test\n";
            PHP, $name));
        if (PHP_OS_FAMILY === 'Windows') {
            File::put($this->dir.'/'.$name.'.cmd', '@"'.PHP_BINARY.'" "'.$script.'" %*'."\r\n");

            return $this->dir.DIRECTORY_SEPARATOR.$name.'.cmd';
        }
        File::put($this->dir.'/'.$name, "#!/bin/sh\nexec \"".PHP_BINARY."\" \"{$script}\" \"\$@\"\n");
        chmod($this->dir.'/'.$name, 0755);

        return $this->dir.'/'.$name;
    }

    private function mode(string $mode): void
    {
        File::put($this->dir.'/mode.txt', $mode);
    }

    /** @return list<string> clés partagées (cache) des vérifications FFmpeg réussies */
    private function sharedEntries(): array
    {
        $binary = (string) config('video.binaries.ffmpeg');
        $key = 'video:executable:ffmpeg:'.hash('sha256', $binary.'|'.filesize($binary).'|'.filemtime($binary));

        return Cache::has($key) ? [$key] : [];
    }
}
