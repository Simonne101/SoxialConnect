<?php

namespace App\Domain\Social\Security;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Chiffrement des tokens OAuth (ADR-008) : AES-256-GCM avec une clé dédiée, distincte
 * d'APP_KEY, et versionnée pour permettre la rotation.
 *
 * Format stocké : « v{version}:{payload chiffré Laravel} ». Les anciennes versions restent
 * lisibles tant que leur clé figure dans TOKEN_ENCRYPTION_PREVIOUS_KEYS.
 */
final class TokenEncrypter
{
    /** @var array<int, Encrypter> */
    private array $encrypters = [];

    public function __construct(
        private readonly int $currentVersion,
        /** @var array<int, string> clé brute (32 octets) par version */
        private readonly array $keys,
        private readonly string $cipher = 'aes-256-gcm',
    ) {
        if (! isset($this->keys[$this->currentVersion])) {
            throw new RuntimeException('TOKEN_ENCRYPTION_KEY manquante ou invalide.');
        }
    }

    public static function fromConfig(array $config): self
    {
        $version = (int) ($config['version'] ?? 1);
        $keys = [];
        if (! empty($config['key'])) {
            $keys[$version] = self::decodeKey((string) $config['key']);
        }
        foreach (array_filter(array_map('trim', explode(',', (string) ($config['previous_keys'] ?? '')))) as $entry) {
            [$v, $k] = array_pad(explode(':', $entry, 2), 2, null);
            if (is_numeric($v) && $k) {
                $keys[(int) $v] = self::decodeKey($k);
            }
        }

        return new self($version, $keys, $config['cipher'] ?? 'aes-256-gcm');
    }

    public function currentVersion(): int
    {
        return $this->currentVersion;
    }

    public function encrypt(string $plain): string
    {
        return 'v'.$this->currentVersion.':'.$this->encrypter($this->currentVersion)->encryptString($plain);
    }

    public function decrypt(string $stored): string
    {
        if (! preg_match('/^v(\d+):(.+)$/s', $stored, $m)) {
            throw new RuntimeException('Format de token chiffré inconnu.');
        }

        return $this->encrypter((int) $m[1])->decryptString($m[2]);
    }

    /** Version de clé utilisée pour une valeur stockée (rotation). */
    public function versionOf(string $stored): ?int
    {
        return preg_match('/^v(\d+):/', $stored, $m) ? (int) $m[1] : null;
    }

    private function encrypter(int $version): Encrypter
    {
        if (! isset($this->keys[$version])) {
            throw new RuntimeException("Clé de chiffrement des tokens v{$version} indisponible.");
        }

        return $this->encrypters[$version] ??= new Encrypter($this->keys[$version], $this->cipher);
    }

    private static function decodeKey(string $key): string
    {
        $raw = Str::startsWith($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        if ($raw === false || strlen($raw) !== 32) {
            throw new RuntimeException('La clé de chiffrement des tokens doit faire 32 octets.');
        }

        return $raw;
    }
}
