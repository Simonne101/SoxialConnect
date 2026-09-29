<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Audit\AuditAction;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Laravel\Fortify\Events\PasswordUpdatedViaController;

/**
 * Traces d'audit des événements d'authentification (LOGIN, LOGOUT, PASSWORD_CHANGED…).
 * Jamais de mot de passe ni d'identifiant de session dans les métadonnées.
 */
class RecordAuthenticationAudit
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function onLogin(Login $event): void
    {
        if ($event->user instanceof User) {
            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            $this->audit->record(AuditAction::Login, $event->user, $event->user, ['remember' => $event->remember]);
        }
    }

    public function onFailed(Failed $event): void
    {
        // L'e-mail tenté est conservé pour l'investigation ; le mot de passe jamais.
        $this->audit->record(
            AuditAction::LoginFailed,
            $event->user instanceof User ? $event->user : null,
            null,
            ['email' => mb_strtolower((string) ($event->credentials['email'] ?? ''))],
        );
    }

    public function onLogout(Logout $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->record(AuditAction::Logout, $event->user, $event->user);
        }
    }

    public function onRegistered(Registered $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->record(AuditAction::Registered, $event->user, $event->user);
        }
    }

    public function onVerified(Verified $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->record(AuditAction::EmailVerified, $event->user, $event->user);
        }
    }

    public function onPasswordReset(PasswordReset $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->record(AuditAction::PasswordReset, $event->user, $event->user);
        }
    }

    public function onPasswordUpdated(PasswordUpdatedViaController $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->record(AuditAction::PasswordChanged, $event->user, $event->user);
        }
    }
}
