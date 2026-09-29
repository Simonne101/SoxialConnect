<?php

namespace App\Models;

use App\Domain\Media\Enums\MediaStatus;
use App\Domain\Media\Enums\MediaType;
use App\Domain\Media\Enums\ScanStatus;
use App\Domain\Media\Security\ScanPolicy;
use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Fichier de la médiathèque (docs/database.md §3.4). Le chemin de stockage est toujours
 * généré côté serveur ; un média non « ready » ou en quarantaine n'est jamais servi ni publié.
 */
class Media extends Model
{
    use BelongsToUser, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'media';

    /** path, disk, mime_type, statuts et checksum sont fixés par le pipeline d'upload. */
    protected $fillable = ['original_name', 'folder_id', 'tags'];

    protected $hidden = ['disk', 'path', 'derivatives'];

    protected function casts(): array
    {
        return [
            'type' => MediaType::class,
            'status' => MediaStatus::class,
            'scan_status' => ScanStatus::class,
            'tags' => 'array',
            'derivatives' => 'array',
            'metadata' => 'array',
            'scanned_at' => 'datetime',
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    /** Prêt ET analyse acceptable (ScanPolicy) : peut être servi et publié. */
    public function isUsable(): bool
    {
        return $this->status === MediaStatus::Ready
            && in_array($this->scan_status?->value, app(ScanPolicy::class)->usableStatuses(), true);
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('status', MediaStatus::Ready->value)
            ->whereIn('scan_status', app(ScanPolicy::class)->usableStatuses());
    }

    public function isGif(): bool
    {
        return $this->mime_type === 'image/gif';
    }

    /** Nom proposé au téléchargement : ASCII sûr pour l'en-tête Content-Disposition. */
    public function downloadName(): string
    {
        $base = pathinfo((string) $this->original_name, PATHINFO_FILENAME);
        $ascii = trim((string) preg_replace('/[^A-Za-z0-9._ -]/', '_', (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base)), ' ._');

        return ($ascii !== '' ? mb_substr($ascii, 0, 120) : 'media').'.'.($this->extension ?: 'bin');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'folder_id');
    }
}
