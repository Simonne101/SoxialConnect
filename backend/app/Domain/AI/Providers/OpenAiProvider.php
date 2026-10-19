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
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI — API « Responses » (POST {base_url}/responses), recommandée par OpenAI pour les
 * nouvelles intégrations (ADR-043). Client HTTP Laravel, sans SDK.
 *
 * Requête : model, input, instructions (facultatif), max_output_tokens, temperature (facultatif,
 * certains modèles la refusent), store=false (OpenAI ne conserve pas la réponse).
 * Réponse : output[] contient des éléments de types variés (reasoning, message…) ; le texte est la
 * concaténation des parties « output_text » des éléments « message ». La propriété output_text
 * n'existe que dans les SDK : elle n'est pas lue ici.
 *
 * Transcription (ADR-046) : POST {base_url}/audio/transcriptions (multipart, verbose_json, segments
 * horodatés). Images : POST {base_url}/images/generations (image renvoyée en base64).
 *
 * La clé API ne quitte jamais cette classe : ni message d'erreur, ni journal, ni résultat.
 */
final class OpenAiProvider implements AiProvider
{
    public const NAME = 'openai';

    /** Codes d'erreur 429 liés à la facturation (non temporaires), dont celui constaté au test réel du 2026-10-06. */
    private const BILLING_ERRORS = ['insufficient_quota', 'credit_balance_exhausted', 'billing_hard_limit_reached'];

    /** Demande refusée par la modération du fournisseur (prompt ou image). */
    private const CONTENT_ERRORS = ['moderation_blocked', 'content_policy_violation'];

    public function name(): string
    {
        return self::NAME;
    }

    public function isConfigured(): bool
    {
        return filled(config('ai.providers.openai.api_key'));
    }

    /** Texte, transcription (whisper), images ; pas d'analyse de vidéo par URL. */
    public function supports(AiCapability $capability): bool
    {
        return $capability !== AiCapability::VideoUrl;
    }

    public function generateText(AiTextRequest $request): AiTextResult
    {
        if (! $this->isConfigured()) {
            throw AiProviderException::notConfigured('OPENAI_API_KEY non configurée.');
        }
        if ($request->videoUrl !== null) {
            throw new AiProviderException('UNSUPPORTED_INPUT', 'Ce fournisseur IA ne peut pas analyser une vidéo à partir de son lien.');
        }

        $payload = array_filter([
            'model' => $request->model,
            'input' => $request->input,
            'instructions' => $request->instructions,
            'max_output_tokens' => $request->maxOutputTokens,
            'temperature' => $request->temperature,
        ], fn ($value) => $value !== null) + ['store' => false];

        [$response, $latencyMs] = $this->send((int) config('ai.timeout', 60), fn (PendingRequest $http, string $base) => $http->asJson()->post($base.'/responses', $payload));

        return $this->normalize($response, $latencyMs);
    }

