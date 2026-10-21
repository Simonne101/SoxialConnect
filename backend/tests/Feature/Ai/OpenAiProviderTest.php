<?php

namespace Tests\Feature\Ai;

use App\Domain\AI\Data\AiTextRequest;
use App\Domain\AI\Exceptions\AiProviderException;
use App\Domain\AI\Providers\OpenAiProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * OpenAiProvider (ADR-043) : requête « Responses » construite selon la documentation, réponse
 * normalisée, erreurs classées, clé jamais exposée. Aucun appel réel (Http::preventStrayRequests).
 */
class OpenAiProviderTest extends TestCase
{
    private const KEY = 'sk-test-cle-fictive-ne-doit-jamais-sortir-0000';

    private const URL = 'https://api.openai.com/v1/responses';

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $logs = [];

    /** Réponse du faux serveur OpenAI (un seul Http::fake : les faux s'empilent). */
    private ?\Closure $responder = null;

    private function respond(\Closure $responder): void
    {
        if ($this->responder === null) {
            Http::fake(fn (Request $request, array $options) => ($this->responder)($request, $options));
        }
        $this->responder = $responder;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'ai.default_provider' => 'openai',
            'ai.providers.openai.api_key' => self::KEY,
            'ai.providers.openai.base_url' => 'https://api.openai.com/v1',
            'ai.timeout' => 42,
        ]);
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logs[] = [$e->message, $e->context];
        });
    }

    private function provider(): OpenAiProvider
    {
        return app(OpenAiProvider::class);
    }

    private function request(array $overrides = []): AiTextRequest
    {
        return new AiTextRequest(...$overrides + ['model' => 'modele-test', 'input' => 'Bonjour', 'maxOutputTokens' => 64]);
    }

    /** Réponse « Responses » réaliste : élément de raisonnement puis message en deux parties. */
    private function completed(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'resp_123',
            'object' => 'response',
            'status' => 'completed',
            'model' => 'modele-test-2026-01-01',
            'output' => [
                ['id' => 'rs_1', 'type' => 'reasoning', 'summary' => []],
                ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [
                    ['type' => 'output_text', 'text' => 'Bonjour ', 'annotations' => []],
                    ['type' => 'output_text', 'text' => 'SoxialConnect', 'annotations' => []],
                ]],
            ],
            'usage' => ['input_tokens' => 12, 'output_tokens' => 5, 'total_tokens' => 17],
            'error' => null,
            'incomplete_details' => null,
        ];
    }

    private function assertNothingLeaked(string ...$texts): void
    {
        foreach ([...$texts, (string) json_encode($this->logs)] as $text) {
            $this->assertStringNotContainsString(self::KEY, $text);
            $this->assertStringNotContainsString('Bearer', $text);
        }
    }

    public function test_the_request_follows_the_responses_api_and_carries_the_key_only_in_the_authorization_header(): void
    {
        $this->respond(function (Request $request, array $options) {
            $this->assertSame(42, $options['timeout'], 'Délai config ai.timeout appliqué.');
            $this->assertSame(10, $options['connect_timeout']);

            return Http::response($this->completed());
        });

        $this->provider()->generateText($this->request(['instructions' => 'Sois bref.', 'temperature' => 0.4]));

        Http::assertSent(fn (Request $r) => $r->url() === self::URL
            && $r->method() === 'POST'
            && $r->isJson()
            && $r->hasHeader('Authorization', 'Bearer '.self::KEY)
            && $r->data() === ['model' => 'modele-test', 'input' => 'Bonjour', 'instructions' => 'Sois bref.', 'max_output_tokens' => 64, 'temperature' => 0.4, 'store' => false]);
        Http::assertSentCount(1);
    }

    public function test_optional_settings_are_omitted_when_not_set(): void
    {
        $this->respond(fn () => Http::response($this->completed()));

        $this->provider()->generateText($this->request());

        Http::assertSent(fn (Request $r) => $r->data() === ['model' => 'modele-test', 'input' => 'Bonjour', 'max_output_tokens' => 64, 'store' => false]);
    }

    public function test_a_valid_response_is_normalized(): void
    {
        $this->respond(fn () => Http::response($this->completed()));

        $result = $this->provider()->generateText($this->request());

        $this->assertSame('openai', $result->provider);
        $this->assertSame('modele-test-2026-01-01', $result->model);
        $this->assertSame('Bonjour SoxialConnect', $result->text, 'Parties output_text des messages, raisonnement ignoré.');
        $this->assertTrue($result->complete);
        $this->assertNull($result->incompleteReason);
        $this->assertSame(12, $result->inputTokens);
        $this->assertSame(5, $result->outputTokens);
        $this->assertSame('resp_123', $result->responseId);
        $this->assertGreaterThanOrEqual(0, $result->latencyMs);
        $this->assertNothingLeaked((string) json_encode($result), print_r($result, true));
    }

    public function test_an_incomplete_response_is_returned_as_such(): void
    {
        $this->respond(fn () => Http::response($this->completed([
            'status' => 'incomplete',
            'incomplete_details' => ['reason' => 'max_output_tokens'],
            'output' => [['id' => 'rs_1', 'type' => 'reasoning', 'summary' => []]],
        ])));

        $result = $this->provider()->generateText($this->request());

        $this->assertFalse($result->complete);
        $this->assertSame('max_output_tokens', $result->incompleteReason);
        $this->assertSame('', $result->text);
    }

    public function test_http_errors_are_classified_without_repeating_the_provider_message(): void
    {
        $leaky = 'Incorrect API key provided: '.self::KEY;
        foreach ([
            [401, ['message' => $leaky, 'type' => 'invalid_request_error', 'code' => 'invalid_api_key'], 'AUTHENTICATION_FAILED', false, 'invalid_api_key'],
            [403, ['message' => 'Country not supported', 'type' => 'invalid_request_error', 'code' => 'unsupported_country_region_territory'], 'PERMISSION_DENIED', false, 'unsupported_country_region_territory'],
            [404, ['message' => 'The model does not exist', 'type' => 'invalid_request_error', 'code' => 'model_not_found'], 'MODEL_NOT_FOUND', false, 'model_not_found'],
            [400, ['message' => "Unsupported parameter: 'temperature'", 'type' => 'invalid_request_error', 'param' => 'temperature', 'code' => 'unsupported_parameter'], 'INVALID_REQUEST', false, 'unsupported_parameter'],
            [429, ['message' => 'You exceeded your current quota', 'type' => 'insufficient_quota', 'code' => 'insufficient_quota'], 'QUOTA_EXCEEDED', false, 'insufficient_quota'],
            [429, ['message' => 'Your credit balance is exhausted', 'type' => 'insufficient_quota', 'code' => 'credit_balance_exhausted'], 'QUOTA_EXCEEDED', false, 'credit_balance_exhausted'],
            [429, ['message' => 'Rate limit reached', 'type' => 'requests', 'code' => 'rate_limit_exceeded'], 'RATE_LIMITED', true, 'rate_limit_exceeded'],
            [500, ['message' => 'The server had an error', 'type' => 'server_error', 'code' => null], 'PROVIDER_UNAVAILABLE', true, 'server_error'],
            [503, [], 'PROVIDER_UNAVAILABLE', true, null],
        ] as [$status, $error, $code, $transient, $providerCode]) {
            $this->respond(fn () => Http::response($error === [] ? '' : ['error' => $error], $status));
            try {
                $this->provider()->generateText($this->request());
                $this->fail("HTTP {$status} : exception attendue.");
            } catch (AiProviderException $e) {
                $this->assertSame($code, $e->errorCode, "HTTP {$status}");
                $this->assertSame($transient, $e->transient, "HTTP {$status}");
                $this->assertSame($status, $e->httpStatus);
                $this->assertSame($providerCode, $e->providerCode, "HTTP {$status}");
                $this->assertStringNotContainsString($error['message'] ?? '§', $e->getMessage(), 'Message du fournisseur jamais repris.');
                $this->assertNothingLeaked($e->getMessage());
            }
        }
    }

    public function test_api_errors_and_malformed_responses_inside_a_200_are_rejected(): void
    {
        foreach ([
            [$this->completed(['status' => 'failed', 'error' => ['code' => 'server_error', 'message' => 'x']]), 'GENERATION_FAILED'],
            [$this->completed(['status' => 'queued']), 'INVALID_RESPONSE'],
            [['object' => 'chat.completion', 'choices' => []], 'INVALID_RESPONSE'],
            ['<html>pas du JSON</html>', 'INVALID_RESPONSE'],
        ] as [$body, $code]) {
            $this->respond(fn () => Http::response($body));
            try {
                $this->provider()->generateText($this->request());
                $this->fail("{$code} attendu.");
            } catch (AiProviderException $e) {
                $this->assertSame($code, $e->errorCode);
                $this->assertFalse($e->transient);
            }
        }
    }

    public function test_timeouts_and_network_failures_are_transient(): void
    {
        foreach ([
            ['cURL error 28: Operation timed out after 42001 milliseconds', 'TIMEOUT'],
            ['cURL error 6: Could not resolve host: api.openai.com', 'NETWORK_ERROR'],
        ] as [$failure, $code]) {
            $this->respond(fn (Request $r) => Http::failedConnection($failure)($r));
            try {
                $this->provider()->generateText($this->request());
                $this->fail("{$code} attendu.");
            } catch (AiProviderException $e) {
                $this->assertSame($code, $e->errorCode);
                $this->assertTrue($e->transient);
                $this->assertNothingLeaked($e->getMessage());
            }
        }
    }

    public function test_without_a_key_nothing_is_sent(): void
    {
        config(['ai.providers.openai.api_key' => null]);
        Http::fake();

        $this->assertFalse($this->provider()->isConfigured());
        try {
            $this->provider()->generateText($this->request());
            $this->fail('NOT_CONFIGURED attendu.');
        } catch (AiProviderException $e) {
            $this->assertSame('NOT_CONFIGURED', $e->errorCode);
            $this->assertSame('OPENAI_API_KEY non configurée.', $e->getMessage());
        }
        Http::assertNothingSent();
    }
}
