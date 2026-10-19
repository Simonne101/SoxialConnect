<?php

namespace Tests\Feature\Security;

use App\Domain\Video\Rendering\FfmpegBinary;
use App\Domain\Video\Rendering\MediaProbe;
use App\Domain\Video\Rendering\RenderFailure;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Défense en profondeur FFmpeg (étape 8, lot 7) : chaque exécution de FFmpeg ou FFprobe limite les protocoles
 * d'entrée à « file ». Une liste de lecture piégée (HLS) qui pointe vers le réseau ne peut donc jamais servir de
 * SSRF, même si un fichier malveillant franchissait les contrôles d'import.
 */
class FfmpegProtocolWhitelistTest extends TestCase
{
    public function test_every_ffmpeg_and_ffprobe_invocation_restricts_input_protocols_to_files(): void
    {
        $invocations = 0;
        foreach (File::allFiles(app_path()) as $file) {
            $source = $file->getContents();
            // Chaque tableau d'arguments qui commence par le binaire FFmpeg/FFprobe (Process ou constructeur de commande).
            preg_match_all('/\[\s*\$this->(?:binaries|ffmpeg)->(?:ffmpegPath|ffprobePath)\(\)[^\]]*\]/s', $source, $matches);
            foreach ($matches[0] as $arguments) {
                $invocations++;
                if (str_contains($arguments, "'-i'") || str_contains($arguments, 'ffprobePath')) {
                    $this->assertStringContainsString("'-protocol_whitelist', 'file'", $arguments, $file->getRelativePathname().' : '.mb_substr($arguments, 0, 160));
                }
            }
        }
        $this->assertGreaterThanOrEqual(4, $invocations, 'Les appels FFmpeg/FFprobe ont bien été trouvés.');
    }

    #[Group('ffmpeg')]
    public function test_a_playlist_pointing_to_the_network_is_never_fetched(): void
    {
        if (! app(FfmpegBinary::class)->isProbeAvailable()) {
            $this->markTestSkipped('FFprobe introuvable (VIDEO_FFPROBE_BINARY) : vérification réelle non exécutée.');
        }
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($listener, false), ':'), 1);
        stream_set_blocking($listener, false);
        $dir = storage_path('framework/testing/ffprobe-ssrf');
        File::ensureDirectoryExists($dir);
        $playlist = $dir.'/piege.m3u8';
        file_put_contents($playlist, "#EXTM3U\n#EXT-X-TARGETDURATION:1\n#EXTINF:1,\nhttp://127.0.0.1:{$port}/segment.ts\n#EXT-X-ENDLIST\n");

        try {
            app(MediaProbe::class)->probe($playlist);
            $this->fail('La liste de lecture piégée aurait dû être refusée.');
        } catch (RenderFailure $e) {
            $this->assertSame('PROBE_FAILED', $e->errorCode);
        } finally {
            File::deleteDirectory($dir);
        }

        $this->assertFalse(@stream_socket_accept($listener, 0), 'Aucune connexion réseau tentée par FFprobe.');
        fclose($listener);
    }
}
