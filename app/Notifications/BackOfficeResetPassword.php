<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BackOfficeResetPassword extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $url = route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return (new MailMessage)
            ->subject('Réinitialisation du mot de passe, ToutDispo')
            ->greeting('Bonjour,')
            ->line('Une demande de réinitialisation du mot de passe administrateur a été reçue.')
            ->action('Réinitialiser mon mot de passe', $url)
            ->line('Ce lien est valable 60 minutes. Si vous n’êtes pas à l’origine de cette demande, aucune action n’est nécessaire.');
    }
}
