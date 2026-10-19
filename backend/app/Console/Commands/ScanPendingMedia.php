<?php

namespace App\Console\Commands;

use App\Domain\Media\Enums\ScanStatus;
use App\Domain\Media\Security\MediaSecurityScanner;
use App\Domain\Media\Storage\MediaStorage;
use App\Models\Media;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mise en service de l'antivirus (étape 8, lot 6) : les médias importés quand aucun moteur n'existait restent
 * « non analysés » (pending) et, dès qu'un moteur est configuré, en quarantaine (ScanPolicy). Cette commande les
 * analyse et enregistre le résultat. Elle ne supprime jamais rien : un média infecté reste stocké, marqué
 * « infected », donc ni servi ni publiable, pour examen.
 */
#[Signature('media:scan-pending {--limit=200 : Nombre maximal de médias analysés} {--dry-run : Compte seulement les médias concernés}')]
#[Description('Analyse avec l\'antivirus les médias encore non analysés')]
class ScanPendingMedia extends Command
{
    public function handle(MediaSecurityScanner $scanner, MediaStorage $storage): int
    {
        $pending = Media::query()->where('scan_status', ScanStatus::Pending->value);
        if ($this->option('dry-run')) {
            $this->info('Médias non analysés : '.$pending->count().'.');

            return self::SUCCESS;
        }
        if (! $scanner->isAvailable()) {
            $this->error('Aucun antivirus joignable (MEDIA_SCAN_DRIVER, CLAMAV_*) : rien n\'a été analysé.');

            return self::FAILURE;
        }

        $counts = ['clean' => 0, 'infected' => 0, 'error' => 0];
        foreach ($pending->orderBy('created_at')->limit(max(1, (int) $this->option('limit')))->get() as $media) {
            $status = $this->scanOne($scanner, $storage, $media);
            $counts[$status->value]++;
        }

        $this->info("Sains : {$counts['clean']} ; infectés : {$counts['infected']} ; erreurs (restent en quarantaine) : {$counts['error']}.");

        return $counts['error'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function scanOne(MediaSecurityScanner $scanner, MediaStorage $storage, Media $media): ScanStatus
    {
        $tmp = null;
        try {
            $tmp = $storage->copyToTemp($media->path);
            $result = $scanner->scan($tmp);
        } catch (Throwable) {
            return ScanStatus::Error; // fichier absent ou illisible : le média reste « pending »
        } finally {
            if ($tmp !== null && is_file($tmp)) {
                @unlink($tmp);
            }
        }

        if ($result->status === ScanStatus::Error) {
            return ScanStatus::Error; // pas de résultat fiable : on ne change rien
        }
        $media->forceFill(['scan_status' => $result->status, 'scanned_at' => now()])->save();
        if ($result->status === ScanStatus::Infected) {
            Log::warning('media.infected_existing', ['media_id' => $media->id, 'user_id' => $media->user_id, 'signature' => $result->signature]);
        }

        return $result->status;
    }
}
