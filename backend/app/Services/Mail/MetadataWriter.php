<?php

namespace App\Services\Mail;

use App\Mailbox\Data\Address;
use App\Mailbox\Data\MessageEnvelope;
use App\Mailbox\Data\MessageFlags;
use App\Models\AttachmentMetadata;
use App\Models\EmailAccount;
use App\Models\Folder;
use App\Models\MessageMetadata;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Persists provider envelopes as cached metadata. Upserts are keyed on the natural
 * unique keys (folder+uidvalidity+uid for IMAP, provider id for Gmail), so replays and
 * concurrent syncs cannot create duplicates (FR-SYNC-004).
 */
class MetadataWriter
{
    public function __construct(
        private readonly ThreadingService $threading,
        private readonly AuthenticationResults $authResults,
    ) {}

    /**
     * @return MessageMetadata|null the row if newly created
     */
    public function upsert(EmailAccount $account, Folder $folder, int $uidValidity, MessageEnvelope $envelope): ?MessageMetadata
    {
        $existing = $envelope->providerId !== null
            ? MessageMetadata::query()->where('email_account_id', $account->id)->where('provider_message_id', $envelope->providerId)->first()
            : MessageMetadata::query()->where('folder_id', $folder->id)->where('uidvalidity', $uidValidity)->where('uid', $envelope->uid)->first();

        if ($existing !== null) {
            // Gmail: a label change may move the message between folders.
            $existing->forceFill(['folder_id' => $folder->id, ...$this->flagColumns($envelope->flags)])->save();

            return null;
        }

        $row = new MessageMetadata;
        $row->forceFill([
            'email_account_id' => $account->id,
            'folder_id' => $folder->id,
            'uidvalidity' => $uidValidity,
            'uid' => $envelope->uid,
            'provider_message_id' => $envelope->providerId,
            'provider_thread_id' => $envelope->providerThreadId,
            'message_id_header' => $envelope->messageId,
            'thread_key' => $this->threading->threadKeyFor($account->id, $envelope),
            'in_reply_to' => $envelope->inReplyTo,
            'references_json' => $envelope->references === [] ? null : $envelope->references,
            'from_name' => $envelope->from?->name !== null ? mb_substr($envelope->from->name, 0, 255) : null,
            'from_email' => $envelope->from?->email,
            'to_json' => self::addresses($envelope->to),
            'cc_json' => self::addresses($envelope->cc),
            'reply_to_json' => self::addresses($envelope->replyTo),
            'subject_enc' => $envelope->subject,
            'snippet_enc' => config('mbuniemail.sync.cache_snippets') ? $envelope->snippet : null,
            'sent_at' => $envelope->sentAt,
            'internal_date' => $envelope->internalDate,
            'size_bytes' => min($envelope->size, 4_294_967_295),
            'has_attachments' => $envelope->hasAttachments(),
            'auth_results' => $this->authResults->parse($envelope->authenticationResults),
            ...$this->flagColumns($envelope->flags),
        ]);

        try {
            DB::transaction(function () use ($row, $envelope) {
                $row->save();
                foreach ($envelope->attachments as $attachment) {
                    $a = new AttachmentMetadata;
                    $a->forceFill([
                        'message_metadata_id' => $row->id,
                        'part_id' => $attachment->partId,
                        'filename' => $attachment->filename,
                        'mime_type' => mb_substr($attachment->mimeType, 0, 127),
                        'size_bytes' => $attachment->size,
                        'content_id' => $attachment->contentId,
                        'is_inline' => $attachment->isInline,
                    ])->save();
                }
            });
        } catch (UniqueConstraintViolationException) {
            return null; // a concurrent run inserted it first
        }

        return $row;
    }

    public function applyFlags(Folder $folder, int $uid, MessageFlags $flags): void
    {
        MessageMetadata::query()->where('folder_id', $folder->id)->where('uid', $uid)->update($this->flagColumns($flags));
    }

    /** @return array<string, bool> */
    private function flagColumns(MessageFlags $flags): array
    {
        return [
            'is_seen' => $flags->seen,
            'is_flagged' => $flags->flagged,
            'is_answered' => $flags->answered,
            'is_draft' => $flags->draft,
        ];
    }

    /**
     * @param  list<Address>  $addresses
     * @return list<array{email: string, name: string|null}>|null
     */
    private static function addresses(array $addresses): ?array
    {
        return $addresses === [] ? null : array_slice(array_map(fn (Address $a) => $a->toArray(), $addresses), 0, 100);
    }
}
