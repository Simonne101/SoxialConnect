<?php

namespace Tests\Feature\Security;

use App\Domain\Media\Security\ClamAvScanner;
use App\Domain\Media\Security\MediaSecurityScanner;
use App\Domain\Media\Storage\MediaStorage;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Media\Concerns\MediaFixtures;
use Tests\TestCase;

/**
 * Antivirus ClamAV (étape 8, lot 6) : protocole clamd réel contre un faux démon (tests/Support/fake-clamd.php),
 * échec fermé dans tous les cas douteux, refus et non-stockage des fichiers infectés, mise en service sur les
 * médias existants. Le test EICAR contre un vrai clamd (groupe « clamav ») ne s'exécute que si CLAMAV_TEST_HOST
 * désigne un démon joignable (CI, conteneur Docker) — il est sinon signalé comme non exécuté.
 *
 * Le fichier de test EICAR n'apparaît dans aucun fichier du dépôt (l'antivirus d'un poste de développement mettrait
 * ce fichier en quarantaine) : il est reconstitué en mémoire, et n'est écrit sur disque que pour le vrai clamd.
 */
class ClamAvScannerTest extends TestCase
{
    use MediaFixtures, RefreshDatabase;

    /** Chaîne EICAR inversée, en hexadécimal. */
    private const EICAR_REVERSED_HEX = '2a482b482421454c49462d545345542d535552495649544e412d445241444e4154532d5241434945247d3729434337295e50283435585a505c345b5041402550214f3558';

