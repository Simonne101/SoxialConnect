<?php

namespace Tests\Feature\Security;

use App\Domain\Social\Security\TokenEncrypter;
use App\Models\SocialAccount;
use App\Models\SocialToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Rotation contrôlée de TOKEN_ENCRYPTION_KEY (docs/secrets-remediation.md) : compatibilité temporaire par
 * TOKEN_ENCRYPTION_PREVIOUS_KEYS, vérification sans modification (--dry-run), rechiffrement, retrait de l'ancienne clé,
 * retour arrière ; aucune valeur jamais affichée. Clés de TEST uniquement (aucune clé réelle).
 */
class TokenRotationTest extends TestCase
{
    use RefreshDatabase;

    private const OLD = 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE='; // 32 × « a »

    private const NEW = 'base64:YmJiYmJiYmJiYmJiYmJiYmJiYmJiYmJiYmJiYmJiYmI='; // 32 × « b »

    private function useKeys(string $key, int $version, string $previous = ''): void
    {
        config(['security.token_encryption' => ['key' => $key, 'version' => $version, 'previous_keys' => $previous, 'cipher' => 'aes-256-gcm']]);
        $this->app->forgetInstance(TokenEncrypter::class);
    }

    /** @return list<int> */
    private function seedTokens(): array
    {
        $this->useKeys(self::OLD, 1);
        $ids = [];
        foreach ([['acces-a', 'rafraichissement-a'], ['acces-b', null]] as [$access, $refresh]) {
            $ids[] = SocialToken::create([
                'social_account_id' => SocialAccount::factory()->create()->id, 'token_kind' => 'user',
                'access_token_encrypted' => $access, 'refresh_token_encrypted' => $refresh, 'status' => 'active',
            ])->id;
        }

        return $ids;
    }

    private function reencrypt(array $options = []): array
    {
        $code = Artisan::call('security:reencrypt-tokens', $options);

        return [$code, Artisan::output()];
    }

    private function assertNoValueIn(string $output): void
    {
        foreach (['acces-a', 'acces-b', 'rafraichissement-a', 'YWFh', 'YmJi'] as $value) {
            $this->assertStringNotContainsString($value, $output);
        }
        $this->assertDoesNotMatchRegularExpression('/v\d:eyJ/', $output, 'Aucune valeur chiffrée affichée.');
    }

    public function test_full_rotation_with_temporary_compatibility_then_old_key_removal(): void
    {
        $ids = $this->seedTokens();
        $before = DB::table('social_tokens')->orderBy('id')->pluck('access_token_encrypted')->all();

        // Nouvelle clé + ancienne en compatibilité : tout reste lisible, rien n'est encore modifié.
        $this->useKeys(self::NEW, 2, '1:'.self::OLD);
        $this->assertSame('acces-a', SocialToken::find($ids[0])->access_token_encrypted);
        [$code, $output] = $this->reencrypt(['--dry-run' => true]);
        $this->assertSame(0, $code);
        $this->assertMatchesRegularExpression('/À rechiffrer\W+2/u', $output);
        $this->assertSame($before, DB::table('social_tokens')->orderBy('id')->pluck('access_token_encrypted')->all(), '--dry-run ne modifie rien.');
        $this->assertNoValueIn($output);

        // Rechiffrement : valeurs identiques en clair, désormais en v2.
        [$code, $output] = $this->reencrypt();
        $this->assertSame(0, $code);
        $this->assertNoValueIn($output);
        foreach (DB::table('social_tokens')->get() as $row) {
            $this->assertSame(2, (int) $row->encryption_key_version);
            $this->assertStringStartsWith('v2:', $row->access_token_encrypted);
            $this->assertTrue($row->refresh_token_encrypted === null || str_starts_with($row->refresh_token_encrypted, 'v2:'));
        }

        // L'ancienne clé peut alors être retirée : tout reste lisible.
        $this->useKeys(self::NEW, 2);
        [$code] = $this->reencrypt(['--dry-run' => true]);
        $this->assertSame(0, $code);
        $token = SocialToken::find($ids[0]);
        $this->assertSame(['acces-a', 'rafraichissement-a'], [$token->access_token_encrypted, $token->refresh_token_encrypted]);
        $this->assertNull(SocialToken::find($ids[1])->refresh_token_encrypted);
    }

    public function test_replacing_the_key_without_compatibility_is_detected_and_nothing_is_touched(): void
    {
        $this->seedTokens();
        $before = DB::table('social_tokens')->orderBy('id')->get()->toArray();

        $this->useKeys(self::NEW, 2); // ancienne clé oubliée
        [$code, $output] = $this->reencrypt();

        $this->assertSame(1, $code, 'Échec explicite : des jetons seraient perdus.');
        $this->assertMatchesRegularExpression('/Illisibles avec la configuration actuelle\W+2/u', $output);
        $this->assertEquals($before, DB::table('social_tokens')->orderBy('id')->get()->toArray(), 'Aucune ligne modifiée.');
        $this->assertNoValueIn($output);
    }

    public function test_a_token_being_refreshed_is_left_untouched_and_the_command_can_be_rerun(): void
    {
        $ids = $this->seedTokens();
        $this->useKeys(self::NEW, 2, '1:'.self::OLD);
        $account = SocialToken::find($ids[0])->social_account_id;
        $before = DB::table('social_tokens')->where('id', $ids[0])->value('access_token_encrypted');

        // Un rafraîchissement du jeton est en cours (même verrou que les gestionnaires de jetons) : attente réelle
        // de 15 s, simulée, puis abandon de CETTE ligne seulement.
        Sleep::fake(syncWithCarbon: true);
        $lock = Cache::lock("social-token-refresh:{$account}", 30);
        $this->assertTrue($lock->get());
        [$code, $output] = $this->reencrypt();

        $this->assertSame(1, $code, 'Échec signalé : une ligne reste à rechiffrer.');
        $this->assertMatchesRegularExpression('/Modifiés pendant l\'opération \(à relancer\)\W+1/u', $output);
        $this->assertSame($before, DB::table('social_tokens')->where('id', $ids[0])->value('access_token_encrypted'), 'Ligne verrouillée intacte.');
        $this->assertStringStartsWith('v2:', DB::table('social_tokens')->where('id', $ids[1])->value('access_token_encrypted'));

        // Relance après le rafraîchissement : idempotente, seule la ligne restante est traitée.
        $lock->release();
        [$code, $output] = $this->reencrypt();
        $this->assertSame(0, $code);
        $this->assertMatchesRegularExpression('/Déjà à la clé actuelle\W+1/u', $output);
        $this->assertSame('acces-a', SocialToken::find($ids[0])->access_token_encrypted);
    }

    public function test_rollback_keeps_rotated_tokens_readable_when_both_keys_are_kept(): void
    {
        $ids = $this->seedTokens();
        $this->useKeys(self::NEW, 2, '1:'.self::OLD);
        $this->reencrypt();

        // Retour arrière : l'ancienne clé redevient la clé actuelle, la nouvelle reste en compatibilité.
        $this->useKeys(self::OLD, 1, '2:'.self::NEW);
        [$code] = $this->reencrypt(['--dry-run' => true]);
        $this->assertSame(0, $code);
        $this->assertSame('acces-b', SocialToken::find($ids[1])->access_token_encrypted);
    }
}
