<?php

namespace App\Models;

use App\Domain\Users\Enums\UserStatus;
use App\Notifications\VerifyEmailNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Utilisateur (docs/database.md §3.1). Identifiant : UUID v7, également identifiant public.
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable, SoftDeletes;

    /** Champs modifiables par l'utilisateur. Statut, e-mail vérifié et 2FA ne le sont jamais par assignation de masse. */
    protected $fillable = [
        'name',
        'email',
        'password',
        'headline',
        'bio',
        'timezone',
        'locale',
    ];

    /** Valeurs par défaut (mode strict : tout attribut lu par les ressources doit exister). */
    protected $attributes = [
        'status' => 'active',
        'timezone' => 'Europe/Paris',
        'locale' => 'fr',
        'headline' => null,
        'bio' => null,
        'email_verified_at' => null,
        'two_factor_confirmed_at' => null,
        'last_login_at' => null,
    ];

    /** Jamais sérialisés (réponses API, journaux, files d'attente). */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted',
            'status' => UserStatus::class,
        ];
    }

    /** E-mail normalisé en minuscules (unicité, connexion). */
    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = mb_strtolower(trim($value));
    }

    public function isSuspended(): bool
    {
        return $this->status === UserStatus::Suspended;
    }

    /**
     * E-mail de vérification mis en file (inscription et renvoi du lien) : une panne du serveur
     * d'e-mail ne fait jamais échouer l'inscription après la création du compte. Le lien est
     * calculé maintenant, sur l'origine utilisée par la personne (voir VerifyEmailNotification).
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(VerifyEmailNotification::for($this));
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class);
    }

    public function mediaFolders(): HasMany
    {
        return $this->hasMany(MediaFolder::class);
    }

    public function videoProjects(): HasMany
    {
        return $this->hasMany(VideoProject::class);
    }

    public function aiGenerations(): HasMany
    {
        return $this->hasMany(AiGeneration::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