    /** @var list<resource> */
    private array $processes = [];

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            proc_terminate($process);
            proc_close($process);
        }
        parent::tearDown();
    }

    private static function eicar(): string
    {
        return strrev((string) hex2bin(self::EICAR_REVERSED_HEX));
    }

    private static function freePort(): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        return $port;
    }

    /** Lance le faux démon et renvoie son port. */
    private function fakeClamd(string $mode = 'normal'): int
    {
        $port = self::freePort();
        $process = proc_open([PHP_BINARY, base_path('tests/Support/fake-clamd.php'), (string) $port, $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $this->processes[] = $process;
        $this->assertSame('READY', trim((string) fgets($pipes[1])), 'Faux démon clamd démarré.');

        return $port;
    }

    private function scanner(int $port): ClamAvScanner
    {
        return new ClamAvScanner("tcp://127.0.0.1:{$port}", 2.0, 30.0);
    }

    /** Contenu passé sans écrire de fichier sur le disque. */
    private function inMemory(string $bytes): string
    {
        return 'data://application/octet-stream;base64,'.base64_encode($bytes);
    }

    private function useClamAv(int $port): void
    {
        config(['media.scan.driver' => 'clamav', 'media.scan.clamav.host' => '127.0.0.1', 'media.scan.clamav.port' => $port, 'media.scan.clamav.socket' => null]);
        $this->app->forgetInstance(MediaSecurityScanner::class);
    }

    public function test_the_engine_is_detected_through_ping_and_an_unreachable_daemon_is_unavailable(): void
    {
        $this->assertTrue($this->scanner($this->fakeClamd())->isAvailable());

        $dead = $this->scanner(self::freePort());
        $this->assertFalse($dead->isAvailable());
        $this->assertSame('error', $dead->scan($this->inMemory('x'))->status->value, 'Démon injoignable : jamais « sain ».');
    }

    public function test_clean_content_is_clean_and_eicar_is_infected(): void
    {
        $scanner = $this->scanner($this->fakeClamd());

        $this->assertSame('clean', $scanner->scan($this->inMemory(str_repeat('a', 3 * 1024 * 1024)))->status->value, 'Plusieurs morceaux INSTREAM.');
        $result = $scanner->scan($this->inMemory(self::eicar()));
        $this->assertSame('infected', $result->status->value);
        $this->assertSame('Eicar-Test-Signature', $result->signature);
    }

    public function test_size_limits_unexpected_replies_and_unreadable_files_fail_closed(): void
    {
        $this->assertSame('error', $this->scanner($this->fakeClamd('limit'))->scan($this->inMemory(str_repeat('a', 1000)))->status->value, 'StreamMaxLength dépassé.');
        $this->assertSame('error', $this->scanner($this->fakeClamd('garbage'))->scan($this->inMemory('a'))->status->value, 'Réponse incompréhensible.');
        $this->assertSame('error', $this->scanner($this->fakeClamd())->scan(storage_path('framework/testing/absent-'.uniqid().'.bin'))->status->value);
    }

    public function test_uploads_are_scanned_before_being_stored_and_infected_files_are_refused(): void
    {
        Storage::fake('media');
        $this->useClamAv($this->fakeClamd());
        config(['media.scan.required' => true]);
        $user = User::factory()->create();

        $this->storeMedia($user, $this->upload('piege.png', base64_decode(self::PNG).'SOXIALCONNECT-SCANNER-TEST-MARKER'))
            ->assertUnprocessable()->assertJsonPath('meta.reason', 'infected');
        $this->assertSame([], Storage::disk('media')->allFiles(), 'Fichier infecté : rien n\'est stocké.');
        $this->assertDatabaseCount('media', 0);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'MEDIA_REJECTED']);

        $this->storeMedia($user, $this->png())->assertCreated()->assertJsonPath('data.scan_status', 'clean')->assertJsonPath('data.usable', true);
    }

    public function test_a_required_scan_with_an_unreachable_daemon_refuses_every_upload(): void
    {
        Storage::fake('media');
        $this->useClamAv(self::freePort());
        config(['media.scan.required' => true]);

        $this->storeMedia(User::factory()->create(), $this->png())->assertStatus(503);
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_existing_unscanned_media_are_scanned_when_the_engine_is_switched_on_and_nothing_is_deleted(): void
    {
        Storage::fake('media');
        $user = User::factory()->create();
        $storage = app(MediaStorage::class);
        $make = function (string $bytes) use ($user, $storage): Media {
            $media = Media::factory()->for($user)->create(['scan_status' => 'pending']);
            $media->forceFill(['path' => $storage->pathFor($user->id, $media->id, MediaStorage::ORIGINAL, 'png')])->save();
            Storage::disk('media')->put($media->path, $bytes);

            return $media;
        };
        $clean = $make(base64_decode(self::PNG));
        $infected = $make(base64_decode(self::PNG).'SOXIALCONNECT-SCANNER-TEST-MARKER');

        $this->artisan('media:scan-pending')->assertFailed(); // aucun moteur : rien n'est changé
        $this->assertSame('pending', $clean->fresh()->scan_status->value);

        $this->useClamAv($this->fakeClamd());
        $this->artisan('media:scan-pending --dry-run')->expectsOutputToContain('Médias non analysés : 2.')->assertSuccessful();
        $this->artisan('media:scan-pending')->assertSuccessful();

        $this->assertSame('clean', $clean->fresh()->scan_status->value);
        $this->assertSame('infected', $infected->fresh()->scan_status->value);
        $this->assertNotNull($infected->fresh()->scanned_at);
        Storage::disk('media')->assertExists($infected->path); // conservé pour examen, mais plus jamais servi
    }

    #[Group('clamav')]
    public function test_a_real_clamd_detects_the_eicar_test_file(): void
    {
        $host = (string) env('CLAMAV_TEST_HOST', '');
        if ($host === '') {
            $this->markTestSkipped('Aucun démon ClamAV de test (CLAMAV_TEST_HOST) : test EICAR réel non exécuté.');
        }
        $scanner = new ClamAvScanner(sprintf('tcp://%s:%d', $host, (int) env('CLAMAV_TEST_PORT', 3310)), 5.0, 60.0);
        $this->assertTrue($scanner->isAvailable(), 'Démon ClamAV de test joignable.');

        $file = tempnam(sys_get_temp_dir(), 'eicar-');
        file_put_contents($file, self::eicar());
        try {
            $result = $scanner->scan($file);
        } finally {
            @unlink($file);
        }

        $this->assertSame('infected', $result->status->value);
        $this->assertStringContainsStringIgnoringCase('eicar', (string) $result->signature);
        $this->assertSame('clean', $scanner->scan($this->inMemory('fichier ordinaire'))->status->value);
    }
}
