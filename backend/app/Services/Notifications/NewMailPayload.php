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

        return [
            'title' => $count > 1 ? "{$count} new messages" : 'New message',
            'body' => $count > 1 ? "Latest from {$sender}" : ($showSubject && $message->subject_enc ? "{$sender}: ".mb_substr((string) $message->subject_enc, 0, 80) : "From {$sender}"),
            'visibility' => 'private',
            // Identifiers only; the app fetches content after the user unlocks and opens it.
            'data' => ['account_id' => $message->email_account_id, 'message_id' => $message->id],
        ];
    }
}
