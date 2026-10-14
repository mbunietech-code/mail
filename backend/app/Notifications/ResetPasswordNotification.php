<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The token travels in the URL fragment so it is not sent to servers or written to
 * access logs when the link is opened.
 */
class ResetPasswordNotification extends Notification
{
    public function __construct(#[\SensitiveParameter] private readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('mbuniemail.password.reset_ttl_minutes');

        return (new MailMessage)
            ->subject('Reset your MbunieMail password')
            ->line('A password reset was requested for your MbunieMail account.')
            ->action('Reset password', config('mbuniemail.app_link_url').'/reset-password#token='.$this->token)
            ->line("This link expires in {$minutes} minutes and can be used once.")
            ->line('If you did not request this, you can ignore this email; your password is unchanged.');
    }
}
