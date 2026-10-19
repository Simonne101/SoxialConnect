<?php

namespace App\Console\Commands;

use App\Domain\Video\Sources\YtDlp\YtDlpBinary;
use App\Domain\Video\Sources\YtDlp\YtDlpVideoSourceProvider;
use Illuminate\Console\Command;

/**
 * Vérification de l'installation yt-dlp (ADR-059) : exécutable présent et opérationnel (version lue),
 * voie activée ou non. Aucun accès réseau : rien n'est téléchargé.
 */
class CheckYtDlp extends Command
{
    protected $signature = 'clipper:ytdlp-check';

    protected $description = 'Vérifie l\'installation de yt-dlp (exécutable, version, activation) sans accès réseau';

    public function handle(YtDlpBinary $binary, YtDlpVideoSourceProvider $provider): int
    {
        $binary->forget();
        $binary->verify();
        $version = $binary->version();
        $enabled = (bool) config('video.sources.yt_dlp.enabled', false);

        $this->components->twoColumnDetail('Exécutable', is_file($binary->path()) ? 'présent' : 'introuvable');
        $this->components->twoColumnDetail('État', match ($binary->state()) {
            YtDlpBinary::OK => 'opérationnel',
            YtDlpBinary::UNVERIFIED => 'démarrage trop lent pour être vérifié (machine chargée ?)',
            default => 'inutilisable',
        });
        $this->components->twoColumnDetail('Version', $version ?? '—');
        $this->components->twoColumnDetail('Activation (CLIPPER_YTDLP_ENABLED)', $enabled ? 'activé' : 'désactivé');
        $this->components->twoColumnDetail('Voie d\'acquisition disponible', $provider->isConfigured() ? 'oui' : 'non');

        if (! $binary->isAvailable()) {
            $this->components->error('yt-dlp est absent ou inutilisable : vérifiez YT_DLP_BINARY.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
