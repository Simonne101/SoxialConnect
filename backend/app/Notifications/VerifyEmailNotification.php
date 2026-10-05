<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * E-mail de vérification de l'adresse, envoyé via la file d'attente (file « default ») :
 * l'inscription et le renvoi du lien ne dépendent jamais de la disponibilité du serveur d'e-mail.
 * Un échec d'envoi est retenté puis consigné dans failed_jobs, sans annuler l'inscription.
 *
 * Le lien signé est construit au moment de la demande (inscription, renvoi), sur l'origine
 * réellement utilisée par la personne (site local, tunnel HTTPS, domaine de production) : il
 * mène à la route web /email/verify/{id}/{hash} avec le cookie de session de cette origine.
 * Construit hors requête (console, file), il retombe sur APP_URL.
 */
class VerifyEmailNotification extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(private ?string $link = null) {}

    /** Notification prête à mettre en file, avec le lien signé calculé maintenant. */
    public static function for(object $notifiable): self
    {
        $notification = new self;
        $notification->link = $notification->verificationUrl($notifiable);

        return $notification;
    }

    protected function verificationUrl($notifiable)
    {
        return $this->link ?? parent::verificationUrl($notifiable);
    }
}
