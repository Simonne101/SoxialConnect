<?php

namespace Tests\Feature\Clipper;

use App\Domain\Video\Enums\SourceOwnership;
use App\Domain\Video\Sources\AcquisitionStep;
use App\Domain\Video\Sources\DataPortability\DataPortabilityArchiveExtractor;
use App\Domain\Video\Sources\DataPortability\DataPortabilityClient;
use App\Domain\Video\Sources\DataPortability\DataPortabilityConsents;
use App\Domain\Video\Sources\DataPortability\DataPortabilityException;
use App\Domain\Video\Sources\DataPortability\DataPortabilityOAuth;
use App\Domain\Video\Sources\DataPortability\DataPortabilityVideoSourceProvider;
use App\Domain\Video\Sources\VideoSourceProviders;
use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fournisseur Data Portability (ADR-049, ADR-054) : désactivé par défaut, activé avec un client OAuth.
 * Contrat HTTP vérifié contre la référence REST v1 (simulé : aucune requête réelle, aucun export).
 * Consentement et extraction de l'archive : DataPortabilityAcquisitionTest.
 */
class DataPortabilityProviderTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://dataportability.googleapis.com';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        // Indépendant du .env local (le client YouTube sert de client par défaut).
        config(['video.sources.data_portability.client_id' => null, 'video.sources.data_portability.client_secret' => null]);
    }

    private function source(SourceOwnership $ownership = SourceOwnership::Owned, array $metadata = []): VideoSource
    {
        $user = User::factory()->create();
        $project = VideoProject::factory()->for($user)->clipping()->create();
        $source = new VideoSource;
        $source->forceFill([
            'user_id' => $user->id, 'video_project_id' => $project->id, 'provider' => 'youtube', 'external_reference' => 'dQw4w9WgXcQ',
            'source_type' => 'youtube_video', 'ownership_status' => $ownership, 'status' => 'pending', 'metadata' => $metadata,
        ])->save();

        return $source;
    }

    /** Fournisseur avec un consentement fourni par le test (groupe « vidéos publiques » accordé). */
    private function provider(?string $token = 'jeton-utilisateur'): DataPortabilityVideoSourceProvider
    {
        $consents = new class($token) extends DataPortabilityConsents
        {
            public function __construct(private readonly ?string $token) {}

            public function accessTokenFor(User $user, ?string $accountId = null): ?string
            {
                return $this->token;
            }

            public function grantedResources(User $user, ?string $accountId = null): array
            {
                return $this->token !== null ? ['youtube.public_videos'] : [];
            }
        };

        return new DataPortabilityVideoSourceProvider(app(DataPortabilityClient::class), $consents, app(DataPortabilityOAuth::class), app(DataPortabilityArchiveExtractor::class));
    }

    public function test_disabled_by_default_so_no_automatic_source_is_ever_claimed(): void
    {
        $provider = app(DataPortabilityVideoSourceProvider::class);
        $this->assertFalse($provider->isConfigured());
        $this->assertSame([], app(VideoSourceProviders::class)->configured());
        $this->assertNull(app(VideoSourceProviders::class)->for($this->source()));

        config(['video.sources.data_portability.enabled' => true]);
        $this->assertFalse($provider->isConfigured(), 'Identifiants OAuth exigés.');
        config(['video.sources.data_portability.client_id' => 'id', 'video.sources.data_portability.client_secret' => 'secret']);
        $this->assertTrue($provider->isConfigured());
        config(['video.sources.data_portability.resources' => []]);
        $this->assertFalse($provider->isConfigured(), 'Au moins un groupe de vidéos exigé.');

        // Vidéos du propriétaire uniquement.
        $this->assertTrue($provider->supports($this->source(SourceOwnership::Owned)));
        $this->assertFalse($provider->supports($this->source(SourceOwnership::NotOwned)));
        $this->assertFalse($provider->supports($this->source(SourceOwnership::Unverified)));
    }

    public function test_without_user_consent_the_provider_stops_explicitly(): void
    {
        $step = app(DataPortabilityVideoSourceProvider::class)->begin($this->source());

        $this->assertSame(AcquisitionStep::UNAVAILABLE, $step->kind);
        $this->assertSame('CONSENT_REQUIRED', $step->errorCode);
        Http::assertNothingSent();
    }

    public function test_initiate_and_state_follow_the_documented_contract(): void
    {
        Http::fake([
            self::BASE.'/v1/portabilityArchive:initiate' => Http::response(['archiveJobId' => 'job_123', 'accessType' => 'ACCESS_TYPE_ONE_TIME']),
            self::BASE.'/v1/archiveJobs/job_123/portabilityArchiveState' => Http::sequence()
                ->push(['name' => 'archiveJobs/job_123/portabilityArchiveState', 'state' => 'IN_PROGRESS'])
                ->push(['state' => 'COMPLETE', 'urls' => ['https://storage.googleapis.com/archive/part1.zip?X-Goog-Signature=abc', 'https://evil.test/x.zip', 'http://storage.googleapis.com/y.zip']]),
        ]);
        $provider = $this->provider();
        $source = $this->source();

        $step = $provider->begin($source);
        $this->assertSame(AcquisitionStep::WAITING, $step->kind);
        $this->assertSame('job_123', $step->metadata['archive_job_id']);
        $this->assertFalse($step->metadata['retried']);
        // Échéance de l'export : 7 jours (durée maximale documentée par Google).
        $this->assertEqualsWithDelta(7 * 86_400, strtotime($step->metadata['deadline']) - strtotime($step->metadata['initiated_at']), 1);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === self::BASE.'/v1/portabilityArchive:initiate'
            && $r['resources'] === ['youtube.public_videos'] && $r->hasHeader('Authorization', 'Bearer jeton-utilisateur'));

        $source->forceFill(['metadata' => $step->metadata])->save();
        $this->assertSame(AcquisitionStep::WAITING, $provider->poll($source)->kind, 'Archive en préparation.');

        // Archive prête : téléchargée depuis les seules URL signées Cloud Storage, puis exploitée
        // (archive vide ici → échec explicite, jamais un faux succès ; extraction : DataPortabilityAcquisitionTest).
        Http::fake(['storage.googleapis.com/*' => Http::response('', 200)]);
        $done = $provider->poll($source);
        $this->assertSame(AcquisitionStep::FAILED, $done->kind);
        $this->assertSame('ARCHIVE_INVALID', $done->errorCode);
        $this->assertNull($done->localPath);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'evil.test'));
    }

    public function test_only_signed_google_storage_urls_are_kept(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(['state' => 'COMPLETE', 'urls' => ['https://storage.googleapis.com/a.zip', 'https://storage.googleapis.com:8443/b.zip', 'https://user@storage.googleapis.com/c.zip', 'https://storage.googleapis.com.evil.test/d.zip']])]);

        $state = app(DataPortabilityClient::class)->state('jeton', 'job_1');
        $this->assertSame(['https://storage.googleapis.com/a.zip'], $state['urls']);

        $this->expectException(DataPortabilityException::class);
        app(DataPortabilityClient::class)->state('jeton', '../../etc');
    }

    public function test_errors_are_normalized_without_the_provider_message(): void
    {
        $cases = [
            [401, 'CONSENT_REQUIRED', false],
            [403, 'PERMISSION_DENIED', false],
            [429, 'RATE_LIMITED', true],
            [503, 'PROVIDER_UNAVAILABLE', true],
            [400, 'INVALID_REQUEST', false],
        ];
        foreach ($cases as [$status, $code, $transient]) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake([self::BASE.'/*' => Http::response(['error' => ['message' => 'secret interne Google']], $status)]);
            try {
                app(DataPortabilityClient::class)->initiate('jeton', ['youtube.public_videos']);
                $this->fail("{$code} attendu");
            } catch (DataPortabilityException $e) {
                $this->assertSame($code, $e->errorCode);
                $this->assertSame($transient, $e->transient, $code);
                $this->assertStringNotContainsString('secret interne', $e->getMessage());
            }
        }

        // Consentement retiré pendant le suivi : action requise, pas un échec définitif.
        Http::swap(new Factory);
        Http::fake([self::BASE.'/*' => Http::response([], 401)]);
        $step = $this->provider()->poll($this->source(metadata: ['archive_job_id' => 'job_9']));
        $this->assertSame(AcquisitionStep::UNAVAILABLE, $step->kind);
        $this->assertSame('CONSENT_REQUIRED', $step->errorCode);
    }
}
