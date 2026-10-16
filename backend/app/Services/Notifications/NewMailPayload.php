<?php

namespace App\Services\Notifications;

use App\Models\MessageMetadata;

/**
 * Builds push/toast notification content (FR-NOTIF-002, risk R-19). By default only
 * the sender's display name is shown — never the body, and the subject only if the user
 * opted in. Lock-screen visibility is "private" so the OS hides even that when locked.
 */
final class NewMailPayload
{
    /** @return array{title: string, body: string, visibility: string, data: array<string, string>} */
    public static function build(MessageMetadata $message, bool $showSubject, int $count = 1): array
    {
        $sender = trim((string) ($message->from_name ?: $message->from_email ?: 'Unknown sender'));
        $sender = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $sender) ?? '', 0, 60);

        $subject = $showSubject && $message->subject_enc ? mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $message->subject_enc) ?? '', 0, 80) : null;
        $received = $message->internal_date ?? now();

        return [
            'title' => $count > 1 ? "{$count} new messages" : $sender,
            'body' => $count > 1
                ? "Latest from {$sender}".($subject !== null ? ": {$subject}" : '')
                : ($subject ?? 'Sent you a new message'),
            'visibility' => 'private',
            // The app draws the notification itself (logo, styling), so it gets the
            // pieces too. Never the body or snippet; the subject only when opted in.
            'data' => [
                'account_id' => $message->email_account_id,
                'message_id' => $message->id,
                'sender' => $sender,
                'count' => (string) $count,
                'received_at' => (string) ($received->getTimestamp() * 1000),
                ...($subject !== null ? ['subject' => $subject] : []),
            ],
        ];
    }
}
