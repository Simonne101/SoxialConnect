<?php

namespace App\Support\Logging;

use Throwable;

/**
 * Masque les secrets avant écriture (journaux, métadonnées d'audit, extraits de réponses, jobs en échec)
 * — docs/security.md §13 : jamais de mot de passe, token, clé d'API ni secret de session.
 *
 * - Tableaux : valeur masquée si la clé est sensible (config security.redacted_keys), récursivement.
 * - Chaînes : motifs de secrets (Bearer, JWT, paires clé=valeur ou "clé":"valeur", jetons Google, jeton de
 *   bot Telegram dans une URL, signatures d'URL, clés base64).
 * - Exceptions : converties en tableau expurgé (classe, message, emplacement, pile, exceptions précédentes),
 *   car un formateur écrirait sinon leur message — qui peut contenir une URL avec un jeton — en clair.
 */
final class SecretRedactor
{
    public const MASK = '[REDACTED]';

    /** Profondeur maximale de la chaîne d'exceptions précédentes conservée. */
    private const MAX_PREVIOUS = 3;

    /** Longueur maximale d'une pile d'appels conservée (caractères). */
    private const MAX_TRACE = 8000;

    /** Noms de paramètres dont la valeur est secrète dans une URL, un en-tête ou un JSON sérialisé. */
    private const SECRET_PARAMS = 'access_token|refresh_token|id_token|client_secret|api_key|apikey|password|token|code|code_verifier'
        .'|secret|bot_token|webhook_secret|oauth_token|oauth_token_secret|oauth_verifier|signature|sig|x-amz-signature|x-goog-signature';

    /** Clés secrètes dans un JSON sérialisé (sans « code » ni « sig », surtout des codes d'erreur d'API). */
    private const JSON_SECRET_KEYS = 'access_token|refresh_token|id_token|client_secret|api_key|apikey|password|token|code_verifier'
        .'|secret|bot_token|webhook_secret|oauth_token|oauth_token_secret|oauth_verifier';

    /** @var array<string, true> */
    private array $keys;

    /** @param list<string> $keys */
    public function __construct(array $keys)
    {
        $this->keys = array_fill_keys(array_map(fn (string $k) => mb_strtolower($k), $keys), true);
    }

    public static function fromConfig(): self
    {
        return new self(config('security.redacted_keys', []));
    }

    /** Masque récursivement les valeurs dont la clé est sensible, puis les motifs de secrets dans les chaînes. */
    public function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = is_string($k) && isset($this->keys[mb_strtolower($k)]) ? self::MASK : $this->redact($v);
            }

            return $out;
        }
        if ($value instanceof Throwable) {
            return $this->redactThrowable($value);
        }

        return is_string($value) ? $this->redactString($value) : $value;
    }

    /**
     * Exception (et précédentes) sous forme de tableau expurgé : jamais l'objet, dont le message ou la pile
     * seraient sérialisés tels quels par le formateur du journal.
     *
     * @return array<string, mixed>
     */
    public function redactThrowable(Throwable $e, int $depth = 0): array
    {
        $out = [
            'class' => $e::class,
            'message' => $this->redactString($e->getMessage()),
            'code' => $e->getCode(),
            'file' => $e->getFile().':'.$e->getLine(),
            'trace' => mb_substr($this->redactString($e->getTraceAsString()), 0, self::MAX_TRACE),
        ];
        if (($previous = $e->getPrevious()) !== null && $depth < self::MAX_PREVIOUS) {
            $out['previous'] = $this->redactThrowable($previous, $depth + 1);
        }

        return $out;
    }

    /** Représentation texte d'une exception (message, pile, précédentes), expurgée — ex. failed_jobs.exception. */
    public function redactThrowableString(Throwable $e): string
    {
        return $this->redactString((string) $e);
    }

    public function redactString(string $value): string
    {
        $patterns = [
            // En-têtes Authorization « Bearer … »
            '/\b(Bearer|Basic)\s+[A-Za-z0-9\-._~+\/]+=*/i' => '$1 '.self::MASK,
            // JWT
            '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/' => self::MASK,
            // Jeton de bot Telegram dans une URL (https://api.telegram.org/bot<id>:<jeton>/méthode) ou seul.
            '/\bbot\d{5,}:[A-Za-z0-9_-]{20,}/' => 'bot'.self::MASK,
            '/\b\d{6,}:AA[A-Za-z0-9_-]{30,}\b/' => self::MASK,
            // Jetons Google : accès (ya29.…) et renouvellement (1//…).
            '/\bya29\.[A-Za-z0-9._-]{10,}/' => self::MASK,
            '/(?<![A-Za-z0-9])1\/\/[A-Za-z0-9_-]{20,}/' => self::MASK,
            // Paires clé=valeur dans une URL ou une chaîne (access_token=…, password=…, signature=…)
            '/\b('.self::SECRET_PARAMS.')=([^&\s"\']+)/i' => '$1='.self::MASK,
            // JSON sérialisé dans un message : "access_token":"…"
            '/"('.self::JSON_SECRET_KEYS.')"\s*:\s*"(?:[^"\\\\]|\\\\.)*"/i' => '"$1":"'.self::MASK.'"',
            // En-têtes secrets : X-Telegram-Bot-Api-Secret-Token: …, X-Api-Key: …
            '/\b(x-telegram-bot-api-secret-token|x-api-key|api-key)\s*:\s*\S+/i' => '$1: '.self::MASK,
            // Clés Laravel base64 (APP_KEY, TOKEN_ENCRYPTION_KEY)
            '/base64:[A-Za-z0-9+\/]{40,}={0,2}/' => self::MASK,
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $value);
    }
}
