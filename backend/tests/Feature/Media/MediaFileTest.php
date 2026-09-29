<?php

namespace Tests\Feature\Media;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Media\Concerns\MediaFixtures;
use Tests\TestCase;

/** Lecture des fichiers : URL signée courte, en-têtes de sécurité, quarantaine, purge. */
class MediaFileTest extends TestCase
{
    use MediaFixtures, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        $this->user = User::factory()->create();
    }

    /** @return array<string, mixed> */
    private function uploaded($file): array
    {
        return $this->storeMedia($this->user, $file)->assertCreated()->json('data');
    }

    private function relative(string $url): string
    {
        return (string) preg_replace('#^https?://[^/]+#', '', $url);
    }

    public function test_signed_url_serves_the_file_with_security_headers(): void
    {
        $media = $this->uploaded($this->png());

        $response = $this->get($this->relative($media['url']))->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));

        $download = $this->get($this->relative($media['download_url']))->assertOk();
        $this->assertStringStartsWith('attachment;', $download->headers->get('Content-Disposition'));
    }

    public function test_documents_are_always_downloaded(): void
    {
        $media = $this->uploaded($this->pdf());
        $response = $this->get($this->relative($media['url']))->assertOk();
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
    }

    public function test_unsigned_tampered_or_expired_urls_are_refused(): void
    {
        $media = $this->uploaded($this->png());
        $url = $this->relative($media['url']);

        $this->get(strtok($url, '?'))->assertForbidden();
        $this->get(str_replace('original', 'thumbnail', $url))->assertForbidden();
        $this->get((string) preg_replace('/signature=[0-9a-f]+/', 'signature=0000', $url))->assertForbidden();

        $this->travel((int) config('media.url_ttl_minutes') + 1)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_deleted_or_quarantined_media_are_never_served(): void
    {
        $media = $this->uploaded($this->png());
        $url = $this->relative($media['url']);

        config(['media.scan.required' => true]);
        $this->get($url)->assertNotFound();
        config(['media.scan.required' => false]);
        $this->get($url)->assertOk();

        $this->actingAs($this->user)->fromSpa()->deleteJson("/api/v1/media/{$media['id']}")->assertNoContent();
        $this->get($url)->assertNotFound();
    }

    public function test_missing_variant_returns_404(): void
    {
        $media = $this->uploaded($this->mp4());
        $this->assertNull($media['thumbnail_url']);

        $signed = \URL::temporarySignedRoute('api.v1.media.file', now()->addMinutes(5), ['media' => $media['id'], 'variant' => 'thumbnail']);
        $this->get($this->relative($signed))->assertNotFound();
    }

    public function test_thumbnail_is_served_as_webp(): void
    {
        $this->requiresGd();
        $media = $this->uploaded($this->jpg());
        $this->get($this->relative($media['thumbnail_url']))->assertOk()->assertHeader('Content-Type', 'image/webp');
    }

    public function test_purge_removes_old_deleted_files_only(): void
    {
        $old = $this->uploaded($this->png('ancien.png'));
        $recent = $this->uploaded($this->jpg('recent.jpg'));
        $kept = $this->uploaded($this->gif('garde.gif'));
        $api = fn () => $this->actingAs($this->user)->fromSpa();
        $api()->deleteJson("/api/v1/media/{$old['id']}")->assertNoContent();
        $api()->deleteJson("/api/v1/media/{$recent['id']}")->assertNoContent();
        Media::withTrashed()->whereKey($old['id'])->update(['deleted_at' => now()->subDays(40)]);

        $this->artisan('media:purge-deleted')->assertSuccessful();

        $this->assertDatabaseMissing('media', ['id' => $old['id']]);
        $this->assertSame([], Storage::disk('media')->allFiles("users/{$this->user->id}/media/{$old['id']}"));
        $this->assertSoftDeleted('media', ['id' => $recent['id']]);
        Storage::disk('media')->assertExists(Media::withTrashed()->findOrFail($recent['id'])->path);
        Storage::disk('media')->assertExists(Media::findOrFail($kept['id'])->path);
    }
}
