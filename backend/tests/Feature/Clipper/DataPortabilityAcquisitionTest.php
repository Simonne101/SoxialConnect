<?php

namespace Tests\Feature\Clipper;

use App\Domain\Social\Enums\TokenKind;
use App\Domain\Social\OAuth\OAuthTokens;
use App\Domain\Video\Enums\AcquisitionMethod;
use App\Domain\Video\Enums\ClipStatus;
use App\Domain\Video\Enums\SourceOwnership;
use App\Domain\Video\Enums\VideoSourceStatus;
use App\Domain\Video\Rendering\FfmpegBinary;
use App\Domain\Video\Rendering\MediaProbe;
use App\Domain\Video\Sources\AcquisitionStep;
use App\Domain\Video\Sources\DataPortability\DataPortabilityArchiveExtractor;
use App\Domain\Video\Sources\DataPortability\DataPortabilityConsents;
use App\Domain\Video\Sources\VideoSourceService;
use App\Jobs\AcquireVideoSourceJob;
use App\Jobs\AnalyzeClipSourceJob;
use App\Jobs\RenderClipJob;
use App\Models\Media;
use App\Models\SocialAccount;
use App\Models\SocialToken;
use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Feature\Social\Concerns\YouTubeFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * Acquisition depuis le lien par l'export officiel Google (Data Portability, ADR-054).
 *
 * SIMULÉ : les réponses HTTP de Google (OAuth, Data Portability, URL signées Cloud Storage, YouTube
 * videos.list, oEmbed) — aucune requête réelle. RÉEL : archive ZIP, extraction, vidéo MP4 produite par
 * FFmpeg, import dans la médiathèque, découpe FFmpeg, FFprobe. Le parcours réel avec Google exige un
 * client OAuth autorisé pour l'API Data Portability et un compte de l'EEE / Suisse / Royaume-Uni.
 */
class DataPortabilityAcquisitionTest extends TestCase
{
    use RefreshDatabase, YouTubeFixtures;

    private const URL = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

    private const VIDEO_ID = 'dQw4w9WgXcQ';

    private const DP = 'https://dataportability.googleapis.com';

    private const ARCHIVE_URL = 'https://storage.googleapis.com/dataportability-archive/part-1.zip?X-Goog-Signature=abc';

    private const DP_TOKEN = 'ya29.portability-token-ne-doit-jamais-sortir';

    private User $user;

    private SocialAccount $account;

