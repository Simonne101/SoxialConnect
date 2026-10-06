<?php

namespace App\Domain\AI\Providers;

use App\Domain\AI\Contracts\AiProvider;
use App\Domain\AI\Data\AiTextRequest;
use App\Domain\AI\Data\AiTextResult;
use App\Domain\AI\Exceptions\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
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
 * La clé API ne quitte jamais cette classe : ni message d'erreur, ni journal, ni résultat.
 */
final class OpenAiProvider implements AiProvider
{
    public const NAME = 'openai';

    /** Codes d'erreur 429 liés à la facturation (non temporaires), dont celui constaté au test réel du 2026-10-06. */
    private const BILLING_ERRORS = ['insufficient_quota', 'credit_balance_exhausted', 'billing_hard_limit_reached'];

    public function name(): string
    {
        return self::NAME;
    }

    public function isConfigured(): bool
    {
        return filled(config('ai.providers.openai.api_key'));
    }

    public function generateText(AiTextRequest $request): AiTextResult
    {
        if (! $this->isConfigured()) {
            throw AiProviderException::notConfigured('OPENAI_API_KEY non configurée.');
        }

        $payload = array_filter([
            'model' => $request->model,
            'input' => $request->input,
            'instructions' => $request->instructions,
            'max_output_tokens' => $request->maxOutputTokens,
            'temperature' => $request->temperature,
        ], fn ($value) => $value !== null) + ['store' => false];

        $started = hrtime(true);
        try {
            $response = Http::withToken((string) config('ai.providers.openai.api_key'))
                ->acceptJson()
                ->asJson()
                ->connectTimeout(10)
                ->timeout((int) config('ai.timeout', 60))
                ->post(rtrim((string) config('ai.providers.openai.base_url'), '/').'/responses', $payload);
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

        return $this->normalize($response, $latencyMs);
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
            $status === 429 => $error('RATE_LIMITED', 'Limite de requêtes OpenAI atteinte. Réessayez dans un instant.', true),
            $status >= 500 => $error('PROVIDER_UNAVAILABLE', 'OpenAI est momentanément indisponible.', true),
            in_array($status, [400, 422], true) => $error('INVALID_REQUEST', 'OpenAI a refusé la requête (paramètre ou modèle incompatible).'),
            default => $error('HTTP_ERROR', "OpenAI a répondu par une erreur ({$status})."),
        };
    }
}
