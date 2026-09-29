<?php

namespace Tests\Feature\Security;

use App\Domain\Social\Security\TokenEncrypter;
use App\Models\SocialAccount;
use App\Models\SocialToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Tokens OAuth chiffrés au repos avec une clé dédiée et versionnée (ADR-008). */
class TokenEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_tokens_are_encrypted_at_rest_and_hidden_from_serialization(): void
    {
        $account = SocialAccount::factory()->create();
        $token = SocialToken::create([
            'social_account_id' => $account->id,
            'token_kind' => 'page',
            'access_token_encrypted' => 'EAAB-plain-access-token',
            'refresh_token_encrypted' => 'plain-refresh-token',
            'status' => 'active',
        ]);

        $raw = DB::table('social_tokens')->where('id', $token->id)->first();
        $this->assertStringStartsWith('v1:', $raw->access_token_encrypted);
        $this->assertStringNotContainsString('EAAB-plain-access-token', $raw->access_token_encrypted);
        $this->assertSame(1, (int) $raw->encryption_key_version);

        $fresh = SocialToken::find($token->id);
        $this->assertSame('EAAB-plain-access-token', $fresh->access_token_encrypted);
        $this->assertSame('plain-refresh-token', $fresh->refresh_token_encrypted);

        $this->assertStringNotContainsString('plain', $fresh->toJson());
        $this->assertStringNotContainsString('plain', (string) $fresh);
        $this->assertStringNotContainsString('plain', print_r($fresh, true));
    }

    public function test_key_rotation_keeps_old_tokens_readable(): void
    {
        $oldKey = 'base64:'.base64_encode(str_repeat('a', 32));
        $newKey = 'base64:'.base64_encode(str_repeat('b', 32));

        $v1 = TokenEncrypter::fromConfig(['key' => $oldKey, 'version' => 1]);
        $stored = $v1->encrypt('ancien-token');

        $v2 = TokenEncrypter::fromConfig(['key' => $newKey, 'version' => 2, 'previous_keys' => '1:'.$oldKey]);
        $this->assertSame('ancien-token', $v2->decrypt($stored));
        $this->assertSame(1, $v2->versionOf($stored));
        $this->assertStringStartsWith('v2:', $v2->encrypt('nouveau'));
    }

    public function test_token_key_is_distinct_from_app_key(): void
    {
        $this->assertNotSame(config('app.key'), config('security.token_encryption.key'));
    }
}
