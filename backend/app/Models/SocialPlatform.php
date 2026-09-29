<?php

namespace App\Models;

use App\Domain\Social\Enums\PlatformAvailability;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Donnée de référence (seeder) : réseaux pris en charge et leurs capacités déclarées. */
class SocialPlatform extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['key', 'name', 'availability', 'capabilities', 'api_version'];

    protected function casts(): array
    {
        return [
            'availability' => PlatformAvailability::class,
            'capabilities' => 'array',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }
}
