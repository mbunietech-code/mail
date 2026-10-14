<?php

namespace App\Services\Mail;

use App\Exceptions\ApiException;
use App\Jobs\SyncAccountJob;
use App\Mailbox\Contracts\MailProvider;
use App\Mailbox\Data\MessageRef;
use App\Mailbox\ProviderError;
use App\Mailbox\ProviderErrorCode;
use App\Mailbox\ProviderErrorResponder;
use App\Mailbox\ProviderFactory;
use App\Models\AttachmentMetadata;
use App\Models\Folder;
use App\Models\MessageMetadata;
use App\Security\Html\EmailHtmlSanitizer;

/**
 * Message operations against the provider, keeping the metadata cache consistent.
 * Callers must already have resolved $message through MessageMetadata::ownedBy().
 */
class MessageService
{
    public function __construct(
        private readonly ProviderFactory $providers,
        private readonly EmailHtmlSanitizer $sanitizer,
        private readonly SenderAssessment $assessment,
    ) {}

    public function ref(MessageMetadata $message): MessageRef
    {
        return new MessageRef($message->folder->remote_name, $message->uid, $message->uidvalidity, $message->provider_message_id);
    }

    /** @return array<string, mixed> */
    public function read(MessageMetadata $message, bool $allowRemoteImages): array
    {
        $content = $this->withProvider($message, fn (MailProvider $p) => $p->fetchMessage($this->ref($message)));

        // Record attachment parts so they can be downloaded by id.
        $attachments = [];
        foreach ($content->attachments as $info) {
            $row = AttachmentMetadata::query()->where('message_metadata_id', $message->id)->where('part_id', $info->partId)->first() ?? new AttachmentMetadata;
            $row->forceFill([
                'message_metadata_id' => $message->id,
                'part_id' => $info->partId,
                'filename' => $info->filename,
                'mime_type' => mb_substr($info->mimeType, 0, 127),
                'size_bytes' => $info->size,
                'content_id' => $info->contentId,
                'is_inline' => $info->isInline,
            ])->save();
            $attachments[] = $row;
        }

        $sanitized = $content->html !== null ? $this->sanitizer->sanitize($content->html, $allowRemoteImages) : null;
        $inline = [];
        foreach ($attachments as $a) {
            if ($a->content_id !== null && in_array($a->content_id, $sanitized->contentIds ?? [], true)) {
                $inline[$a->content_id] = $a->id;
            }
        }

        return [
            'id' => $message->id,
            'thread_key' => $message->thread_key,
            'folder_id' => $message->folder_id,
            'subject' => $message->subject_enc ?? '',
            'headers' => [
                'from' => ['email' => $message->from_email, 'name' => $message->from_name],
                'to' => $message->to_json ?? [],
                'cc' => $message->cc_json ?? [],
                'reply_to' => $message->reply_to_json ?? [],
                'date' => ($message->sent_at ?? $message->internal_date)->toIso8601String(),
                'message_id' => $message->message_id_header,
            ],
            'body' => [
                'html' => $sanitized?->html,
                'text' => $content->text !== null ? mb_substr($content->text, 0, 2_000_000) : null,
                'remote_images_blocked' => $sanitized->blockedRemoteImages ?? 0,
                'remote_images_allowed' => $allowRemoteImages,
                'inline_images' => (object) $inline,
            ],
            'attachments' => array_map(fn (AttachmentMetadata $a) => [
                'id' => $a->id,
                'filename' => $a->filename,
                'mime_type' => $a->mime_type,
                'size' => $a->size_bytes,
                'is_inline' => $a->is_inline,
                'warning' => AttachmentPolicy::warningFor($a->filename, $a->mime_type),
            ], $attachments),
            'flags' => ['is_seen' => $message->is_seen, 'is_flagged' => $message->is_flagged, 'is_answered' => $message->is_answered],
            'security' => $this->assessment->assess($message),
        ];
    }

    public function setFlags(MessageMetadata $message, ?bool $seen, ?bool $flagged): void
    {
        $this->withProvider($message, fn (MailProvider $p) => $p->setFlags($this->ref($message), $seen, $flagged));

        $message->forceFill(array_filter(['is_seen' => $seen, 'is_flagged' => $flagged], fn ($v) => $v !== null))->save();
        $this->recount($message->folder);
    }

    public function move(MessageMetadata $message, Folder $target): void
    {
        if ($target->email_account_id !== $message->email_account_id) {
            throw new ApiException('VALIDATION_FAILED', 422, 'The destination folder belongs to another account.');
        }
        if ($target->id === $message->folder_id) {
            return;
        }

        $source = $message->folder;
        $this->withProvider($message, fn (MailProvider $p) => $p->move($this->ref($message), $target->remote_name));

        // The message gets a new UID in the target folder; the next sync caches it.
        $message->delete();
        $this->recount($source);
        SyncAccountJob::dispatch($message->email_account_id, true);
    }

    public function trash(MessageMetadata $message, bool $permanent): void
    {
        $folder = $message->folder;

        if ($permanent) {
            if ($folder->role !== 'trash') {
                throw new ApiException('CONFLICT', 409, 'Only messages already in Trash can be deleted permanently.');
            }
            $this->withProvider($message, fn (MailProvider $p) => $p->expunge($this->ref($message)));
            $message->delete();
            $this->recount($folder);

            return;
        }

        $this->move($message, $this->folderByRole($message, 'trash'));
    }

    public function markSpam(MessageMetadata $message, bool $spam): void
    {
        $this->move($message, $this->folderByRole($message, $spam ? 'spam' : 'inbox'));
    }

    /** @return array{bytes: string, filename: string, mime: string} */
    public function attachment(AttachmentMetadata $attachment): array
    {
        $message = $attachment->message;
        $bytes = $this->withProvider($message, fn (MailProvider $p) => $p->fetchAttachment($this->ref($message), $attachment->part_id));

        return ['bytes' => $bytes, 'filename' => $attachment->filename, 'mime' => $attachment->mime_type];
    }

    private function folderByRole(MessageMetadata $message, string $role): Folder
    {
        return Folder::query()->where('email_account_id', $message->email_account_id)->where('role', $role)->first()
            ?? throw new ApiException('PROVIDER_NOT_SUPPORTED', 422, "This mailbox has no {$role} folder.");
    }

    private function recount(Folder $folder): void
    {
        $folder->forceFill(['unread_count' => $folder->messages()->where('is_seen', false)->count()])->save();
    }

    /**
     * @template T
     *
     * @param  callable(MailProvider): T  $callback
     * @return T
     */
    private function withProvider(MessageMetadata $message, callable $callback): mixed
    {
        $provider = $this->providers->forAccount($message->emailAccount);

        try {
            return $callback($provider);
        } catch (ProviderError $e) {
            if (in_array($e->errorCode, [ProviderErrorCode::MessageGone, ProviderErrorCode::MailboxGone], true)) {
                $message->delete(); // stale cache entry
            }
            report($e);

            throw ProviderErrorResponder::toApi($e);
        } finally {
            $provider->disconnect();
        }
    }
}
