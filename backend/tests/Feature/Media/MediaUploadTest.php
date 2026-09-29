<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Actions\ProcessMedia;
use App\Domain\Media\Security\MediaSecurityScanner;
use App\Domain\Media\Security\ScanResult;
use App\Domain\Users\Enums\UserStatus;
use App\Jobs\ProcessMediaJob;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Media\Concerns\MediaFixtures;
use Tests\TestCase;

/** Import sécurisé (docs/security.md §7) : contrôles réels, stockage privé, quarantaine, erreurs. */
class MediaUploadTest extends TestCase
{
    use MediaFixtures, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        $this->user = User::factory()->create();
    }

    private function assertNothingStored(): void
    {
        $this->assertSame([], Storage::disk('media')->allFiles());
        $this->assertDatabaseCount('media', 0);
    }

    // --- Formats acceptés -------------------------------------------------------------------

    public function test_image_upload_is_stored_privately_under_a_generated_key(): void
    {
        $response = $this->storeMedia($this->user, $this->png('Mon Logo.png'))->assertCreated()
            ->assertJsonPath('message', 'Média importé.')
            ->assertJsonPath('data.type', 'image')
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.name', 'Mon Logo.png')
            ->assertJsonPath('data.mime_type', 'image/png')
            ->assertJsonPath('data.width', 4)
            ->assertJsonPath('data.height', 3)
            ->assertJsonPath('data.scan_status', 'pending') // aucun antivirus : jamais présenté comme « clean »
            ->assertJsonPath('data.usable', true)
            ->assertJsonMissingPath('data.path')
            ->assertJsonMissingPath('data.disk');

        $id = $response->json('data.id');
        $media = Media::findOrFail($id);
        $this->assertSame("users/{$this->user->id}/media/{$id}/original.png", $media->path);
        Storage::disk('media')->assertExists($media->path);
        $this->assertSame(hash('sha256', base64_decode(self::PNG)), $media->checksum_sha256);
        $this->assertStringContainsString("/api/v1/media/{$id}/file/original", $response->json('data.url'));
        $this->assertStringContainsString('signature=', $response->json('data.url'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'MEDIA_UPLOADED', 'subject_id' => $id]);
    }

    public function test_all_supported_formats_are_accepted_with_their_detected_type(): void
    {
        $cases = [
            [$this->jpg(), 'image', 'image/jpeg', 'jpg'],
            [$this->gif(), 'image', 'image/gif', 'gif'],
            [$this->webp(), 'image', 'image/webp', 'webp'],
            [$this->mp4(), 'video', 'video/mp4', 'mp4'],
            [$this->mp3(), 'audio', 'audio/mpeg', 'mp3'],
            [$this->wav(), 'audio', 'audio/x-wav', 'wav'],
            [$this->pdf(), 'document', 'application/pdf', 'pdf'],
        ];
        foreach ($cases as [$file, $type, $mime, $ext]) {
            $this->storeMedia($this->user, $file)->assertCreated()
                ->assertJsonPath('data.type', $type)
                ->assertJsonPath('data.mime_type', $mime)
                ->assertJsonPath('data.extension', $ext);
        }
        $this->assertDatabaseCount('media', count($cases));
    }

    public function test_video_has_no_fake_thumbnail_without_ffmpeg(): void
    {
        $id = $this->storeMedia($this->user, $this->mp4())->assertCreated()
            ->assertJsonPath('data.thumbnail_url', null)
            ->assertJsonPath('data.duration_ms', null)
            ->json('data.id');

        $this->assertSame('unavailable', Media::findOrFail($id)->metadata['video_processing']);
    }

    // --- Traitement d'image (GD) --------------------------------------------------------------

    public function test_images_get_a_thumbnail_and_are_reencoded(): void
    {
        $this->requiresGd();
        $response = $this->storeMedia($this->user, $this->png())->assertCreated();
        $media = Media::findOrFail($response->json('data.id'));

        $this->assertTrue($media->metadata['reencoded']);
        $thumb = $media->derivatives['thumbnail']['path'];
        $this->assertSame("users/{$this->user->id}/media/{$media->id}/thumbnail.webp", $thumb);
        Storage::disk('media')->assertExists($thumb);
        $this->assertStringContainsString('/file/thumbnail', $response->json('data.thumbnail_url'));
    }

    public function test_gps_exif_metadata_is_removed_from_jpeg(): void
    {
        $this->requiresGd();
        $id = $this->storeMedia($this->user, $this->jpgWithGps())->assertCreated()->json('data.id');

        $stored = Storage::disk('media')->get(Media::findOrFail($id)->path);
        $this->assertStringNotContainsString('SECRET-LOCATION', $stored);
        $this->assertStringNotContainsString('GPSLatitude', $stored);
    }

    public function test_gif_keeps_its_original_bytes(): void
    {
        $this->requiresGd();
        $id = $this->storeMedia($this->user, $this->gif())->assertCreated()->assertJsonPath('data.is_gif', true)->json('data.id');
        $media = Media::findOrFail($id);

        $this->assertSame(base64_decode(self::GIF), Storage::disk('media')->get($media->path));
        $this->assertFalse($media->metadata['reencoded']);
        $this->assertNotNull($media->derivatives['thumbnail'] ?? null);
    }

    // --- Fichiers refusés ----------------------------------------------------------------------

    public function test_dangerous_or_unsupported_files_are_rejected(): void
    {
        $files = [
            $this->upload('logo.svg', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            $this->upload('page.html', '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>'),
            $this->upload('photo.jpg', '<!DOCTYPE html><html><body>déguisé</body></html>'), // HTML déguisé en image
            $this->upload('image.png', "<?php system(\$_GET['c']);"),                         // PHP déguisé en image
            $this->upload('setup.exe', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF".str_repeat("\x00", 100)),
            $this->upload('notes.txt', 'Texte simple'),
        ];
        foreach ($files as $file) {
            $this->storeMedia($this->user, $file)->assertUnprocessable()
                ->assertJsonValidationErrors(['file'])
                ->assertJsonPath('meta.reason', 'unsupported_format');
        }
        $this->assertNothingStored();
        $this->assertDatabaseHas('audit_logs', ['action' => 'MEDIA_REJECTED']);
    }

    public function test_extension_must_match_the_real_content(): void
    {
        $this->storeMedia($this->user, $this->upload('photo.jpg', base64_decode(self::PNG)))
            ->assertUnprocessable()->assertJsonPath('meta.reason', 'extension_mismatch');
        $this->storeMedia($this->user, $this->upload('video.mp4.png', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64)))
            ->assertUnprocessable()->assertJsonPath('meta.reason', 'extension_mismatch');
        $this->assertNothingStored();
    }

    public function test_empty_oversized_and_giant_images_are_rejected(): void
    {
        $this->storeMedia($this->user, $this->upload('vide.png', ''))->assertUnprocessable();

        config(['media.max_size_mb.image' => 0]);
        $this->storeMedia($this->user, $this->png())->assertUnprocessable()->assertJsonPath('meta.reason', 'too_large');

        config(['media.max_size_mb.image' => 20, 'media.image.max_side_px' => 3]);
        $this->storeMedia($this->user, $this->png())->assertUnprocessable()->assertJsonPath('meta.reason', 'dimensions');

        $this->assertNothingStored();
    }

    public function test_corrupted_image_is_rejected_and_nothing_is_left_behind(): void
    {
        $this->requiresGd();
        // En-tête PNG valide (dimensions lisibles) mais données d'image corrompues.
        $png = base64_decode(self::PNG);
        $broken = substr($png, 0, 33).str_repeat("\xAB", 40);
        $this->storeMedia($this->user, $this->upload('casse.png', $broken))->assertUnprocessable()->assertJsonValidationErrors(['file']);

        $this->assertNothingStored();
    }

    public function test_malicious_file_names_never_reach_the_storage_path(): void
    {
        $id = $this->storeMedia($this->user, $this->png("../../../etc/\u{202E}gnp.exe\x00.png"))->assertCreated()->json('data.id');
        $media = Media::findOrFail($id);

        $this->assertSame("users/{$this->user->id}/media/{$id}/original.png", $media->path);
        $this->assertStringNotContainsString('..', $media->original_name);
        $this->assertStringNotContainsString("\u{202E}", $media->original_name);
        $this->assertStringNotContainsString('/', $media->original_name);
        $this->assertStringEndsWith('.png', $media->original_name);
    }

    // --- Antivirus et quarantaine --------------------------------------------------------------

    public function test_upload_is_refused_when_a_scan_is_required_but_no_engine_is_available(): void
    {
        config(['media.scan.required' => true]);

        $this->storeMedia($this->user, $this->png())->assertStatus(503)->assertJsonStructure(['message', 'errors']);
        $this->assertNothingStored();
    }

    public function test_infected_file_is_rejected_audited_and_not_stored(): void
    {
        $this->app->instance(MediaSecurityScanner::class, new class implements MediaSecurityScanner
        {
            public function isAvailable(): bool
            {
                return true;
            }

            public function scan(string $localPath): ScanResult
            {
                return ScanResult::infected('Eicar-Test-Signature');
            }
        });

        $this->storeMedia($this->user, $this->png())->assertUnprocessable()->assertJsonPath('meta.reason', 'infected');
        $this->assertNothingStored();
        $this->assertDatabaseHas('audit_logs', ['action' => 'MEDIA_REJECTED', 'user_id' => $this->user->id]);
    }

    public function test_clean_scan_result_is_recorded(): void
    {
        $this->app->instance(MediaSecurityScanner::class, new class implements MediaSecurityScanner
        {
            public function isAvailable(): bool
            {
                return true;
            }

            public function scan(string $localPath): ScanResult
            {
                return ScanResult::clean();
            }
        });

        $id = $this->storeMedia($this->user, $this->png())->assertCreated()->assertJsonPath('data.scan_status', 'clean')->json('data.id');
        $this->assertNotNull(Media::findOrFail($id)->scanned_at);
    }

    // --- Traitement asynchrone -----------------------------------------------------------------

    public function test_async_processing_dispatches_a_job_on_the_media_queue(): void
    {
        Queue::fake();
        config(['media.process_async' => true]);

        $id = $this->storeMedia($this->user, $this->png())->assertCreated()
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.usable', false)
            ->assertJsonPath('data.url', null)
            ->json('data.id');

        Queue::assertPushedOn('media', ProcessMediaJob::class, fn (ProcessMediaJob $job) => $job->mediaId === $id);

        (new ProcessMediaJob($id))->handle(app(ProcessMedia::class));
        $this->assertSame('ready', Media::findOrFail($id)->status->value);
    }

    // --- Dossier, tags, doublons ---------------------------------------------------------------

    public function test_upload_into_a_folder_with_normalized_tags_and_duplicate_notice(): void
    {
        $folder = MediaFolder::factory()->for($this->user)->create(['name' => 'Marque']);

        $this->storeMedia($this->user, $this->png(), ['folder_id' => $folder->id, 'tags' => ['  #Logo ', 'MARQUE']])
            ->assertCreated()
            ->assertJsonPath('data.folder_id', $folder->id)
            ->assertJsonPath('data.tags', ['logo', 'marque'])
            ->assertJsonPath('meta.duplicates', 0);

        // Même contenu : importé quand même (jamais supprimé), l'API signale le doublon.
        $this->storeMedia($this->user, $this->png('copie.png'))->assertCreated()->assertJsonPath('meta.duplicates', 1);
        $this->assertDatabaseCount('media', 2);
    }

    public function test_cannot_upload_into_another_users_folder(): void
    {
        $other = MediaFolder::factory()->create(['name' => 'Privé']);

        $this->storeMedia($this->user, $this->png(), ['folder_id' => $other->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['folder_id']);
        $this->assertNothingStored();
    }

    public function test_invalid_tags_and_missing_file_are_rejected(): void
    {
        $this->storeMedia($this->user, $this->png(), ['tags' => ['<script>']])->assertUnprocessable()->assertJsonValidationErrors(['tags.0']);
        $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/media', [])->assertUnprocessable()->assertJsonValidationErrors(['file']);
        $this->assertNothingStored();
    }

    // --- Codes HTTP ----------------------------------------------------------------------------

    public function test_http_codes_for_guests_unverified_and_suspended_users(): void
    {
        $this->fromSpa()->post('/api/v1/media', ['file' => $this->png()], ['Accept' => 'application/json'])->assertUnauthorized();
        $this->storeMedia(User::factory()->unverified()->create(), $this->png())->assertForbidden();

        $this->user->forceFill(['status' => UserStatus::Suspended])->save();
        $this->storeMedia($this->user, $this->png())->assertForbidden();

        $this->assertNothingStored();
    }
}
