<?php

namespace App\Models;

use App\Domain\Media\Enums\MediaStatus;
use App\Domain\Media\Enums\MediaType;
use App\Domain\Media\Enums\ScanStatus;
use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Fichier de la médiathèque (docs/database.md §3.4). Le chemin de stockage est toujours
 * généré côté serveur ; un média non « ready » ou non « clean » n'est jamais servi ni publié.
 */
class Media extends Model
{
    use BelongsToUser, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'media';

    /** path, disk, mime_type, statuts et checksum sont fixés par le pipeline d'upload. */
    protected $fillable = ['original_name', 'folder_id', 'tags'];

    protected $hidden = ['disk', 'path'];

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
        ];
    }

    public function isUsable(): bool
    {
        return $this->status === MediaStatus::Ready && $this->scan_status === ScanStatus::Clean;
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'folder_id');
    }
}
