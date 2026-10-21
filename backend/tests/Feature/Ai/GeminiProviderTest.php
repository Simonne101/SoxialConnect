<?php

namespace Tests\Feature\Ai;

use App\Domain\AI\AiService;
use App\Domain\AI\Data\AiImageRequest;
use App\Domain\AI\Data\AiTextRequest;
use App\Domain\AI\Data\AiTranscriptionRequest;
use App\Domain\AI\Enums\AiCapability;
use App\Domain\AI\Enums\AiTask;
use App\Domain\AI\Exceptions\AiProviderException;
use App\Domain\AI\Providers\GeminiProvider;
use App\Domain\AI\Providers\OpenAiProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Fournisseur Gemini (ADR-048), API Interactions simulée (Http::fake) : aucune requête réelle.
 * Contrat : forme de la requête, lecture de la réponse, erreurs normalisées, secret jamais exposé.
 */
class GeminiProviderTest extends TestCase
{
    private const KEY = 'gemini-cle-fictive-ne-doit-jamais-sortir';

    private const ENDPOINT = 'generativelanguage.googleapis.com/v1beta/interactions';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'ai.providers.gemini.api_key' => self::KEY,
            'ai.providers.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'ai.providers.gemini.models' => ['video_analysis' => 'gemini-analyse-test', 'image' => 'gemini-image-test'],
        ]);
    }

    private function provider(): GeminiProvider
    {
        return app(GeminiProvider::class);
    }

    /** @param list<array<string, mixed>> $content */
    private function interaction(array $content, string $status = 'completed'): array
    {
        return ['id' => 'v1_test', 'status' => $status, 'steps' => [['type' => 'model_output', 'content' => $content]], 'usage' => ['total_input_tokens' => 1200, 'total_output_tokens' => 80, 'total_tokens' => 1280]];
    }

    public function test_text_with_a_youtube_url_and_json_schema_uses_the_interactions_contract(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->interaction([['type' => 'text', 'text' => '{"clips":[]}']]))]);

        $result = $this->provider()->generateText(new AiTextRequest(
            model: 'gemini-analyse-test',
            input: 'Analyse la vidéo.',
            instructions: 'Consignes',
            maxOutputTokens: 900,
            videoUrl: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            jsonSchema: ['type' => 'object'],
        ));

        $this->assertSame('{"clips":[]}', $result->text);
        $this->assertTrue($result->complete);
        $this->assertSame([1200, 80], [$result->inputTokens, $result->outputTokens]);
        $this->assertSame('gemini', $result->provider);
        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://'.self::ENDPOINT
                && $request->hasHeader('x-goog-api-key', self::KEY)
                && ! str_contains($request->url(), self::KEY)
                && $request['model'] === 'gemini-analyse-test'
                && $request['input'][1] === ['type' => 'video', 'uri' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']
                && $request['system_instruction'] === 'Consignes'
                && $request['generation_config']['max_output_tokens'] === 900
                && $request['response_format'] === ['type' => 'text', 'mime_type' => 'application/json', 'schema' => ['type' => 'object']]
                && $request['store'] === false;
        });
    }

    public function test_images_are_requested_in_the_native_aspect_ratio_and_decoded(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->interaction([['type' => 'image', 'mime_type' => 'image/png', 'data' => base64_encode('png-bytes')]]))]);

        $result = $this->provider()->generateImage(new AiImageRequest('gemini-image-test', 'Un visuel', '1024x1536', '4:5'));

        $this->assertSame('png-bytes', $result->bytes);
        Http::assertSent(fn (Request $request) => $request['response_format'] === ['type' => 'image', 'aspect_ratio' => '4:5', 'image_size' => '1K']);
        $this->assertSame('2:3', GeminiProvider::ratioOf('1024x1536'));
        $this->assertSame('3:2', GeminiProvider::ratioOf('1536x1024'));
        $this->assertSame('1:1', GeminiProvider::ratioOf('1024x1024'));
    }

    public function test_errors_are_normalized_without_leaking_the_key_or_the_provider_message(): void
    {
        $cases = [
            [Http::response(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'You exceeded your current quota for '.self::KEY]], 429), 'QUOTA_EXCEEDED', false],
            [Http::response(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'Too many requests']], 429), 'RATE_LIMITED', true],
            // Limite quotidienne du palier gratuit (réponse réelle du 2026-10-07) : quota, pas une limite passagère.
            [Http::response(['error' => ['message' => 'Rate limit exceeded for model gemini-x (limit: 20 requests per day on Free Tier). Please retry in 5h27m42s.', 'code' => 'too_many_requests']], 429), 'QUOTA_EXCEEDED', false],
            [Http::response(['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'API key not valid. '.self::KEY]], 401), 'AUTHENTICATION_FAILED', false],
            [Http::response(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'message' => 'models/x']], 404), 'MODEL_NOT_FOUND', false],
            [Http::response(['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'bad video']], 400), 'INVALID_REQUEST', false],
            [Http::response(['oops' => true], 200), 'INVALID_RESPONSE', false],
            [Http::response($this->interaction([], 'failed')), 'GENERATION_FAILED', false],
            [fn () => throw new ConnectionException('cURL error 28: timed out'), 'TIMEOUT', true],
        ];
        foreach ($cases as [$response, $code, $transient]) {
            Http::swap(new Factory); // faux remplacé à chaque cas (les faux successifs s'ajoutent sinon)
            Http::preventStrayRequests();
            Http::fake([self::ENDPOINT => $response]);
            try {
                $this->provider()->generateText(new AiTextRequest('gemini-analyse-test', 'Bonjour'));
                $this->fail("{$code} attendu");
            } catch (AiProviderException $e) {
                $this->assertSame($code, $e->errorCode);
                $this->assertSame($transient, $e->transient, $code);
                $this->assertStringNotContainsString(self::KEY, $e->getMessage());
                $this->assertStringNotContainsString('exceeded your current quota', $e->getMessage(), 'Message du fournisseur jamais repris.');
            }
        }
    }

    public function test_a_temporarily_overloaded_model_is_retried_but_never_a_quota(): void
    {
        Sleep::fake();
        $overloaded = Http::response(['error' => ['message' => 'gemini is currently experiencing high demand', 'code' => 'service_unavailable']], 503);

        // 503 « high demand » (constaté en réel) : deux nouvelles tentatives espacées, puis succès.
        Http::fake([self::ENDPOINT => Http::sequence()->pushResponse($overloaded)->pushResponse($overloaded)->push($this->interaction([['type' => 'text', 'text' => 'OK']]))]);
        $this->assertSame('OK', $this->provider()->generateText(new AiTextRequest('gemini-analyse-test', 'Bonjour'))->text);
        Http::assertSentCount(3);
        Sleep::assertSequence([Sleep::for(GeminiProvider::OVERLOAD_RETRY_DELAYS_MS[0])->milliseconds(), Sleep::for(GeminiProvider::OVERLOAD_RETRY_DELAYS_MS[1])->milliseconds()]);

        // Surcharge persistante : erreur normalisée et transitoire après trois essais au total.
        Http::swap(new Factory);
        Http::fake([self::ENDPOINT => $overloaded]);
        try {
            $this->provider()->generateText(new AiTextRequest('gemini-analyse-test', 'Bonjour'));
            $this->fail('PROVIDER_UNAVAILABLE attendu');
        } catch (AiProviderException $e) {
            $this->assertSame('PROVIDER_UNAVAILABLE', $e->errorCode);
            $this->assertTrue($e->transient);
            $this->assertStringNotContainsString('high demand', $e->getMessage());
        }
        Http::assertSentCount(3);

        // Quota épuisé : jamais retenté.
        Http::swap(new Factory);
        Http::fake([self::ENDPOINT => Http::response(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'You exceeded your current quota']], 429)]);
        try {
            $this->provider()->generateText(new AiTextRequest('gemini-analyse-test', 'Bonjour'));
            $this->fail('QUOTA_EXCEEDED attendu');
        } catch (AiProviderException $e) {
            $this->assertSame('QUOTA_EXCEEDED', $e->errorCode);
        }
        Http::assertSentCount(1);
    }

    public function test_capabilities_and_unsupported_transcription(): void
    {
        $gemini = $this->provider();
        $this->assertTrue($gemini->supports(AiCapability::Text));
        $this->assertTrue($gemini->supports(AiCapability::VideoUrl));
        $this->assertTrue($gemini->supports(AiCapability::Image));
        $this->assertFalse($gemini->supports(AiCapability::Transcription));
        $this->assertFalse(app(OpenAiProvider::class)->supports(AiCapability::VideoUrl));

        try {
            $gemini->transcribe(new AiTranscriptionRequest('x', __FILE__));
            $this->fail('Transcription non prise en charge');
        } catch (AiProviderException $e) {
            $this->assertSame('UNSUPPORTED_CAPABILITY', $e->errorCode);
        }
        Http::assertNothingSent();

        config(['ai.providers.gemini.api_key' => null]);
        $this->assertFalse($gemini->isConfigured());
        $this->expectException(AiProviderException::class);
        $gemini->generateText(new AiTextRequest('m', 'x'));
    }

    public function test_openai_refuses_a_video_url_without_calling_the_api(): void
    {
        config(['ai.providers.openai.api_key' => 'sk-test-cle-fictive']);
        try {
            app(OpenAiProvider::class)->generateText(new AiTextRequest('m', 'x', videoUrl: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'));
            $this->fail('UNSUPPORTED_INPUT attendu');
        } catch (AiProviderException $e) {
            $this->assertSame('UNSUPPORTED_INPUT', $e->errorCode);
        }
        Http::assertNothingSent();
    }

    public function test_each_task_uses_its_own_provider_and_models(): void
    {
        config([
            'ai.default_provider' => 'openai',
            'ai.providers.openai.api_key' => 'sk-test-cle-fictive',
            'ai.tasks.video_analysis.provider' => 'gemini',
            'ai.tasks.video_analysis.model' => 'modele-openai-texte',
            'ai.tasks.transcription.provider' => null,
            'ai.tasks.transcription.model' => 'modele-openai-audio',
            'ai.tasks.image.provider' => null,
            'ai.tasks.image.model' => 'modele-openai-image',
        ]);
        $ai = app(AiService::class);

        $this->assertSame('gemini', $ai->providerFor(AiTask::VideoAnalysis)->name());
        $this->assertSame('gemini-analyse-test', $ai->modelFor(AiTask::VideoAnalysis), 'Jamais le modèle OpenAI pour Gemini.');
        $this->assertTrue($ai->supportsFor(AiTask::VideoAnalysis, AiCapability::VideoUrl));
        $this->assertSame('openai', $ai->providerFor(AiTask::Transcription)->name());
        $this->assertSame('modele-openai-audio', $ai->modelFor(AiTask::Transcription));
        $this->assertSame('modele-openai-image', $ai->modelFor(AiTask::Image));

        config(['ai.tasks.transcription.provider' => 'gemini']);
        $this->assertFalse($ai->supportsFor(AiTask::Transcription, AiCapability::Transcription), 'Gemini ne transcrit pas.');
        config(['ai.tasks.image.provider' => 'gemini']);
        $this->assertSame('gemini-image-test', $ai->modelFor(AiTask::Image));
        config(['ai.providers.gemini.models.image' => null]);
        $this->assertNull($ai->modelFor(AiTask::Image), 'Modèle Gemini absent : tâche non configurée.');
        $this->assertFalse($ai->supportsFor(AiTask::Image, AiCapability::Image));
    }

    public function test_ai_check_reports_each_task_without_any_network_call(): void
    {
        config([
            'ai.default_provider' => 'openai',
            'ai.providers.openai.api_key' => 'sk-test-cle-fictive',
            'ai.tasks.video_analysis.provider' => 'gemini',
            'ai.tasks.transcription.provider' => 'gemini',
            'ai.tasks.image.provider' => null,
            'ai.tasks.image.model' => null,
        ]);

        $this->artisan('ai:check', ['--tasks' => true])
            ->expectsOutputToContain('gemini-analyse-test')
            ->expectsOutputToContain('NON PRIS EN CHARGE')
            ->expectsOutputToContain('aucun appel réseau')
            ->doesntExpectOutputToContain(self::KEY)
            ->assertSuccessful();
        Http::assertNothingSent();
    }
}
