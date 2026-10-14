<?php

namespace App\Services\Mail;

use App\Mailbox\Data\MessageEnvelope;
use App\Models\MessageMetadata;
use Illuminate\Support\Str;

/**
 * Assigns conversation keys (FR-MAIL-010). Gmail's thread id is authoritative when
 * present; otherwise RFC 5322 Message-ID / In-Reply-To / References link messages.
 * Subjects are deliberately NOT used: "Re: Invoice" from unrelated senders would merge.
 */
class ThreadingService
{
    public function threadKeyFor(string $accountId, MessageEnvelope $envelope): string
    {
        $query = fn () => MessageMetadata::query()->where('email_account_id', $accountId);

        if ($envelope->providerThreadId !== null) {
            $key = $query()->where('provider_thread_id', $envelope->providerThreadId)->value('thread_key');
            if ($key !== null) {
                return $key;
            }
        }

        // Same message already seen in another folder, or any ancestor we know.
        $ids = array_values(array_unique(array_filter([
            $envelope->messageId,
            $envelope->inReplyTo,
            ...array_reverse($envelope->references),
        ])));
        if ($ids !== []) {
            $key = $query()->whereIn('message_id_header', $ids)->orderBy('internal_date')->value('thread_key');
            if ($key !== null) {
                return $key;
            }
        }

        // A reply arrived before its parent: adopt the reply's thread.
        if ($envelope->messageId !== null) {
            $key = $query()->where('in_reply_to', $envelope->messageId)->value('thread_key');
            if ($key !== null) {
                return $key;
            }
        }

        return (string) Str::ulid();
    }
}