    private string $temp;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('media');
        Http::preventStrayRequests();
        $this->temp = storage_path('framework/testing/portability');
        config([
            'video.temp_path' => $this->temp,
            'media.process_async' => false,
            'video.sources.data_portability.enabled' => true,
            'video.sources.data_portability.client_id' => 'dp-client.apps.googleusercontent.com',
            'video.sources.data_portability.client_secret' => 'dp-secret',
            'video.sources.data_portability.redirect' => 'http://localhost:5173/oauth/data-portability/callback',
            'video.sources.data_portability.resources' => ['youtube.public_videos', 'youtube.unlisted_videos'],
            'video.sources.data_portability.poll_seconds' => 60,
        ]);
        $this->user = User::factory()->create();
        $this->account = $this->youtubeAccount($this->user);
        File::ensureDirectoryExists($this->temp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temp ?? '');
        parent::tearDown();
    }

    private function api(?User $user = null): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user ?? $this->user)->fromSpa();
    }

    /** @param array<string, mixed> $extra faux HTTP supplémentaires (prioritaires) */
    private function fakeGoogle(array $extra = [], string $channel = 'UC_test_channel'): void
    {
        Http::fake([
            ...$extra,
            'www.youtube.com/oembed*' => Http::response(['title' => 'Conférence IA : les shorts', 'author_name' => 'Chaîne de test', 'thumbnail_url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg']),
            'www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => [['snippet' => ['channelId' => $channel]]]]),
        ]);
    }

    private function create(): VideoProject
    {
        $id = $this->api()->postJson('/api/v1/clipper/projects', ['youtube_url' => self::URL, 'aspect_ratio' => '9:16'])->assertCreated()->json('data.id');

        return VideoProject::findOrFail($id);
    }

    private function acquire(VideoProject $project): VideoSource
    {
        app(VideoSourceService::class)->acquire((string) $project->videoSource()->value('id'));

        return $project->videoSource()->firstOrFail();
    }

    private function consent(array $scopes = ['youtube.public_videos', 'youtube.unlisted_videos'], ?CarbonImmutable $expiresAt = null, ?string $refresh = 'dp-refresh'): void
    {
        app(DataPortabilityConsents::class)->store($this->account, new OAuthTokens(
            accessToken: self::DP_TOKEN,
            refreshToken: $refresh,
            expiresAt: $expiresAt ?? CarbonImmutable::now()->addHour(),
            scopes: array_map(fn ($s) => 'https://www.googleapis.com/auth/dataportability.'.$s, $scopes),
        ));
    }

    /** @param array<string, string> $entries nom dans l'archive => contenu (ou chemin d'un fichier réel préfixé « @ ») */
    private function zip(array $entries): string
    {
        $path = $this->temp.'/archive-'.uniqid().'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            str_starts_with($content, '@') ? $zip->addFile(substr($content, 1), $name) : $zip->addFromString($name, $content);
        }
        $zip->close();

        return (string) file_get_contents($path);
    }

    private function mp4(string $target, int $seconds = 8): void
    {
        $binaries = app(FfmpegBinary::class);
        if (! $binaries->isAvailable() || ! $binaries->isProbeAvailable()) {
            $this->markTestSkipped('FFmpeg/FFprobe introuvable : test réel ignoré.');
        }
        File::ensureDirectoryExists(dirname($target));
        (new Process([config('video.binaries.ffmpeg'), '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', "testsrc2=s=640x360:r=25:d={$seconds}", '-f', 'lavfi', '-i', "sine=f=440:d={$seconds}",
            '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', $target]))->setTimeout(300)->mustRun();
    }

    private function sourceFor(string $title = 'Conférence IA : les shorts'): VideoSource
    {
        $project = VideoProject::factory()->for($this->user)->clipping()->create(['title' => $title]);
        $source = new VideoSource;
        $source->forceFill(['user_id' => $this->user->id, 'video_project_id' => $project->id, 'provider' => 'youtube', 'external_reference' => self::VIDEO_ID,
            'source_type' => 'youtube_video', 'ownership_status' => SourceOwnership::Owned, 'ownership_account_id' => $this->account->id, 'status' => 'waiting_for_provider'])->save();

        return $source;
    }

    private function extract(VideoSource $source, string $zipBytes, ?string $url = null): AcquisitionStep
    {
        Http::swap(new Factory); // un faux par extraction (le corps d'une réponse simulée ne se lit qu'une fois)
        Http::preventStrayRequests();
        Http::fake(['storage.googleapis.com/*' => Http::response($zipBytes, 200, ['Content-Type' => 'application/zip'])]);

        return app(DataPortabilityArchiveExtractor::class)->extract($source, [$url ?? self::ARCHIVE_URL]);
    }

    // --- Consentement -------------------------------------------------------------------------

    public function test_consent_is_offered_only_for_an_owned_video_and_targets_the_official_google_screen(): void
    {
        $this->fakeGoogle();
        $project = $this->create();

        // Propriété pas encore vérifiée : 409 explicite.
        $this->api()->postJson("/api/v1/clipper/projects/{$project->id}/source/consent")->assertStatus(409);
        $source = $this->acquire($project); // propriété vérifiée (chaîne connectée) → consentement requis
        $this->assertSame(SourceOwnership::Owned, $source->ownership_status);
        $this->assertSame(VideoSourceStatus::Unavailable, $source->status);
        $this->assertSame('CONSENT_REQUIRED', $source->error_code);
        $this->assertStringContainsString('Autorisez l\'export officiel Google', $source->error_message);

        $url = $this->api()->postJson("/api/v1/clipper/projects/{$project->id}/source/consent")->assertOk()->json('data.authorization_url');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('https://www.googleapis.com/auth/dataportability.youtube.public_videos https://www.googleapis.com/auth/dataportability.youtube.unlisted_videos', $q['scope']);
        $this->assertSame(['S256', 'offline', 'http://localhost:5173/oauth/data-portability/callback', 64], [$q['code_challenge_method'], $q['access_type'], $q['redirect_uri'], strlen($q['state'])]);
        $this->assertStringNotContainsString('dp-secret', $url);

        $this->api(User::factory()->create())->postJson("/api/v1/clipper/projects/{$project->id}/source/consent")->assertNotFound();
        config(['video.sources.data_portability.enabled' => false]);
        $this->api()->postJson("/api/v1/clipper/projects/{$project->id}/source/consent")->assertStatus(409);
    }

    public function test_the_callback_stores_an_encrypted_consent_and_resumes_the_acquisition(): void
    {
        $this->fakeGoogle(['oauth2.googleapis.com/token' => Http::response(['access_token' => self::DP_TOKEN, 'refresh_token' => 'dp-refresh', 'expires_in' => 3599, 'token_type' => 'Bearer',
            'scope' => 'https://www.googleapis.com/auth/dataportability.youtube.public_videos'])]);
        $project = $this->create();
        $this->acquire($project);
        $state = $this->api()->postJson("/api/v1/clipper/projects/{$project->id}/source/consent")->json('data.authorization_url');
        parse_str((string) parse_url($state, PHP_URL_QUERY), $q);
        Queue::fake();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->user, 'web')->get('/oauth/data-portability/callback?'.http_build_query(['state' => $q['state'], 'code' => '4/code']))
            ->assertRedirect("http://localhost:5173/studio/clipper/{$project->id}?portability=connected");

        $token = SocialToken::query()->where('social_account_id', $this->account->id)->where('token_kind', TokenKind::Portability->value)->firstOrFail();
        $this->assertSame(self::DP_TOKEN, $token->access_token_encrypted);
        $raw = DB::table('social_tokens')->where('id', $token->id)->value('access_token_encrypted');
        $this->assertStringNotContainsString(self::DP_TOKEN, (string) $raw, 'Jeton chiffré en base.');
        $this->assertSame(VideoSourceStatus::Pending, $project->videoSource()->first()->status);
        Queue::assertPushed(AcquireVideoSourceJob::class, 1);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://oauth2.googleapis.com/token' && $r['grant_type'] === 'authorization_code' && $r['client_id'] === 'dp-client.apps.googleusercontent.com' && filled($r['code_verifier']));

        // State à usage unique : rejouer le retour est refusé.
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->user, 'web')->get('/oauth/data-portability/callback?'.http_build_query(['state' => $q['state'], 'code' => '4/code']))
            ->assertRedirect('http://localhost:5173/studio/clipper?portability=error&code=INVALID_STATE');
    }

    public function test_the_callback_rejects_another_user_a_refusal_and_a_consent_without_video_scopes(): void
    {
        $this->fakeGoogle([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'x', 'expires_in' => 3599, 'scope' => 'openid']),
            'oauth2.googleapis.com/revoke' => Http::response('', 200),
        ]);
        $project = $this->create();
        $this->acquire($project);
        $issue = function (): string {
            $url = $this->api()->postJson('/api/v1/clipper/projects/'.VideoProject::query()->value('id').'/source/consent')->json('data.authorization_url');
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

            return $q['state'];
        };
        $callback = function (array $query, ?User $as = null) {
            $this->app['auth']->forgetGuards();

            return $this->actingAs($as ?? $this->user, 'web')->get('/oauth/data-portability/callback?'.http_build_query($query));
        };

        $callback(['state' => $issue(), 'code' => 'c'], User::factory()->create())->assertRedirect('http://localhost:5173/studio/clipper?portability=error&code=INVALID_STATE');
        $callback(['state' => $issue(), 'error' => 'access_denied'])->assertRedirect("http://localhost:5173/studio/clipper/{$project->id}?portability=error&code=ACCESS_DENIED");
        $callback(['state' => $issue(), 'code' => 'c'])->assertRedirect("http://localhost:5173/studio/clipper/{$project->id}?portability=error&code=SCOPE_DENIED");
        Http::assertSent(fn (Request $r) => $r->url() === 'https://oauth2.googleapis.com/revoke');
        $this->assertSame(0, SocialToken::query()->where('token_kind', TokenKind::Portability->value)->count());
    }

    public function test_an_expiring_consent_is_refreshed_and_a_revoked_one_asks_for_a_new_consent(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::sequence()
            ->push(['access_token' => 'ya29.renouvele', 'expires_in' => 3599])
            ->push(['error' => 'invalid_grant'], 400)]);
        $this->consent(expiresAt: CarbonImmutable::now()->subMinute());
        $consents = app(DataPortabilityConsents::class);

        $this->assertSame('ya29.renouvele', $consents->accessTokenFor($this->user, $this->account->id));
        $this->assertSame('ya29.renouvele', $consents->accessTokenFor($this->user, $this->account->id), 'Réutilisé tant qu\'il est valide.');
        SocialToken::query()->where('token_kind', 'portability')->update(['expires_at' => now()->subMinute()]);
        $this->assertNull($consents->accessTokenFor($this->user, $this->account->id), 'Refusé par Google : nouveau consentement.');
        $this->assertSame('invalid', DB::table('social_tokens')->where('token_kind', 'portability')->value('status'));
        $this->assertNull($consents->accessTokenFor(User::factory()->create()), 'Jamais le consentement d\'un autre utilisateur.');
    }

    // --- Extraction de l'archive ----------------------------------------------------------------

    public function test_the_requested_video_is_found_by_id_by_metadata_title_by_title_or_as_the_only_video(): void
    {
        $other = 'YouTube et YouTube Music/vidéos/Autre vidéo de la chaîne.mp4';
        $cases = [
            'video_id' => [['Takeout/YouTube/videos/Conf-dQw4w9WgXcQ.mp4' => 'VIDEO-A', $other => 'VIDEO-B'], 'VIDEO-A'],
            'metadata_title' => [[
                'YouTube et YouTube Music/métadonnées des vidéos/vidéos.csv' => "Video ID,Approx Duration (ms),Video Title (Original)\ndQw4w9WgXcQ,8000,Ma conférence : l'IA et les Shorts\nautreId1234,5000,Autre vidéo de la chaîne\n",
                "YouTube et YouTube Music/vidéos/Ma conférence _ l'IA et les Shorts.mp4" => 'VIDEO-A', $other => 'VIDEO-B'], 'VIDEO-A'],
            'title' => [['vidéos/Conférence IA _ les shorts.mov' => 'VIDEO-A', $other => 'VIDEO-B'], 'VIDEO-A'],
            'single_video' => [['vidéos/clip.mp4' => 'VIDEO-A', 'vidéos/notes.txt' => 'texte'], 'VIDEO-A'],
        ];
        foreach ($cases as $strategy => [$entries, $expected]) {
            $step = $this->extract($this->sourceFor(), $this->zip($entries));
            $this->assertSame(AcquisitionStep::ACQUIRED, $step->kind, "{$strategy} : ".$step->errorCode);
            $this->assertSame($expected, file_get_contents((string) $step->localPath), $strategy);
            $this->assertStringStartsWith($this->temp, (string) $step->localPath, 'Fichier extrait dans le dossier temporaire, jamais au chemin de l\'archive.');
            @unlink((string) $step->localPath);
        }
        $this->assertSame([], glob($this->temp.'/portability-*') ?: [], 'Archives téléchargées supprimées.');
    }

    public function test_an_absent_or_ambiguous_video_is_reported_never_guessed(): void
    {
        $step = $this->extract($this->sourceFor(), $this->zip(['vidéos/Une vidéo.mp4' => 'A', 'vidéos/Une autre.mp4' => 'B']));
        $this->assertSame(['failed', 'ARCHIVE_VIDEO_AMBIGUOUS', null], [$step->kind, $step->errorCode, $step->localPath]);

        $step = $this->extract($this->sourceFor(), $this->zip(['vidéos/notes.txt' => 'pas de vidéo']));
        $this->assertSame('ARCHIVE_VIDEO_NOT_FOUND', $step->errorCode);

        $step = $this->extract($this->sourceFor(), 'ceci n\'est pas un zip');
        $this->assertSame('ARCHIVE_INVALID', $step->errorCode);

        // Lien hors Cloud Storage : jamais téléchargé.
        $step = app(DataPortabilityArchiveExtractor::class)->extract($this->sourceFor(), ['https://evil.test/archive.zip']);
        $this->assertSame('ARCHIVE_EMPTY', $step->errorCode);

        // Lien signé expiré, archive trop volumineuse.
        Http::swap(new Factory);
        Http::fake(['storage.googleapis.com/*' => Http::response('', 403)]);
        $this->assertSame('ARCHIVE_EXPIRED', app(DataPortabilityArchiveExtractor::class)->extract($this->sourceFor(), [self::ARCHIVE_URL])->errorCode);
        config(['video.sources.data_portability.archive_max_bytes' => 10]);
        $this->assertSame('ARCHIVE_TOO_LARGE', $this->extract($this->sourceFor(), $this->zip(['v.mp4' => str_repeat('x', 500)]))->errorCode);
        $this->assertSame([], glob($this->temp.'/{portability,acquired}-*', GLOB_BRACE) ?: [], 'Aucun fichier temporaire laissé.');
    }

    public function test_an_interrupted_acquisition_job_never_leaves_the_source_waiting(): void
    {
        $source = $this->sourceFor();
        (new AcquireVideoSourceJob($source->id))->failed(new \RuntimeException('timeout'));

        $source->refresh();
        $this->assertSame(VideoSourceStatus::Failed, $source->status);
        $this->assertSame('ACQUISITION_ABORTED', $source->error_code);
    }

    // --- Suivi de l'export (ADR-056) ------------------------------------------------------------

    /** Source dont la propriété est vérifiée et le consentement accordé, prête pour l'export. */
    private function ownedProject(): VideoProject
    {
        $project = $this->create();
        $project->forceFill(['status' => 'ready'])->save();
        $this->consent();

        return $project;
    }

    public function test_an_already_used_one_time_consent_is_reset_and_asks_for_a_new_consent(): void
    {
        $this->fakeGoogle([
            self::DP.'/v1/portabilityArchive:initiate' => Http::response(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'Requested resources have already been exported. Please call ResetAuthorization and re-obtain consent before trying again.']], 429),
            self::DP.'/v1/authorization:reset' => Http::response([]),
        ]);
        $project = $this->ownedProject();

        $source = $this->acquire($project);

        $this->assertSame(VideoSourceStatus::Unavailable, $source->status, 'Jamais une attente sans fin.');
        $this->assertSame('CONSENT_REQUIRED', $source->error_code);
        $this->assertStringContainsString('déjà servi', $source->error_message);
        Http::assertSent(fn (Request $r) => $r->url() === self::DP.'/v1/authorization:reset' && $r->hasHeader('Authorization', 'Bearer '.self::DP_TOKEN));
        $this->assertSame('expired', DB::table('social_tokens')->where('token_kind', 'portability')->value('status'), 'Jeton consommé : nouveau consentement.');
        Queue::assertNotPushed(AcquireVideoSourceJob::class, fn ($job) => $job->delay !== null);
    }

    public function test_a_long_export_is_followed_until_the_google_deadline_with_growing_intervals(): void
    {
        config(['video.sources.max_attempts' => 3]);
        $this->fakeGoogle([
            self::DP.'/v1/portabilityArchive:initiate' => Http::response(['archiveJobId' => 'job_long']),
            self::DP.'/v1/archiveJobs/job_long/portabilityArchiveState' => Http::response(['state' => 'IN_PROGRESS']),
            // Jeton d'accès valable 1 h : renouvelé pendant un export long.
            'oauth2.googleapis.com/token' => Http::response(['access_token' => self::DP_TOKEN, 'expires_in' => 3599]),
        ]);
        $project = $this->ownedProject();
        $this->assertSame(VideoSourceStatus::WaitingForProvider, $this->acquire($project)->status);

        // Bien au-delà de max_attempts : l'export Google suit sa propre échéance (7 jours).
        // poll_seconds = 60 (setUp) la première heure, puis 15 min, puis 30 min au-delà d'une journée.
        foreach ([[2, 60], [90, 900], [60 * 30, 1800]] as [$minutes, $interval]) {
            $this->travel($minutes)->minutes();
            $source = $this->acquire($project);
            $this->assertSame(VideoSourceStatus::WaitingForProvider, $source->status, "{$minutes} min");
            $this->assertEqualsWithDelta(now()->addSeconds($interval)->getTimestamp(), $source->next_check_at->getTimestamp(), 2, "intervalle après {$minutes} min");
        }

        $this->travel(7)->days();
        $source = $this->acquire($project);
        $this->assertSame(VideoSourceStatus::Failed, $source->status);
        $this->assertSame('ACQUISITION_TIMEOUT', $source->error_code);
    }

    public function test_a_failed_export_is_retried_once_through_the_official_endpoint(): void
    {
        $this->fakeGoogle([
            self::DP.'/v1/portabilityArchive:initiate' => Http::response(['archiveJobId' => 'job_1']),
            self::DP.'/v1/archiveJobs/job_1/portabilityArchiveState' => Http::response(['state' => 'FAILED']),
            self::DP.'/v1/archiveJobs/job_1:retry' => Http::response(['archiveJobId' => 'job_2']),
            self::DP.'/v1/archiveJobs/job_2/portabilityArchiveState' => Http::response(['state' => 'FAILED']),
        ]);
        $project = $this->ownedProject();
        $this->acquire($project);

        $source = $this->acquire($project);
        $this->assertSame(VideoSourceStatus::WaitingForProvider, $source->status, 'Relance officielle de l\'export.');
        $this->assertSame('job_2', $source->metadata['archive_job_id']);
        Http::assertSent(fn (Request $r) => $r->url() === self::DP.'/v1/archiveJobs/job_1:retry');

        $source = $this->acquire($project);
        $this->assertSame(VideoSourceStatus::Failed, $source->status);
        $this->assertSame('EXPORT_FAILED', $source->error_code);
    }

    public function test_leftovers_of_an_interrupted_acquisition_are_purged_but_never_an_active_one(): void
    {
        $old = $this->sourceFor();
        $old->forceFill(['status' => 'failed'])->save();
        $active = $this->sourceFor();
        File::ensureDirectoryExists($this->temp."/portability-{$old->id}");
        file_put_contents($this->temp."/portability-{$old->id}/part-0.zip", 'zip');
        file_put_contents($this->temp."/acquired-{$old->id}.mp4", 'mp4');
        File::ensureDirectoryExists($this->temp."/portability-{$active->id}");
        file_put_contents($this->temp."/acquired-{$active->id}.mp4", 'mp4');
        file_put_contents($this->temp.'/autre-fichier.txt', 'jamais touché');
        $past = now()->subDays(2)->getTimestamp();
        foreach (glob($this->temp.'/*') as $path) {
            touch($path, $past);
        }

        $this->artisan('video:purge-temp')->assertSuccessful();

        $this->assertDirectoryDoesNotExist($this->temp."/portability-{$old->id}");
        $this->assertFileDoesNotExist($this->temp."/acquired-{$old->id}.mp4");
        $this->assertDirectoryExists($this->temp."/portability-{$active->id}", 'Acquisition en cours : jamais purgée.');
        $this->assertFileExists($this->temp."/acquired-{$active->id}.mp4");
        $this->assertFileExists($this->temp.'/autre-fichier.txt');
    }

    // --- Parcours complet ------------------------------------------------------------------------

    public function test_the_link_alone_leads_to_a_real_vertical_short_in_the_media_library(): void
    {
        $file = $this->temp.'/export/Conférence IA _ les shorts.mp4';
        $this->mp4($file);
        $archive = $this->zip(['YouTube et YouTube Music/vidéos/Conférence IA _ les shorts.mp4' => '@'.$file, 'YouTube et YouTube Music/vidéos/Autre.mp4' => 'autre']);
        $this->fakeGoogle([
            self::DP.'/v1/portabilityArchive:initiate' => Http::response(['archiveJobId' => 'job_42', 'accessType' => 'ACCESS_TYPE_TIME_BASED']),
            self::DP.'/v1/archiveJobs/job_42/portabilityArchiveState' => Http::sequence()
                ->push(['state' => 'IN_PROGRESS'])
                ->push(['state' => 'COMPLETE', 'urls' => [self::ARCHIVE_URL]]),
            'storage.googleapis.com/*' => Http::response($archive, 200, ['Content-Type' => 'application/zip']),
        ]);
        $this->consent();

        // 1. L'utilisateur colle uniquement le lien.
        $project = $this->create();
        $this->assertNull($project->source_media_id);
        $project->forceFill(['status' => 'ready'])->save(); // analyse depuis le lien terminée (file d'attente factice)

        // 2. Récupération : propriété vérifiée → export demandé → suivi → archive → extraction → médiathèque.
        $this->assertSame(VideoSourceStatus::WaitingForProvider, $this->acquire($project)->status);
        Http::assertSent(fn (Request $r) => $r->url() === self::DP.'/v1/portabilityArchive:initiate' && $r['resources'] === ['youtube.public_videos', 'youtube.unlisted_videos'] && $r->hasHeader('Authorization', 'Bearer '.self::DP_TOKEN));
        $this->assertSame(VideoSourceStatus::WaitingForProvider, $this->acquire($project)->status, 'Archive en préparation.');
        $source = $this->acquire($project);
        $this->assertSame(VideoSourceStatus::Resolving, $source->status, (string) $source->error_message);
        $source = $this->acquire($project); // finalisation (médiathèque traitée)
        $imported = Media::find($source->media_id);
        $this->assertSame(VideoSourceStatus::Ready, $source->status, $source->error_message.' / média : '.json_encode([$imported?->status, $imported?->scan_status, $imported?->mime_type, $imported?->duration_ms]));
        $this->assertSame(AcquisitionMethod::DataPortability, $source->acquisition_method);
        $project->refresh();
        $source = Media::findOrFail($project->source_media_id);
        $this->assertSame([$this->user->id, 'video/mp4'], [$source->user_id, $source->mime_type]);
        Queue::assertPushed(AnalyzeClipSourceJob::class, fn ($job) => $job->projectId === $project->id);
        $this->assertSame([], glob($this->temp.'/{portability,acquired}-*', GLOB_BRACE) ?: [], 'Archive et copie temporaire supprimées.');

        // 3. Passage → FFmpeg → FFprobe → médiathèque (analyse simulée terminée : file d'attente factice).
        $project->forceFill(['status' => 'ready', 'duration_ms' => 8_000])->save();
        $clipId = $this->api()->postJson("/api/v1/clipper/projects/{$project->id}/clips", ['start_ms' => 1_000, 'end_ms' => 6_000, 'aspect_ratio' => '9:16'])->assertStatus(202)->json('data.id');
        app()->call([new RenderClipJob($clipId), 'handle']);
        $clip = $project->clips()->findOrFail($clipId);
        $this->assertSame(ClipStatus::Rendered, $clip->status, (string) $clip->error_message);
        $output = Media::findOrFail($clip->output_media_id);
        $probe = app(MediaProbe::class)->probe(Storage::disk('media')->path($output->path));
        $this->assertSame([1080, 1920, 'h264', 'aac'], [$probe->width, $probe->height, $probe->videoCodec, $probe->audioCodec]);
        $this->assertEqualsWithDelta(5_000, $probe->durationMs, 150);
        $this->api()->getJson("/api/v1/clipper/projects/{$project->id}")->assertJsonPath('data.source_status.method', 'data_portability');
    }
}
