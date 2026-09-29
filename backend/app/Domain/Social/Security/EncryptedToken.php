<?php

namespace App\Domain\Social\Security;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Cast Eloquent des colonnes *_encrypted de social_tokens : la valeur en clair n'existe
 * qu'en mémoire, le temps d'un appel à l'API d'une plateforme.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class EncryptedToken implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : app(TokenEncrypter::class)->decrypt((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }
        $encrypter = app(TokenEncrypter::class);

        return [
            $key => $encrypter->encrypt((string) $value),
            'encryption_key_version' => $encrypter->currentVersion(),
        ];
    }
}
