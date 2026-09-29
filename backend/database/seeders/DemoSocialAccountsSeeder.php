<?php

namespace Database\Seeders;

use App\Models\SocialAccount;
use App\Models\SocialPlatform;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Comptes sociaux de DÉMONSTRATION pour tester le module Content en local (Composer, filtres,
 * calendrier, programmations) sans aucune connexion OAuth.
 *
 * - Développement local uniquement : refuse de s'exécuter hors de config('demo.allowed_environments').
 * - Rattachés à un compte déjà inscrit (DEMO_SEED_EMAIL) : aucun utilisateur ni mot de passe créé.
 * - Marqués metadata.demo = true / metadata.environment = "development", identifiants « demo-… ».
 * - Aucun token (table social_tokens intacte), aucun appel à une API sociale.
 * - Noms explicitement fictifs, sans nom d'utilisateur : impossibles à confondre avec un vrai compte.
 * - Idempotent : relancer le seeder met les comptes à jour sans doublon.
 *
 * Usage : DEMO_SEED_EMAIL=vous@exemple.test php artisan db:seed --class=DemoSocialAccountsSeeder
 */
class DemoSocialAccountsSeeder extends Seeder
{
    /** [plateforme, type de compte, statut, motif] — un compte expiré pour tester la reconnexion. */
    private const ACCOUNTS = [
        ['facebook', 'page', 'connected', null],
        ['instagram', 'business', 'connected', null],
        ['linkedin', 'organization', 'connected', null],
        ['x', 'profile', 'connected', null],
        ['youtube', 'channel', 'connected', null],
        ['pinterest', 'profile', 'connected', null],
        ['tiktok', 'creator', 'expired', 'Compte de démonstration : autorisation expirée (cas de test local).'],
    ];

    public function run(): void
    {
        $allowed = (array) config('demo.allowed_environments', []);
        if (! app()->environment($allowed)) {
            throw new RuntimeException('DemoSocialAccountsSeeder est réservé au développement local ('.implode(', ', $allowed).').');
        }

        $email = strtolower(trim((string) config('demo.seed_email')));
        if ($email === '') {
            throw new RuntimeException('Définissez DEMO_SEED_EMAIL avec l\'adresse d\'un compte déjà inscrit en local.');
        }
        $user = User::query()->where('email', $email)->first()
            ?? throw new RuntimeException("Aucun compte inscrit avec l'adresse {$email}. Inscrivez-vous d'abord depuis l'interface.");

        $this->call(SocialPlatformSeeder::class);
        $platforms = SocialPlatform::query()->pluck('id', 'key');

        foreach (self::ACCOUNTS as [$key, $type, $status, $reason]) {
            $account = SocialAccount::withTrashed()->firstOrNew([
                'user_id' => $user->getKey(),
                'social_platform_id' => $platforms[$key],
                'external_account_id' => "demo-{$key}",
            ]);
            $account->user_id = $user->getKey();
            $account->fill([
                'account_type' => $type,
                'display_name' => 'Compte de démonstration ('.$this->platformName($key).')',
                'username' => null,
                'avatar_media_path' => null,
                'metadata' => ['demo' => true, 'environment' => 'development', 'seeded_by' => self::class],
            ]);
            $account->status = $status;
            $account->status_reason = $reason;
            $account->connected_at ??= now();
            $account->last_synced_at = null;
            $account->deleted_at = null;
            $account->save();
        }

        $this->command?->info(count(self::ACCOUNTS)." comptes sociaux de démonstration prêts pour {$email} (aucun token, aucun appel externe).");
    }

    private function platformName(string $key): string
    {
        return SocialPlatform::query()->where('key', $key)->value('name') ?? $key;
    }
}
