<?php

namespace App\Domain\Media\Actions;

use App\Domain\Media\Enums\MediaStatus;
use App\Domain\Media\Enums\ScanStatus;
use App\Domain\Media\Exceptions\MediaRejected;
use App\Domain\Media\Exceptions\MediaScanUnavailable;
use App\Domain\Media\Security\MediaSecurityScanner;
use App\Domain\Media\Security\ScanPolicy;
use App\Domain\Media\Security\ScanResult;
use App\Domain\Media\Storage\MediaStorage;
use App\Domain\Media\Upload\InspectedFile;
use App\Domain\Media\Upload\MediaFileInspector;
use App\Domain\Quota\DiskGuard;
use App\Domain\Quota\DiskSpaceInsufficient;
use App\Domain\Quota\StorageQuota;
use App\Jobs\ProcessMediaJob;
use App\Models\Media;
use App\Models\User;
use App\Services\Audit\AuditAction;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Import d'un fichier dans la médiathèque (docs/security.md §7) :
 * Validation → Contrôles (magic bytes, taille, dimensions) → Quarantaine + analyse antivirus
 * → Identifiant généré → Stockage privé → Enregistrement → Traitement (synchrone ou job) → Ressource.
 * Le client ne choisit jamais le chemin, le nom de stockage ni le type.
 */
final class UploadMedia
{
    public function __construct(
        private readonly MediaFileInspector $inspector,
        private readonly MediaSecurityScanner $scanner,
        private readonly ScanPolicy $scanPolicy,
        private readonly MediaStorage $storage,
        private readonly ProcessMedia $process,
        private readonly AuditLogger $audit,
        private readonly StorageQuota $quota,
        private readonly DiskGuard $disk,
    ) {}

    /** @param list<string> $tags tags déjà normalisés */
    public function handle(User $user, UploadedFile $file, ?string $folderId = null, array $tags = []): Media
    {
        // Échec fermé : analyse obligatoire mais aucun moteur → rien n'est reçu ni stocké.
        if ($this->scanPolicy->isRequired() && ! $this->scanner->isAvailable()) {
            throw new MediaScanUnavailable;
        }

        try {
            $inspected = $this->inspector->inspect($file);
        } catch (MediaRejected $e) {
            $this->audit->record(AuditAction::MediaRejected, $user, null, ['reason' => $e->reason]);
            throw $e;
        }

        // Quarantaine : le fichier temporaire est analysé avant tout stockage.
        $scan = $this->scanner->scan($inspected->localPath);
        if ($scan->status === ScanStatus::Infected) {
            $this->audit->record(AuditAction::MediaRejected, $user, null, ['reason' => 'infected', 'signature' => $scan->signature]);
            throw MediaRejected::infected();
        }
        if ($scan->status === ScanStatus::Error) {
            throw MediaRejected::scanFailed();
        }

        // Quotas (ADR-071) : espace de l'utilisateur et disque du serveur réservés AVANT le stockage, sous verrou
        // (deux imports simultanés ne dépassent pas la limite) ; libérés une fois le média enregistré (il est alors
        // compté en base) ou en cas d'échec.
        $quotaReservation = $this->quota->reserve($user, $inspected->sizeBytes, 'upload');
        try {
            $diskReservation = $this->disk->reserve($inspected->sizeBytes, 'upload', storage_path('app'));
        } catch (DiskSpaceInsufficient $e) {
            $this->quota->release($user, $quotaReservation);
            throw $e;
        }
        try {
            return $this->store($user, $inspected, $scan, $folderId, $tags);
        } finally {
            $this->quota->release($user, $quotaReservation);
            $this->disk->release($diskReservation);
        }
    }

    /** @param list<string> $tags */
    private function store(User $user, InspectedFile $inspected, ScanResult $scan, ?string $folderId, array $tags): Media
    {
        $media = new Media;
        $media->id = (string) Str::uuid7();
        $media->user_id = $user->getKey();
        $media->folder_id = $folderId;
        $media->type = $inspected->type;
        $media->status = MediaStatus::Processing;
        $media->original_name = $inspected->displayName;
        $media->disk = $this->storage->diskName();
        $media->path = $this->storage->pathFor($user->getKey(), $media->id, MediaStorage::ORIGINAL, $inspected->extension);
        $media->mime_type = $inspected->mimeType;
        $media->extension = $inspected->extension;
        $media->size_bytes = $inspected->sizeBytes;
        $media->checksum_sha256 = $inspected->checksum;
        $media->width = $inspected->width;
        $media->height = $inspected->height;
        $media->scan_status = $scan->status;
        $media->scanned_at = $scan->wasScanned() ? now() : null;
        $media->tags = $tags ?: null;
        $media->metadata = ['format' => $inspected->extension];

        $stream = fopen($inspected->localPath, 'rb');
        try {
            $this->storage->put($media->path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        try {
            DB::transaction(fn () => $media->save());

            if (config('media.process_async')) {
                ProcessMediaJob::dispatch($media->id);
            } else {
                $this->process->handle($media, $inspected->localPath);
            }
        } catch (\Throwable $e) {
            // Aucun fichier ni enregistrement orphelin si une étape échoue.
            $this->storage->deleteAll($media);
            if ($media->exists) {
                $media->forceDelete();
            }
            if ($e instanceof MediaRejected) {
                $this->audit->record(AuditAction::MediaRejected, $user, null, ['reason' => $e->reason]);
            }
            throw $e;
        }

        $this->audit->record(AuditAction::MediaUploaded, $user, $media, [
            'type' => $media->type->value,
            'size_bytes' => $media->size_bytes,
            'scan_status' => $media->scan_status->value,
        ]);

        return $media->refresh();
    }

    /** Nombre d'autres médias identiques (même contenu) de l'utilisateur — information, jamais suppression. */
    public function duplicatesOf(Media $media): int
    {
        return Media::query()->where('user_id', $media->user_id)
            ->where('checksum_sha256', $media->checksum_sha256)
            ->whereKeyNot($media->getKey())
            ->count();
    }
}
