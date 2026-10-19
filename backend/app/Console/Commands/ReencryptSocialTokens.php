<?php

namespace App\Console\Commands;

use App\Domain\Social\Security\TokenEncrypter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Rotation de TOKEN_ENCRYPTION_KEY (lot 2 de l'audit, docs/secrets-remediation.md) : rechiffre avec la clé ACTUELLE
 * les jetons OAuth (social_tokens) encore chiffrés avec une ancienne version, lisible grâce à
 * TOKEN_ENCRYPTION_PREVIOUS_KEYS.
 *
 * - --dry-run : ne modifie rien ; compte les jetons par version et vérifie qu'ils sont TOUS déchiffrables avec la
 *   configuration actuelle (à lancer avant de retirer une ancienne clé, et avant le rechiffrement).
 * - Rechiffrement : ligne par ligne, sous le verrou de rafraîchissement du compte (« social-token-refresh:{compte} »,
 *   le même que les gestionnaires de jetons) et seulement si la valeur stockée n'a pas changé entre-temps.
 * - Aucune valeur (claire ou chiffrée) n'est jamais affichée ni journalisée. Code 1 si un jeton est illisible.
 */
#[Signature('security:reencrypt-tokens {--dry-run : Vérifier sans rien modifier} {--chunk=100 : Lignes lues par lot}')]
#[Description('Rechiffre les jetons OAuth avec la clé de chiffrement actuelle (rotation de TOKEN_ENCRYPTION_KEY)')]
class ReencryptSocialTokens extends Command
{
    private const COLUMNS = ['access_token_encrypted', 'refresh_token_encrypted'];

    public function handle(TokenEncrypter $encrypter): int
    {
        $current = $encrypter->currentVersion();
        $dryRun = (bool) $this->option('dry-run');
        $stats = ['total' => 0, 'current' => 0, 'rewritten' => 0, 'unreadable' => 0, 'skipped' => 0];
        $byVersion = [];

        DB::table('social_tokens')->orderBy('id')->select(['id', 'social_account_id', ...self::COLUMNS])
            ->chunkById(max(1, (int) $this->option('chunk')), function ($rows) use ($encrypter, $current, $dryRun, &$stats, &$byVersion) {
                foreach ($rows as $row) {
                    $stats['total']++;
                    $versions = $this->versions($encrypter, $row);
                    foreach ($versions as $version) {
                        $byVersion[$version] = ($byVersion[$version] ?? 0) + 1;
                    }
                    if (! $this->readable($encrypter, $row)) {
                        $stats['unreadable']++;

                        continue;
                    }
                    if (array_diff($versions, [$current]) === []) {
                        $stats['current']++;

                        continue;
                    }
                    if ($dryRun) {
                        continue;
                    }
                    $stats[$this->rewrite($encrypter, $row) ? 'rewritten' : 'skipped']++;
                }
            });

        ksort($byVersion);
        $this->components->twoColumnDetail('Clé actuelle', 'v'.$current);
        $this->components->twoColumnDetail('Jetons', (string) $stats['total']);
        foreach ($byVersion as $version => $count) {
            $this->components->twoColumnDetail("Valeurs chiffrées en v{$version}", (string) $count);
        }
        $this->components->twoColumnDetail('Déjà à la clé actuelle', (string) $stats['current']);
        $this->components->twoColumnDetail($dryRun ? 'À rechiffrer' : 'Rechiffrés', (string) ($dryRun ? $stats['total'] - $stats['current'] - $stats['unreadable'] : $stats['rewritten']));
        if ($stats['skipped'] > 0) {
            $this->components->twoColumnDetail('Modifiés pendant l\'opération (à relancer)', (string) $stats['skipped']);
        }
        $this->components->twoColumnDetail('Illisibles avec la configuration actuelle', (string) $stats['unreadable']);

        if ($stats['unreadable'] > 0) {
            $this->components->error('Des jetons sont illisibles : ne retirez AUCUNE ancienne clé (TOKEN_ENCRYPTION_PREVIOUS_KEYS).');

            return self::FAILURE;
        }

        return $stats['skipped'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<int> versions des valeurs non nulles de la ligne */
    private function versions(TokenEncrypter $encrypter, object $row): array
    {
        $versions = [];
        foreach (self::COLUMNS as $column) {
            if ($row->{$column} !== null) {
                $versions[] = $encrypter->versionOf((string) $row->{$column}) ?? 0;
            }
        }

        return array_values(array_unique($versions));
    }

    private function readable(TokenEncrypter $encrypter, object $row): bool
    {
        try {
            foreach (self::COLUMNS as $column) {
                if ($row->{$column} !== null) {
                    $encrypter->decrypt((string) $row->{$column});
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function rewrite(TokenEncrypter $encrypter, object $row): bool
    {
        try {
            return Cache::lock("social-token-refresh:{$row->social_account_id}", 30)->block(15, function () use ($encrypter, $row) {
                $update = ['encryption_key_version' => $encrypter->currentVersion()];
                $query = DB::table('social_tokens')->where('id', $row->id);
                foreach (self::COLUMNS as $column) {
                    $stored = $row->{$column};
                    // Garde optimiste : rien n'est écrit si un rafraîchissement a remplacé la valeur entre-temps.
                    $stored === null ? $query->whereNull($column) : $query->where($column, $stored);
                    $update[$column] = $stored === null ? null : $encrypter->encrypt($encrypter->decrypt((string) $stored));
                }

                return $query->update($update) === 1;
            });
        } catch (LockTimeoutException) {
            return false;
        }
    }
}
