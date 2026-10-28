<?php

namespace Tests\Feature\Clipper;

use App\Domain\Video\Enums\AcquisitionMethod;
use App\Domain\Video\Enums\SourceOwnership;
use App\Domain\Video\Enums\VideoSourceStatus;
use App\Domain\Video\Rendering\FfmpegBinary;
use App\Domain\Video\Rendering\MediaProbe;
use App\Domain\Video\Sources\VideoSourceService;
use App\Domain\Video\Sources\YtDlp\YtDlpBinary;
use App\Domain\Video\Sources\YtDlp\YtDlpVideoSourceProvider;
use App\Jobs\AcquireVideoSourceJob;
use App\Jobs\AnalyzeClipSourceJob;
use App\Models\Media;
use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoSource;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Acquisition par yt-dlp (ADR-059).
 *
 * SIMULÉ : l'exécutable yt-dlp est remplacé par un faux (script PHP lancé comme un exécutable) qui
 * enregistre les arguments reçus et renvoie des métadonnées, une erreur ou un fichier ; aucun accès à
 * YouTube. RÉEL : lancement d'un processus externe, surveillance (délai, annulation), vidéo MP4 produite
 * par FFmpeg, import dans la médiathèque, durée mesurée par FFprobe, nettoyage des dossiers temporaires.
 */
class YtDlpAcquisitionTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://youtu.be/dQw4w9WgXcQ?si=tracking';

    private User $user;

    private string $temp;

    private string $fake;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('media');
        Http::preventStrayRequests();
        Http::fake(['www.youtube.com/oembed*' => Http::response(['title' => 'Tears of Steel', 'author_name' => 'Blender', 'thumbnail_url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg'])]);
        $this->temp = storage_path('framework/testing/ytdlp-temp');
        $this->fake = storage_path('framework/testing/fake-ytdlp');
        File::ensureDirectoryExists($this->temp);
        $this->user = User::factory()->create();
        config([
            // Chaque cas crée un projet pour le même utilisateur : quota de projets actifs neutralisé ici
            // (testé dans tests/Feature/Security/QuotaTest.php, ADR-071).
            'quotas.clipping.max_active' => 0,
            'quotas.clipping.max_per_day' => 0,
            'video.temp_path' => $this->temp,
            'media.process_async' => false,
            'video.sources.providers' => [YtDlpVideoSourceProvider::class],
            'video.sources.yt_dlp.enabled' => true,
            'video.sources.yt_dlp.binary' => $this->installFake(),
        ]);
        $this->scenario([]);
        app(YtDlpBinary::class)->forget(); // état mémorisé par processus : jamais hérité d'un test précédent
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temp ?? '');
        File::deleteDirectory($this->fake ?? '');
        parent::tearDown();
    }

    // --- Attestation de droits -------------------------------------------------------------------

    public function test_without_rights_attestation_nothing_is_downloaded_until_the_user_confirms(): void
    {
        $project = $this->create();
        $source = $this->acquire($project);

        $this->assertSame(VideoSourceStatus::Unavailable, $source->status);
        $this->assertSame('RIGHTS_CONFIRMATION_REQUIRED', $source->error_code);
        $this->assertSame([], $this->calls(), 'yt-dlp jamais lancé sans attestation.');
        $status = $this->api()->getJson("/api/v1/clipper/projects/{$project->id}")->assertOk()->json('data.source_status');
        $this->assertSame([false, true], [$status['rights_attested'], $status['download_available']]);

        // L'utilisateur atteste : attestation horodatée, recherche relancée.
        $this->api()->postJson("/api/v1/clipper/projects/{$project->id}/source/acquire", ['rights_confirmed' => true])->assertStatus(202)
            ->assertJsonPath('data.source_status.rights_attested', true);
        $source = $project->videoSource()->firstOrFail();
        $this->assertSame(VideoSourceStatus::Pending, $source->status);
        $this->assertNotEmpty($source->metadata['rights_attested_at']);
        Queue::assertPushed(AcquireVideoSourceJob::class, 2);
    }

    // --- Parcours complet ------------------------------------------------------------------------

    public function test_an_attested_public_video_is_downloaded_imported_and_measured(): void
    {
        $file = $this->fake.'/fixture.mp4';
        $this->mp4($file, 6);
        $this->scenario(['download' => 'copy', 'file' => $file]);

        $project = $this->create(rights: true);
        $project->forceFill(['status' => 'ready'])->save(); // analyse depuis le lien terminée (file d'attente factice)
        $source = $this->acquire($project);
        $this->assertSame(VideoSourceStatus::Resolving, $source->status, (string) $source->error_message);
        $this->assertSame(AcquisitionMethod::YtDlp, $source->acquisition_method);
        $source = $this->acquire($project); // finalisation (médiathèque traitée, FFprobe)
        $this->assertSame(VideoSourceStatus::Ready, $source->status, (string) $source->error_message);

        $project->refresh();
        $media = Media::findOrFail($project->source_media_id);
        $this->assertSame([$this->user->id, 'video/mp4'], [$media->user_id, $media->mime_type]);
        $probe = app(MediaProbe::class)->probe(Storage::disk('media')->path($media->path));
        $this->assertTrue($probe->hasVideo && $probe->hasAudio);
        $this->assertEqualsWithDelta(6_000, $probe->durationMs, 300, 'Durée mesurée par FFprobe sur le fichier de la médiathèque.');
        Queue::assertPushed(AnalyzeClipSourceJob::class, fn ($job) => $job->projectId === $project->id);
        $this->assertSame([], glob($this->temp.'/{ytdlp,acquired}-*', GLOB_BRACE) ?: [], 'Dossier de téléchargement et copie temporaire supprimés.');

        // Arguments réellement reçus par l'exécutable : URL canonique, aucun cookie, aucune configuration externe.
        [$metadata, $download] = $this->calls();
        foreach ([$metadata, $download] as $args) {
            $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', end($args), 'URL reconstruite depuis l\'identifiant validé.');
            foreach (['--ignore-config', '--no-cookies', '--no-cookies-from-browser', '--no-playlist'] as $flag) {
                $this->assertContains($flag, $args);
            }
            $this->assertSame('youtube', $args[array_search('--use-extractors', $args, true) + 1]);
            $this->assertNotContains('--cookies', $args);
            $this->assertNotContains('--cookies-from-browser', $args);
            $this->assertNotContains('--proxy', $args, 'Aucun relais configuré : connexion directe.');
            $this->assertStringNotContainsString('si=tracking', implode(' ', $args));
        }
        $this->assertContains('-J', $metadata);
        $this->assertSame('mp4', $download[array_search('--merge-output-format', $download, true) + 1]);
        $this->assertStringContainsString('[height<=1080]', $download[array_search('-f', $download, true) + 1], 'Format transmis intact.');
        $this->assertStringEndsWith('source.%(ext)s', $download[array_search('-o', $download, true) + 1], 'Modèle de sortie transmis intact.');
    }

    // --- Relais réseau (YouTube refuse les adresses des hébergeurs) --------------------------------

    public function test_a_configured_proxy_is_passed_to_every_ytdlp_call_and_an_invalid_one_never_is(): void
    {
        $file = $this->fake.'/fixture.mp4';
        $this->mp4($file, 2);
        $proxy = 'socks5h://relais:s3cret@127.0.0.1:1080';
        config(['video.sources.yt_dlp.proxy' => " {$proxy} "]);
        $this->scenario(['download' => 'copy', 'file' => $file]);
        $this->acquire($this->create(rights: true));

        [$metadata, $download] = $this->calls();
        foreach ([$metadata, $download] as $args) {
            $this->assertSame($proxy, $args[array_search('--proxy', $args, true) + 1], 'Même relais pour les métadonnées et le téléchargement.');
            $this->assertContains('--no-cookies', $args, 'Le relais ne remplace aucun garde-fou.');
        }

        foreach (['ftp://relais.example:21', 'relais.example:1080', 'socks5://'] as $invalid) {
            config(['video.sources.yt_dlp.proxy' => $invalid]);
            $this->scenario(['download' => 'copy', 'file' => $file]);
            $this->acquire($this->create(rights: true));
            $this->assertNotContains('--proxy', $this->calls()[0], $invalid);
        }
    }

    public function test_an_unreachable_proxy_is_a_temporary_error_and_its_credentials_are_never_logged(): void
    {
        config(['video.sources.yt_dlp.proxy' => 'socks5h://relais:s3cret@10.0.0.9:1080']);
        Log::spy();
        $this->scenario(['meta_error' => 'ERROR: [youtube] dQw4w9WgXcQ: Unable to download API page: [Errno 111] Connection refused (caused by ProxyError("socks5h://relais:s3cret@10.0.0.9:1080 unreachable"))']);
        $source = $this->acquire($this->create(rights: true));

        $this->assertSame([VideoSourceStatus::WaitingForProvider, 'PROXY_UNAVAILABLE'], [$source->status, $source->error_code]);
        $this->assertStringNotContainsString('s3cret', (string) $source->error_message);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => $message === 'clipper.ytdlp_failed'
            && ($context['error_code'] ?? null) === 'PROXY_UNAVAILABLE'
            && ! str_contains((string) ($context['stderr'] ?? ''), 's3cret'));
    }

    // --- Parcours depuis l'interface (régression) ------------------------------------------------

    public function test_an_eligible_link_submitted_like_the_interface_reaches_the_ytdlp_provider_through_the_real_registry(): void
    {
        // Registre RÉEL de config/video.php (export Google puis yt-dlp), pas une liste propre au test.
        config(['video.sources.providers' => (require config_path('video.php'))['sources']['providers']]);
        $this->scenario(['download' => 'empty']);

        $id = $this->api()->postJson('/api/v1/clipper/projects', ['youtube_url' => self::URL, 'aspect_ratio' => '9:16', 'rights_confirmed' => true])
            ->assertCreated()->assertJsonPath('data.source_status.rights_attested', true)->assertJsonPath('meta.video_download_available', true)->json('data.id');
        $sourceId = (string) VideoProject::findOrFail($id)->videoSource()->value('id');
        Queue::assertPushed(AcquireVideoSourceJob::class, fn ($job) => $job->sourceId === $sourceId);

        app()->call([new AcquireVideoSourceJob($sourceId), 'handle']); // job exécuté comme par le worker
        $this->assertContains('-J', $this->calls()[0] ?? [], 'yt-dlp réellement lancé (métadonnées).');
        $source = VideoSource::findOrFail($sourceId);
        $this->assertSame(AcquisitionMethod::YtDlp, $source->acquisition_method);
        $this->assertSame('DOWNLOAD_INCOMPLETE', $source->error_code, 'Résultat du téléchargement, jamais le message générique.');
    }

    /**
     * Incident du 2026-10-12 : sous forte charge, `yt-dlp --version` (décompression de l'exécutable autonome)
     * a dépassé le délai de vérification ; l'échec, mis en cache PARTAGÉ, a fait conclure au worker
     * « outil indisponible » (YTDLP_UNAVAILABLE) pour le lien de la propre vidéo de l'utilisatrice.
     */
    public function test_a_slow_executable_start_never_makes_the_interface_flow_report_ytdlp_unavailable(): void
    {
        config(['video.sources.providers' => (require config_path('video.php'))['sources']['providers'], 'video.sources.yt_dlp.check_timeout' => 10]);
        $binary = app(YtDlpBinary::class);
        $binary->forget();
        $this->scenario(['version' => 'slow', 'download' => 'empty']);

        // Parcours de l'interface : capacités, création avec attestation, puis job d'acquisition (comme le worker).
        $this->api()->getJson('/api/v1/clipper/projects')->assertOk()->assertJsonPath('meta.video_download_available', true);
        $this->assertSame(YtDlpBinary::UNVERIFIED, $binary->state(), 'Démarrage lent : non vérifié, pas indisponible.');
        $this->assertNull(Cache::get('video:executable:yt-dlp:'.hash('sha256', $binary->path().'|'.filesize($binary->path()).'|'.filemtime($binary->path()))), 'Aucun échec partagé entre processus.');
        $id = $this->api()->postJson('/api/v1/clipper/projects', ['youtube_url' => 'https://youtube.com/shorts/dQw4w9WgXcQ?si=partage', 'aspect_ratio' => '9:16', 'rights_confirmed' => true])
            ->assertCreated()->assertJsonPath('data.source_status.download_available', true)->json('data.id');
        $sourceId = (string) VideoProject::findOrFail($id)->videoSource()->value('id');
        app()->call([new AcquireVideoSourceJob($sourceId), 'handle']);

        $source = VideoSource::findOrFail($sourceId);
        $this->assertNotSame('YTDLP_UNAVAILABLE', $source->error_code);
        $this->assertSame([AcquisitionMethod::YtDlp, 'DOWNLOAD_INCOMPLETE'], [$source->acquisition_method, $source->error_code], 'yt-dlp réellement lancé : résultat du téléchargement.');
        $this->assertContains('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $this->calls()[0], 'Lien Shorts de partage normalisé.');
    }

    public function test_only_a_proven_failure_makes_ytdlp_unavailable_and_it_is_never_shared_between_processes(): void
    {
        $binary = app(YtDlpBinary::class);
        $binary->forget();
        $this->scenario(['version' => 'broken']);

        $this->assertSame(YtDlpBinary::UNAVAILABLE, $binary->state());
        $source = $this->acquire($this->create(rights: true));
        $this->assertSame([VideoSourceStatus::Unavailable, 'YTDLP_UNAVAILABLE'], [$source->status, $source->error_code]);
        $this->assertNull(Cache::get('video:executable:yt-dlp:'.hash('sha256', $binary->path().'|'.filesize($binary->path()).'|'.filemtime($binary->path()))));

        // Réparé : l'état d'échec n'est mémorisé que 30 s dans CE processus ; un autre processus revérifie aussitôt.
        $this->scenario(['version' => 'ok']);
        $binary->forget(); // équivalent d'un nouveau processus
        $this->assertSame(YtDlpBinary::OK, $binary->state());
        $this->assertSame('2026.08.19', $binary->version());
    }

    public function test_every_ytdlp_launch_receives_the_application_temp_and_cache_directories(): void
    {
        app(YtDlpBinary::class)->forget();
        $this->scenario(['download' => 'empty']);
        $this->acquire($this->create(rights: true));

        $env = json_decode((string) File::get($this->fake.'/env.json'), true);
        $root = storage_path('app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'yt-dlp');
        $this->assertSame([$root.DIRECTORY_SEPARATOR.'tmp', $root.DIRECTORY_SEPARATOR.'tmp', $root.DIRECTORY_SEPARATOR.'cache'], [$env['TEMP'], $env['TMP'], $env['XDG_CACHE_HOME']]);
    }

    /** RÉEL (aucun réseau) : l'exécutable installé démarre via YtDlpBinary, avec l'environnement de l'application. */
    public function test_the_installed_executable_really_starts_in_the_application_environment(): void
    {
        $real = storage_path('app/yt-dlp/'.(PHP_OS_FAMILY === 'Windows' ? 'yt-dlp.exe' : 'yt-dlp'));
        if (! is_file($real)) {
            $this->markTestSkipped('yt-dlp non installé sur ce poste.');
        }
        config(['video.sources.yt_dlp.binary' => $real, 'video.sources.yt_dlp.check_timeout' => 300]);
        $binary = app(YtDlpBinary::class);
        $binary->forget();

        $this->assertSame(YtDlpBinary::OK, $binary->state());
        $this->assertMatchesRegularExpression('/^\d{4}\.\d{2}\.\d{2}/', (string) $binary->version());
        $this->assertTrue(app(YtDlpVideoSourceProvider::class)->isConfigured());
    }

    /**
     * RÉEL (aucun réseau) — incident du 2026-10-13 : dans le serveur web de `php artisan serve` (SAPI
     * cli-server, Laravel démarré), Symfony Process ne transmettait à l'enfant que les variables du .env
     * (getenv() ∩ $_SERVER) : yt-dlp ne démarrait pas (« Could not create temporary directory! »,
     * « _Py_HashRandomization_Init ») et l'API affichait « outil indisponible ». Serveur lancé exactement
     * comme ServeCommand (clés de $_ENV retirées sauf la liste de passage).
     */
    public function test_ytdlp_really_starts_from_the_api_web_server_process(): void
    {
        $real = storage_path('app/yt-dlp/'.(PHP_OS_FAMILY === 'Windows' ? 'yt-dlp.exe' : 'yt-dlp'));
        if (! is_file($real)) {
            $this->markTestSkipped('yt-dlp non installé sur ce poste.');
        }
        $router = $this->fake.'/cli-server-router.php';
        File::put($router, sprintf(<<<'PHP'
            <?php
            require %1$s;
            $app = require %2$s;
            set_time_limit(0); // prouver le démarrage réel (une requête web normale plafonne la vérification : état « unverified »)
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            config(['cache.default' => 'array', 'video.sources.yt_dlp.binary' => %3$s, 'video.sources.yt_dlp.check_timeout' => 300]);
            $binary = app(App\Domain\Video\Sources\YtDlp\YtDlpBinary::class);
            $before = $binary->state(); // requête web : aucune exécution tant que rien n'est vérifié
            echo 'sapi=', PHP_SAPI, ' state=', $binary->verify(), ' version=', $binary->version(), ' before=', $before, ' after=', $binary->state();
            PHP, var_export(base_path('vendor/autoload.php'), true), var_export(base_path('bootstrap/app.php'), true), var_export($real, true)));
        $pass = array_map('strtoupper', ServeCommand::$passthroughVariables);
        $env = collect($_ENV)->mapWithKeys(fn ($v, $k) => in_array(strtoupper((string) $k), $pass, true) ? [$k => $v] : [$k => false])->all();
        $port = random_int(20000, 40000);
        $server = new Process([PHP_BINARY, '-S', "127.0.0.1:{$port}", $router], public_path(), $env);
        $server->start();
        try {
            $body = false;
            for ($i = 0; $i < 20 && $body === false; $i++) {
                usleep(500_000);
                $body = @file_get_contents("http://127.0.0.1:{$port}/", false, stream_context_create(['http' => ['timeout' => 320]]));
            }
        } finally {
            $server->stop(1);
        }

        $this->assertMatchesRegularExpression('/^sapi=cli-server state=ok version=\d{4}\.\d{2}\.\d{2} before=unverified after=ok$/', (string) $body, 'Web : non vérifié sans exécution ; vérification forcée réelle ; puis succès partagé lu.');
    }

    public function test_a_disabled_download_is_diagnosed_distinctly_from_a_real_download_failure(): void
    {
        config(['video.sources.yt_dlp.enabled' => false, 'video.sources.providers' => (require config_path('video.php'))['sources']['providers']]);
        $project = $this->create(rights: true);
        $disabled = $this->acquire($project);
        $this->assertSame([VideoSourceStatus::Unavailable, 'VIDEO_DOWNLOAD_DISABLED'], [$disabled->status, $disabled->error_code]);
        $this->assertStringContainsString('n\'est pas activée sur ce serveur', $disabled->error_message);
        $this->assertSame([], $this->calls(), 'Voie désactivée : yt-dlp jamais lancé.');

        // Activation après coup : diagnostic recalculé à l'affichage, recherche relancée par la commande planifiée.
        config(['video.sources.yt_dlp.enabled' => true]);
        $this->api()->getJson("/api/v1/clipper/projects/{$project->id}")
            ->assertJsonPath('data.source_status.download_available', true)
            ->assertJsonPath('data.source_status.error_message', fn ($m) => str_contains($m, 'désormais disponible'));
        $this->artisan('clipper:retry-unavailable-sources')->assertSuccessful();
        $this->assertSame(VideoSourceStatus::Pending, $project->videoSource()->firstOrFail()->status);

        // Échec RÉEL de téléchargement : code propre, distinct de la désactivation.
        $this->scenario(['meta_error' => 'ERROR: [youtube] dQw4w9WgXcQ: Sign in to confirm you’re not a bot']);
        $blocked = $this->acquire($project);
        $this->assertSame([VideoSourceStatus::Failed, 'BLOCKED_BY_YOUTUBE', AcquisitionMethod::YtDlp], [$blocked->status, $blocked->error_code, $blocked->acquisition_method]);
        $this->assertNotSame($disabled->error_message, $blocked->error_message);
    }

    public function test_an_owned_video_without_google_consent_falls_back_to_ytdlp(): void
    {
        config([
            'video.sources.providers' => (require config_path('video.php'))['sources']['providers'],
            'video.sources.data_portability.enabled' => true,
            'video.sources.data_portability.client_id' => 'dp-client.apps.googleusercontent.com',
            'video.sources.data_portability.client_secret' => 'dp-secret',
            'video.sources.data_portability.redirect' => 'http://localhost:5173/oauth/data-portability/callback',
        ]);
        $this->scenario(['download' => 'empty']);
        $project = $this->create(rights: true);
        $project->videoSource->forceFill(['ownership_status' => SourceOwnership::Owned, 'ownership_checked_at' => now()])->save();

        $source = $this->acquire($project);
        $this->assertCount(2, $this->calls(), 'Export Google inutilisable (aucun consentement) : yt-dlp essayé ensuite.');
        $this->assertSame([AcquisitionMethod::YtDlp, 'DOWNLOAD_INCOMPLETE'], [$source->acquisition_method, $source->error_code]);

        // Sans attestation : les deux voies sont inutilisables, la raison de la première (export Google) est gardée.
        $this->scenario(['download' => 'empty']);
        $other = $this->create();
        $other->videoSource->forceFill(['ownership_status' => SourceOwnership::Owned, 'ownership_checked_at' => now()])->save();
        $this->assertSame('CONSENT_REQUIRED', $this->acquire($other)->error_code);
        $this->assertSame([], $this->calls());
    }

    // --- Refus avant tout téléchargement ---------------------------------------------------------

    public function test_private_age_restricted_live_and_too_long_videos_are_refused_before_downloading(): void
    {
        $cases = [
            'VIDEO_NOT_PUBLIC' => ['availability' => 'private'],
            'AGE_RESTRICTED' => ['age_limit' => 18],
            'LIVE_NOT_SUPPORTED' => ['live_status' => 'is_live'],
            'SOURCE_TOO_LONG' => ['duration' => 3 * 3600],
        ];
        foreach ($cases as $code => $meta) {
            $this->scenario(['meta' => $meta, 'download' => 'copy']);
            $source = $this->acquire($this->create(rights: true));
            $this->assertSame($code, $source->error_code, $code);
            $this->assertNotSame(VideoSourceStatus::Ready, $source->status);
            $this->assertCount(1, $this->calls(), "{$code} : seules les métadonnées sont lues.");
        }
    }

    public function test_the_media_library_limit_bounds_the_download_and_a_too_heavy_video_is_refused_before_downloading(): void
    {
        // Médiathèque limitée à 100 Mo (plus strict que la limite du Clipper) : limite transmise à yt-dlp.
        config(['media.max_size_mb.video' => 100]);
        $limit = (string) (100 * 1024 * 1024);
        $this->scenario(['meta' => ['requested_formats' => [['filesize' => 150 * 1024 * 1024], ['filesize_approx' => 12 * 1024 * 1024]]], 'download' => 'copy']);
        $source = $this->acquire($this->create(rights: true));

        $this->assertSame([VideoSourceStatus::Failed, 'SOURCE_TOO_LARGE'], [$source->status, $source->error_code]);
        $this->assertStringContainsString('100 Mo', $source->error_message);
        $this->assertCount(1, $this->calls(), 'Taille annoncée trop grande : aucun téléchargement.');
        $format = $this->calls()[0][array_search('-f', $this->calls()[0], true) + 1];
        $this->assertStringContainsString('<'.(int) floor($limit * 0.85).']', $format, 'Format choisi dans la limite (part audio réservée).');

        // Format admissible : téléchargement borné par la même limite.
        $this->scenario(['meta' => ['requested_formats' => [['filesize' => 60 * 1024 * 1024], ['filesize' => 11 * 1024 * 1024]]], 'download' => 'empty']);
        $this->acquire($this->create(rights: true));
        $download = $this->calls()[1];
        $this->assertSame($limit, $download[array_search('--max-filesize', $download, true) + 1]);
    }

    public function test_youtube_errors_become_precise_codes_without_leaking_raw_output(): void
    {
        $cases = [
            'ERROR: [youtube] dQw4w9WgXcQ: Private video. Sign in if you\'ve been granted access' => [VideoSourceStatus::Unavailable, 'VIDEO_NOT_PUBLIC'],
            'ERROR: [youtube] dQw4w9WgXcQ: Sign in to confirm you’re not a bot. C:\\secret\\path' => [VideoSourceStatus::Failed, 'BLOCKED_BY_YOUTUBE'],
            'ERROR: [youtube] dQw4w9WgXcQ: Join this channel to get access to members-only content' => [VideoSourceStatus::Unavailable, 'VIDEO_NOT_PUBLIC'],
            'ERROR: [youtube] dQw4w9WgXcQ: Video unavailable. This video has been removed' => [VideoSourceStatus::Unavailable, 'VIDEO_UNAVAILABLE'],
            'ERROR: [youtube] dQw4w9WgXcQ: The uploader has not made this video available in your country' => [VideoSourceStatus::Unavailable, 'GEO_RESTRICTED'],
            'ERROR: unable to download video data: HTTP Error 429: Too Many Requests' => [VideoSourceStatus::WaitingForProvider, 'RATE_LIMITED'],
            // Refus réel constaté le 2026-10-12 (données vidéo) : refus de YouTube, temporaire, jamais « échec générique ».
            'ERROR: unable to download video data: HTTP Error 403: Forbidden' => [VideoSourceStatus::WaitingForProvider, 'BLOCKED_BY_YOUTUBE'],
            'ERROR: something unexpected' => [VideoSourceStatus::WaitingForProvider, 'DOWNLOAD_FAILED'],
        ];
        foreach ($cases as $stderr => [$status, $code]) {
            $this->scenario(['meta_error' => $stderr]);
            $source = $this->acquire($this->create(rights: true));
            $this->assertSame([$status, $code], [$source->status, $source->error_code], $stderr);
            $this->assertStringNotContainsString('ERROR', (string) $source->error_message);
            $this->assertStringNotContainsString('secret', (string) $source->error_message);
        }
    }

    // --- Fichier obtenu --------------------------------------------------------------------------

    public function test_an_empty_an_invalid_or_a_too_large_file_is_never_accepted(): void
    {
        $this->scenario(['download' => 'empty']);
        $source = $this->acquire($this->create(rights: true));
        $this->assertSame('DOWNLOAD_INCOMPLETE', $source->error_code);

        $this->scenario(['download' => 'outside']);
        $source = $this->acquire($this->create(rights: true));
        $this->assertSame('DOWNLOAD_INCOMPLETE', $source->error_code, 'Chemin hors du dossier de la tâche : refusé.');

        $this->scenario(['download' => 'invalid']);
        $source = $this->acquire($this->create(rights: true));
        $this->assertSame([VideoSourceStatus::Failed, 'SOURCE_REJECTED'], [$source->status, $source->error_code], (string) $source->error_message);

        config(['video.sources.max_bytes' => 1_000]);
        $this->scenario(['download' => 'large']);
        $project = $this->create(rights: true);
        $source = $this->acquire($project);
        $this->assertSame([VideoSourceStatus::Failed, 'SOURCE_TOO_LARGE'], [$source->status, $source->error_code]);
        $this->assertSame('1000', $this->calls()[1][array_search('--max-filesize', $this->calls()[1], true) + 1]);
        $this->assertNull($project->refresh()->source_media_id);
        $this->assertSame([], glob($this->temp.'/{ytdlp,acquired}-*', GLOB_BRACE) ?: [], 'Aucun reste temporaire.');
    }

    // --- Délai, annulation, installation ---------------------------------------------------------

    public function test_a_stuck_download_is_stopped_at_the_timeout(): void
    {
        config(['video.sources.yt_dlp.timeout' => 5]);
        $this->scenario(['download' => 'slow']);
        $started = microtime(true);
        $source = $this->acquire($this->create(rights: true));

        $this->assertLessThan(60, microtime(true) - $started, 'Processus arrêté au délai, bien avant la fin du faux téléchargement (120 s).');
        $this->assertSame([VideoSourceStatus::WaitingForProvider, 'DOWNLOAD_TIMEOUT'], [$source->status, $source->error_code]);
        $this->assertSame([], glob($this->temp.'/ytdlp-*') ?: []);
    }

    public function test_cancelling_the_project_stops_the_running_download(): void
    {
        $this->scenario(['download' => 'slow']);
        $project = $this->create(rights: true);
        $source = $project->videoSource()->firstOrFail();
        $project->forceFill(['status' => 'cancelled'])->save();

        $started = microtime(true);
        $step = app(YtDlpVideoSourceProvider::class)->begin($source);

        $this->assertLessThan(60, microtime(true) - $started, 'Processus arrêté sans attendre la fin (120 s).');
        $this->assertSame('CANCELLED', $step->errorCode);
        $this->assertSame([], glob($this->temp.'/ytdlp-*') ?: []);
    }

    public function test_a_missing_or_disabled_executable_is_reported_and_never_used(): void
    {
        $this->artisan('clipper:ytdlp-check')->assertSuccessful();

        config(['video.sources.yt_dlp.binary' => $this->fake.'/absent.exe']);
        $this->artisan('clipper:ytdlp-check')->assertFailed();
        $source = $this->acquire($this->create(rights: true));
        $this->assertSame([VideoSourceStatus::Unavailable, 'YTDLP_UNAVAILABLE'], [$source->status, $source->error_code], 'Exécutable absent : diagnostic distinct d\'un échec de téléchargement.');

        config(['video.sources.yt_dlp.binary' => $this->installFake(), 'video.sources.yt_dlp.enabled' => false]);
        $this->assertFalse(app(YtDlpVideoSourceProvider::class)->isConfigured());
        $this->api()->getJson('/api/v1/clipper/projects/'.$this->create()->id)->assertJsonPath('data.source_status.download_available', false);
    }

    public function test_leftovers_of_an_interrupted_download_are_purged(): void
    {
        $dir = $this->temp.'/ytdlp-'.'01a130e0-0000-7000-8000-000000000001';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/source.mp4.part', 'partiel');
        touch($dir, now()->subDays(2)->getTimestamp());

        $this->artisan('video:purge-temp')->assertSuccessful();
        $this->assertDirectoryDoesNotExist($dir);
    }

    // --- Outils ----------------------------------------------------------------------------------

    private function api(?User $user = null): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user ?? $this->user)->fromSpa();
    }

    private function create(bool $rights = false): VideoProject
    {
        $id = $this->api()->postJson('/api/v1/clipper/projects', ['youtube_url' => self::URL, 'aspect_ratio' => '9:16', 'rights_confirmed' => $rights])->assertCreated()->json('data.id');

        return VideoProject::findOrFail($id);
    }

    private function acquire(VideoProject $project): VideoSource
    {
        app(VideoSourceService::class)->acquire((string) $project->videoSource()->value('id'));

        return $project->videoSource()->firstOrFail();
    }

    /** @param array<string, mixed> $scenario comportement du faux yt-dlp (métadonnées, erreur, fichier) */
    private function scenario(array $scenario): void
    {
        File::put($this->fake.'/scenario.json', json_encode($scenario));
        File::delete($this->fake.'/calls.jsonl');
    }

    /** @return list<list<string>> arguments reçus par le faux yt-dlp (hors --version) */
    private function calls(): array
    {
        $path = $this->fake.'/calls.jsonl';
        $lines = is_file($path) ? array_filter(explode("\n", (string) File::get($path))) : [];

        return array_values(array_filter(array_map(fn ($l) => json_decode($l, true), $lines), fn ($args) => ! in_array('--version', $args, true)));
    }

    /** Faux yt-dlp : script PHP lancé par un exécutable de commandes (.cmd sous Windows, sh ailleurs). */
    private function installFake(): string
    {
        File::ensureDirectoryExists($this->fake);
        $script = <<<'PHP'
            <?php
            $dir = __DIR__;
            $args = array_slice($argv, 1);
            file_put_contents($dir.'/calls.jsonl', json_encode($args)."\n", FILE_APPEND);
            file_put_contents($dir.'/env.json', json_encode(['TEMP' => getenv('TEMP'), 'TMP' => getenv('TMP'), 'XDG_CACHE_HOME' => getenv('XDG_CACHE_HOME')]));
            $s = json_decode((string) file_get_contents($dir.'/scenario.json'), true) ?: [];
            if (in_array('--version', $args, true)) {
                if (($s['version'] ?? 'ok') === 'slow') { sleep(15); }
                if (($s['version'] ?? 'ok') === 'broken') { fwrite(STDERR, "[PYI-1:ERROR] Could not create temporary directory!\n"); exit(127); }
                echo "2026.08.19\n"; exit(0);
            }
            if (isset($s['meta_error'])) { fwrite(STDERR, $s['meta_error']."\n"); exit(1); }
            if (in_array('-J', $args, true)) {
                echo json_encode(array_merge(['id' => 'dQw4w9WgXcQ', 'availability' => 'public', 'age_limit' => 0, 'live_status' => 'not_live', 'duration' => 6, 'license' => 'Creative Commons Attribution license (reuse allowed)'], $s['meta'] ?? []));
                exit(0);
            }
            $out = str_replace('%(ext)s', 'mp4', $args[array_search('-o', $args, true) + 1]);
            switch ($s['download'] ?? 'empty') {
                case 'slow': sleep(120); break;
                case 'copy': copy($s['file'], $out); break;
                case 'invalid': file_put_contents($out, str_repeat('pas une vidéo ', 200)); break;
                case 'large': file_put_contents($out, str_repeat('x', 5000)); break;
                case 'outside': file_put_contents($dir.'/outside.mp4', 'x'); $out = $dir.'/outside.mp4'; break;
                default: file_put_contents($out, '');
            }
            echo $out."\n";
            PHP;
        File::put($this->fake.'/fake-ytdlp.php', $script);
        if (PHP_OS_FAMILY === 'Windows') {
            File::put($this->fake.'/yt-dlp.cmd', '@"'.PHP_BINARY.'" "'.$this->fake.DIRECTORY_SEPARATOR."fake-ytdlp.php\" %*\r\n");

            return $this->fake.DIRECTORY_SEPARATOR.'yt-dlp.cmd';
        }
        File::put($this->fake.'/yt-dlp', "#!/bin/sh\nexec \"".PHP_BINARY.'" "'.$this->fake."/fake-ytdlp.php\" \"\$@\"\n");
        chmod($this->fake.'/yt-dlp', 0755);

        return $this->fake.'/yt-dlp';
    }

    private function mp4(string $target, int $seconds): void
    {
        $binaries = app(FfmpegBinary::class);
        if (! $binaries->isAvailable() || ! $binaries->isProbeAvailable()) {
            $this->markTestSkipped('FFmpeg/FFprobe introuvable : test réel ignoré.');
        }
        (new Process([config('video.binaries.ffmpeg'), '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', "testsrc2=s=640x360:r=25:d={$seconds}", '-f', 'lavfi', '-i', "sine=f=440:d={$seconds}",
            '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', $target]))->setTimeout(300)->mustRun();
    }
}
