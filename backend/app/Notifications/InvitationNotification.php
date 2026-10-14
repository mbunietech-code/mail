<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class InvitationNotification extends Notification
{
    public function __construct(
        #[\SensitiveParameter] private readonly string $token,
        private readonly Carbon $expiresAt,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You have been invited to MbunieMail')
            ->line('MbuniTech has invited you to use MbunieMail, the company email app.')
            ->action('Accept invitation', config('mbuniemail.app_link_url').'/invite#token='.$this->token)
            ->line('This invitation expires on '.$this->expiresAt->toDayDateTimeString().' (UTC) and can be used once.')
            ->line('If you were not expecting this, ignore this email.');
    }
}
