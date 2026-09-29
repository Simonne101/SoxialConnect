<?php

namespace App\Support\Logging;

/**
 * Masque les secrets avant écriture (journaux, métadonnées d'audit, extraits de réponses)
 * — docs/security.md §13 : jamais de mot de passe, token, clé d'API ni secret de session.
 */
final class SecretRedactor
{
    public const MASK = '[REDACTED]';

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

        return is_string($value) ? $this->redactString($value) : $value;
    }

    public function redactString(string $value): string
    {
        $patterns = [
            // En-têtes Authorization « Bearer … »
            '/\b(Bearer|Basic)\s+[A-Za-z0-9\-._~+\/]+=*/i' => '$1 '.self::MASK,
            // JWT
            '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/' => self::MASK,
            // Paires clé=valeur dans une URL ou une chaîne (access_token=…, password=…)
            '/\b(access_token|refresh_token|client_secret|api_key|apikey|password|token|code)=([^&\s"\']+)/i' => '$1='.self::MASK,
            // Clés Laravel base64 (APP_KEY, TOKEN_ENCRYPTION_KEY)
            '/base64:[A-Za-z0-9+\/]{40,}={0,2}/' => self::MASK,
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $value);
    }
}
