<?php

namespace App\Domain\AI\Providers;

use App\Domain\AI\Contracts\AiProvider;
use App\Domain\AI\Data\AiImageRequest;
use App\Domain\AI\Data\AiImageResult;
use App\Domain\AI\Data\AiTextRequest;
use App\Domain\AI\Data\AiTextResult;
use App\Domain\AI\Data\AiTranscriptionRequest;
use App\Domain\AI\Data\AiTranscriptionResult;
use App\Domain\AI\Enums\AiCapability;
use App\Domain\AI\Exceptions\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Google Gemini — API « Interactions » (POST {base_url}/interactions), recommandée par Google pour
 * les nouvelles intégrations (ADR-048). Client HTTP Laravel, sans SDK ; clé dans l'en-tête
 * x-goog-api-key (jamais dans l'URL, jamais journalisée).
 *
 * Requête : model, input[] (contenus « text », « video » par URI), system_instruction,
 * generation_config (max_output_tokens, temperature), response_format (JSON + schéma, ou image avec
 * aspect_ratio et image_size), store=false.
 * Réponse : status (completed, incomplete, failed…), steps[] (« model_output ») dont content[]
 * porte les textes (type « text ») ou les images (type « image », data en base64) ;
 * usage.total_input_tokens / total_output_tokens.
 *
 * Capacités : texte, analyse d'une vidéo YouTube publique par son URL (préversion Google), images.
 * Transcription horodatée NON prise en charge ici : la documentation de Gemini ne garantit pas des
 * horodatages de segments fiables (whisper.cpp local ou OpenAI restent les moteurs de transcription).
 */
final class GeminiProvider implements AiProvider
{
    public const NAME = 'gemini';

    /** Délais (ms) avant chaque nouvelle tentative après un 503 « high demand ». */
    public const OVERLOAD_RETRY_DELAYS_MS = [3000, 8000];

    public function name(): string
    {
        return self::NAME;
    }

    public function isConfigured(): bool
    {
        return filled(config('ai.providers.gemini.api_key'));
    }

    public function supports(AiCapability $capability): bool
    {
        return $capability !== AiCapability::Transcription;
    }

    public function generateText(AiTextRequest $request): AiTextResult
    {
        $this->ensureConfigured();

        $input = [['type' => 'text', 'text' => $request->input]];
        if ($request->videoUrl !== null) {
            $input[] = ['type' => 'video', 'uri' => $request->videoUrl];
        }
        $payload = array_filter([
            'model' => $request->model,
            'input' => $input,
            'system_instruction' => $request->instructions,
            'generation_config' => array_filter([
                'max_output_tokens' => $request->maxOutputTokens,
                'temperature' => $request->temperature,
            ], fn ($value) => $value !== null),
            'response_format' => $request->jsonSchema !== null
                ? ['type' => 'text', 'mime_type' => 'application/json', 'schema' => $request->jsonSchema]
                : null,
        ], fn ($value) => $value !== null) + ['store' => false];

        $timeout = (int) ($request->videoUrl !== null ? config('ai.providers.gemini.video_timeout', 300) : config('ai.timeout', 60));
        [$body, $latencyMs, $status] = $this->send($payload, $timeout);
        if ($body['status'] === 'failed') {
            throw new AiProviderException('GENERATION_FAILED', 'Gemini n\'a pas pu produire de réponse.', httpStatus: $status);
        }

        $text = '';
        foreach ($this->outputs($body) as $content) {
            if (($content['type'] ?? null) === 'text' && is_string($content['text'] ?? null)) {
                $text .= $content['text'];
            }
        }
        $usage = (array) ($body['usage'] ?? []);

        return new AiTextResult(
            provider: self::NAME,
            model: is_string($body['model'] ?? null) ? $body['model'] : $request->model,
            text: $text,
            complete: ($body['status'] ?? null) === 'completed',
            incompleteReason: ($body['status'] ?? null) === 'incomplete' ? 'incomplete' : null,
            inputTokens: is_int($usage['total_input_tokens'] ?? null) ? $usage['total_input_tokens'] : null,
            outputTokens: is_int($usage['total_output_tokens'] ?? null) ? $usage['total_output_tokens'] : null,
            latencyMs: $latencyMs,
            responseId: is_string($body['id'] ?? null) ? $body['id'] : null,
        );
    }

    public function transcribe(AiTranscriptionRequest $request): AiTranscriptionResult
    {
        throw new AiProviderException('UNSUPPORTED_CAPABILITY', 'La transcription horodatée n\'est pas prise en charge par ce fournisseur IA.');
    }

    public function generateImage(AiImageRequest $request): AiImageResult
    {
        $this->ensureConfigured();

        $payload = [
            'model' => $request->model,
            'input' => [['type' => 'text', 'text' => $request->prompt]],
            'response_format' => [
                'type' => 'image',
                'aspect_ratio' => $request->aspectRatio ?? self::ratioOf($request->size),
                'image_size' => (string) config('ai.providers.gemini.image_size', '1K'),
            ],
            'store' => false,
        ];
        [$body, $latencyMs, $status] = $this->send($payload, (int) config('ai.images.timeout', 180));

        foreach ($this->outputs($body) as $content) {
            if (($content['type'] ?? null) === 'image' && is_string($content['data'] ?? null)) {
                $bytes = base64_decode($content['data'], true);
                if ($bytes !== false && $bytes !== '') {
                    $usage = (array) ($body['usage'] ?? []);

                    return new AiImageResult(
                        provider: self::NAME,
                        model: $request->model,
                        bytes: $bytes,
                        inputTokens: is_int($usage['total_input_tokens'] ?? null) ? $usage['total_input_tokens'] : null,
                        outputTokens: is_int($usage['total_output_tokens'] ?? null) ? $usage['total_output_tokens'] : null,
                        latencyMs: $latencyMs,
                    );
                }
            }
        }

        // Aucune image : demande refusée par les règles de contenu ou réponse inattendue.
        throw ($body['status'] ?? null) === 'failed' || ($body['status'] ?? null) === 'incomplete'
            ? new AiProviderException('CONTENT_REJECTED', 'La demande a été refusée par les règles de contenu du fournisseur IA. Reformulez-la.', httpStatus: $status)
            : new AiProviderException('INVALID_RESPONSE', 'Réponse inattendue de Gemini (image).', httpStatus: $status);
    }

    /** « 1024x1536 » → « 2:3 » (format le plus proche accepté par Gemini). */
    public static function ratioOf(string $size): string
    {
        [$w, $h] = array_map('intval', explode('x', strtolower($size)) + [1 => 0]);
        if ($w <= 0 || $h <= 0) {
            return '1:1';
        }
        $ratio = $w / $h;
        $allowed = ['1:1' => 1, '2:3' => 2 / 3, '3:2' => 3 / 2, '3:4' => 3 / 4, '4:3' => 4 / 3, '4:5' => 4 / 5, '5:4' => 5 / 4, '9:16' => 9 / 16, '16:9' => 16 / 9];
        uasort($allowed, fn ($a, $b) => abs($a - $ratio) <=> abs($b - $ratio));

        return (string) array_key_first($allowed);
    }

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw AiProviderException::notConfigured('GEMINI_API_KEY non configurée.');
        }
    }

    /**
     * Contenus produits par le modèle (étapes « model_output »).
     *
     * @param  array<string, mixed>  $body
     * @return list<array<string, mixed>>
     */
    private function outputs(array $body): array
    {
        $contents = [];
        foreach ((array) ($body['steps'] ?? []) as $step) {
            if (is_array($step) && ($step['type'] ?? 'model_output') === 'model_output') {
                foreach ((array) ($step['content'] ?? []) as $content) {
                    if (is_array($content)) {
                        $contents[] = $content;
                    }
                }
            }
        }

        return $contents;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: array<string, mixed>, 1: int, 2: int} corps, latence (ms), statut HTTP
     */
    private function send(array $payload, int $timeout): array
    {
        $started = hrtime(true);
        try {
            $response = Http::withHeaders(['x-goog-api-key' => (string) config('ai.providers.gemini.api_key')])
                ->acceptJson()
                ->asJson()
                ->connectTimeout(10)
                ->timeout($timeout)
                // 503 « high demand » : surcharge passagère d'un modèle, constatée en réel ; deux
                // nouvelles tentatives espacées. Jamais pour un quota, un 429 ou un délai dépassé.
                ->retry(self::OVERLOAD_RETRY_DELAYS_MS, when: fn ($e) => $e instanceof RequestException && $e->response->status() === 503, throw: false)
                ->post(rtrim((string) config('ai.providers.gemini.base_url'), '/').'/interactions', $payload);
        } catch (ConnectionException $e) {
            throw str_contains($e->getMessage(), 'cURL error 28')
                ? new AiProviderException('TIMEOUT', 'Gemini n\'a pas répondu dans le délai imparti.', transient: true)
                : new AiProviderException('NETWORK_ERROR', 'Gemini est momentanément injoignable.', transient: true);
        }
        $latencyMs = (int) round((hrtime(true) - $started) / 1_000_000);

        if (! $response->successful()) {
            throw $this->httpError($response);
        }
        $body = $response->json();
        if (! is_array($body) || ! is_string($body['status'] ?? null)) {
            throw new AiProviderException('INVALID_RESPONSE', 'Réponse inattendue de Gemini.', httpStatus: $response->status());
        }
        if (in_array($body['status'], ['cancelled', 'requires_action'], true)) {
            throw new AiProviderException('GENERATION_FAILED', 'Gemini n\'a pas pu produire de réponse.', httpStatus: $response->status());
        }

        return [$body, $latencyMs, $response->status()];
    }

    /**
     * Erreurs HTTP normalisées. Le message de Google n'est jamais repris tel quel ; seul un code
     * (status ou code de l'erreur) est conservé pour le diagnostic.
     */
    private function httpError(Response $response): AiProviderException
    {
        $status = $response->status();
        $code = $response->json('error.status') ?? $response->json('error.code');
        $code = is_string($code) && $code !== '' ? $code : null;
        // Quota du projet, y compris une limite QUOTIDIENNE (« 20 requests per day on Free Tier »,
        // constaté en réel) : attendre « un instant » ne suffit pas, ce n'est pas une limite passagère.
        $message = strtolower((string) $response->json('error.message'));
        $quota = str_contains($message, 'quota') || str_contains($message, 'per day');
        $error = fn (string $errorCode, string $message, bool $transient = false) => new AiProviderException($errorCode, $message, $transient, $status, $code);

        return match (true) {
            $status === 401, $status === 403 && str_contains(strtolower((string) $response->json('error.message')), 'api key') => $error('AUTHENTICATION_FAILED', 'Gemini refuse la clé API (GEMINI_API_KEY invalide ou révoquée).'),
            $status === 403 => $error('PERMISSION_DENIED', 'Gemini refuse l\'accès (projet, région ou modèle non autorisés).'),
            $status === 404 => $error('MODEL_NOT_FOUND', 'Modèle Gemini introuvable ou non accessible avec cette clé.'),
            $status === 429 && $quota => $error('QUOTA_EXCEEDED', 'Quota Gemini épuisé : vérifiez le plan et la facturation du projet Google.'),
            $status === 429 => $error('RATE_LIMITED', 'Limite de requêtes Gemini atteinte. Réessayez dans un instant.', true),
            $status >= 500 => $error('PROVIDER_UNAVAILABLE', 'Gemini est momentanément indisponible.', true),
            in_array($status, [400, 422], true) => $error('INVALID_REQUEST', 'Gemini a refusé la requête (paramètre, vidéo ou modèle incompatible).'),
            default => $error('HTTP_ERROR', "Gemini a répondu par une erreur ({$status})."),
        };
    }
}