    public function transcribe(AiTranscriptionRequest $request): AiTranscriptionResult
    {
        if (! $this->isConfigured()) {
            throw AiProviderException::notConfigured('OPENAI_API_KEY non configurée.');
        }
        $size = is_file($request->audioPath) ? (int) filesize($request->audioPath) : 0;
        if ($size === 0) {
            throw new AiProviderException('INVALID_REQUEST', 'Fichier audio introuvable ou vide.');
        }
        if ($size > (int) config('ai.transcription.max_file_mb', 25) * 1024 * 1024) {
            throw new AiProviderException('AUDIO_TOO_LARGE', 'La piste audio est trop longue pour être transcrite.');
        }

        $fields = array_filter([
            'model' => $request->model,
            'response_format' => 'verbose_json',
            'timestamp_granularities[]' => 'segment',
            'language' => $request->language,
        ], fn ($value) => $value !== null);

        $stream = fopen($request->audioPath, 'rb');
        try {
            [$response, $latencyMs] = $this->send((int) config('ai.transcription.timeout', 600), fn (PendingRequest $http, string $base) => $http
                ->attach('file', $stream, basename($request->audioPath))
                ->post($base.'/audio/transcriptions', $fields));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $body = $response->json();
        if (! is_array($body) || ! is_string($body['text'] ?? null) || ! is_array($body['segments'] ?? null)) {
            throw new AiProviderException('INVALID_RESPONSE', 'Réponse inattendue d\'OpenAI (transcription).', httpStatus: $response->status());
        }
        $segments = [];
        foreach ($body['segments'] as $segment) {
            $start = $segment['start'] ?? null;
            $end = $segment['end'] ?? null;
            $text = trim((string) ($segment['text'] ?? ''));
            if (! is_numeric($start) || ! is_numeric($end) || $end < $start || $text === '') {
                continue;
            }
            $segments[] = ['start_ms' => (int) round($start * 1000), 'end_ms' => (int) round($end * 1000), 'text' => $text];
        }

        return new AiTranscriptionResult(
            provider: self::NAME,
            model: $request->model,
            text: trim($body['text']),
            language: is_string($body['language'] ?? null) ? $body['language'] : null,
            segments: $segments,
            latencyMs: $latencyMs,
        );
    }

    public function generateImage(AiImageRequest $request): AiImageResult
    {
        if (! $this->isConfigured()) {
            throw AiProviderException::notConfigured('OPENAI_API_KEY non configurée.');
        }

        $payload = ['model' => $request->model, 'prompt' => $request->prompt, 'size' => $request->size, 'n' => 1];
        [$response, $latencyMs] = $this->send((int) config('ai.images.timeout', 180), fn (PendingRequest $http, string $base) => $http->asJson()->post($base.'/images/generations', $payload));

        $encoded = $response->json('data.0.b64_json');
        $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($bytes === false || $bytes === '') {
            throw new AiProviderException('INVALID_RESPONSE', 'Réponse inattendue d\'OpenAI (image).', httpStatus: $response->status());
        }
        $usage = (array) ($response->json('usage') ?? []);

        return new AiImageResult(
            provider: self::NAME,
            model: $request->model,
            bytes: $bytes,
            inputTokens: is_int($usage['input_tokens'] ?? null) ? $usage['input_tokens'] : null,
            outputTokens: is_int($usage['output_tokens'] ?? null) ? $usage['output_tokens'] : null,
            latencyMs: $latencyMs,
        );
    }

    /**
     * Appel authentifié commun : délais, erreurs réseau et HTTP traduites en AiProviderException.
     *
     * @param  Closure(PendingRequest, string): Response  $call
     * @return array{0: Response, 1: int} réponse réussie et latence (ms)
     */
    private function send(int $timeout, Closure $call): array
    {
        $http = Http::withToken((string) config('ai.providers.openai.api_key'))
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout($timeout);

        $started = hrtime(true);
        try {
            $response = $call($http, rtrim((string) config('ai.providers.openai.base_url'), '/'));
        } catch (ConnectionException $e) {
            // cURL 28 : délai dépassé ; autres : DNS, connexion refusée, TLS.
            throw str_contains($e->getMessage(), 'cURL error 28')
                ? new AiProviderException('TIMEOUT', 'OpenAI n\'a pas répondu dans le délai imparti.', transient: true)
                : new AiProviderException('NETWORK_ERROR', 'OpenAI est momentanément injoignable.', transient: true);
        }
        $latencyMs = (int) round((hrtime(true) - $started) / 1_000_000);

        if (! $response->successful()) {
            throw $this->httpError($response);
        }

        return [$response, $latencyMs];
    }

    private function normalize(Response $response, int $latencyMs): AiTextResult
    {
        $body = $response->json();
        if (! is_array($body) || ($body['object'] ?? null) !== 'response' || ! is_array($body['output'] ?? null)) {
            throw new AiProviderException('INVALID_RESPONSE', 'Réponse inattendue d\'OpenAI.', httpStatus: $response->status());
        }

        $status = $body['status'] ?? null;
        if ($status === 'failed' || filled($body['error'] ?? null)) {
            $code = is_string($body['error']['code'] ?? null) ? $body['error']['code'] : null;

            throw new AiProviderException('GENERATION_FAILED', 'OpenAI n\'a pas pu produire de réponse.', httpStatus: $response->status(), providerCode: $code);
        }
        if (! in_array($status, ['completed', 'incomplete'], true)) {
            throw new AiProviderException('INVALID_RESPONSE', 'Réponse OpenAI dans un état inattendu.', httpStatus: $response->status());
        }

        $text = '';
        foreach ($body['output'] as $item) {
            if (($item['type'] ?? null) !== 'message') {
                continue; // raisonnement, appels d'outils… : pas de texte destiné à l'utilisateur.
            }
            foreach ((array) ($item['content'] ?? []) as $part) {
                if (($part['type'] ?? null) === 'output_text' && is_string($part['text'] ?? null)) {
                    $text .= $part['text'];
                }
            }
        }

        $usage = (array) ($body['usage'] ?? []);

        return new AiTextResult(
            provider: self::NAME,
            model: is_string($body['model'] ?? null) ? $body['model'] : '',
            text: $text,
            complete: $status === 'completed',
            incompleteReason: is_string($body['incomplete_details']['reason'] ?? null) ? $body['incomplete_details']['reason'] : null,
            inputTokens: is_int($usage['input_tokens'] ?? null) ? $usage['input_tokens'] : null,
            outputTokens: is_int($usage['output_tokens'] ?? null) ? $usage['output_tokens'] : null,
            latencyMs: $latencyMs,
            responseId: is_string($body['id'] ?? null) ? $body['id'] : null,
        );
    }

    /**
     * Erreurs HTTP (corps { error: { message, type, param, code } }). Le message d'OpenAI n'est
     * jamais repris : il peut citer une partie de la clé (« Incorrect API key provided: sk-…xyz »).
     */
    private function httpError(Response $response): AiProviderException
    {
        $status = $response->status();
        $code = $response->json('error.code');
        $code = is_string($code) && $code !== '' ? $code : (is_string($response->json('error.type')) ? $response->json('error.type') : null);
        $error = fn (string $errorCode, string $message, bool $transient = false) => new AiProviderException($errorCode, $message, $transient, $status, $code);

        return match (true) {
            $status === 401 => $error('AUTHENTICATION_FAILED', 'OpenAI refuse la clé API (OPENAI_API_KEY invalide ou révoquée).'),
            $status === 403 => $error('PERMISSION_DENIED', 'OpenAI refuse l\'accès (projet, organisation ou région non autorisés).'),
            $status === 404 => $error('MODEL_NOT_FOUND', 'Modèle OpenAI introuvable ou non accessible avec cette clé.'),
            // 429 de facturation (quota, solde de crédits) : définitif tant que le compte n'est pas rechargé.
            $status === 429 && in_array($code, self::BILLING_ERRORS, true) => $error('QUOTA_EXCEEDED', 'Crédits ou quota OpenAI épuisés : rechargez le compte OpenAI.'),
            in_array($code, self::CONTENT_ERRORS, true) => $error('CONTENT_REJECTED', 'La demande a été refusée par les règles de contenu du fournisseur IA. Reformulez-la.'),
            $status === 429 => $error('RATE_LIMITED', 'Limite de requêtes OpenAI atteinte. Réessayez dans un instant.', true),
            $status >= 500 => $error('PROVIDER_UNAVAILABLE', 'OpenAI est momentanément indisponible.', true),
            in_array($status, [400, 422], true) => $error('INVALID_REQUEST', 'OpenAI a refusé la requête (paramètre ou modèle incompatible).'),
            default => $error('HTTP_ERROR', "OpenAI a répondu par une erreur ({$status})."),
        };
    }
}
