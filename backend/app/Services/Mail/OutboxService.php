<?php

namespace App\Services\Mail;

use App\Exceptions\ApiException;
use App\Jobs\SendMessageJob;
use App\Mailbox\PartDecoder;
use App\Models\EmailAccount;
use App\Models\MessageMetadata;
use App\Models\OutboxAttachment;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Security\Html\EmailHtmlSanitizer;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Compose, queue and track outgoing mail (SDD §8.2).
 *
 * At most one submission per (user, Idempotency-Key): a repeated request returns the
 * original record, and a different body under the same key is rejected (FR-SEND-005).
 */
class OutboxService
{
    public function __construct(
        private readonly EmailHtmlSanitizer $sanitizer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated request
     * @return array{0: OutboxMessage, 1: bool} message, and whether it was newly created
     */
    public function queue(User $user, string $idempotencyKey, array $data): array
    {
        $requestHash = hash('sha256', json_encode(self::canonical($data), JSON_THROW_ON_ERROR));

        $existing = OutboxMessage::query()->where('user_id', $user->id)->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            if (! hash_equals((string) $existing->request_hash, $requestHash)) {
                throw new ApiException('IDEMPOTENCY_MISMATCH', 409, 'This Idempotency-Key was already used for a different message.');
            }

            return [$existing, false];
        }

        $account = $user->emailAccounts()->with('providerProfile')->findOrFail($data['account_id']);
        if (in_array($account->status, [EmailAccount::STATUS_DISABLED, EmailAccount::STATUS_AUTH_FAILED], true)) {
            throw new ApiException('ACCOUNT_UNAVAILABLE', 422, 'This mailbox cannot send until it is reconnected.');
        }

        $recipients = self::recipients($data);
        if ($recipients === []) {
            throw new ApiException('VALIDATION_FAILED', 422, 'Add at least one recipient.');
        }
        if (count($recipients) > (int) config('mbuniemail.outbox.max_recipients')) {
            throw new ApiException('VALIDATION_FAILED', 422, 'Too many recipients.');
        }

        $reference = $this->referencedMessage($user, $account, $data);
        $attachments = $this->claimableAttachments($user, $data['attachment_ids'] ?? []);

        $this->enforceSendLimits($user, $account, count($recipients));

        $html = isset($data['html']) && $data['html'] !== '' ? $this->sanitizer->sanitizeOutbound((string) $data['html']) : null;
        $text = isset($data['text']) && $data['text'] !== '' ? (string) $data['text'] : ($html !== null ? self::htmlToText($html) : '');
        $domain = substr(strrchr($account->email_address, '@') ?: '@mt.co.tz', 1);
        $mode = $data['mode'] ?? 'new';

        try {
            $message = $this->insert($user, $account, $idempotencyKey, $requestHash, $data, $recipients, $reference, $attachments, $html, $text, $domain, $mode);
        } catch (UniqueConstraintViolationException) {
            // A concurrent request with the same key won the race: return its record.
            return $this->queue($user, $idempotencyKey, $data);
        }

        $this->audit->record('mail.send.queued', 'success', $user, 'outbox_message', $message->id, ['recipients' => count($recipients), 'attachments' => count($attachments), 'mode' => $mode]);
        SendMessageJob::dispatch($message->id)->afterCommit();

        return [$message, true];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $recipients
     * @param  list<string>  $attachments
     */
    private function insert(User $user, EmailAccount $account, string $idempotencyKey, string $requestHash, array $data, array $recipients, ?MessageMetadata $reference, array $attachments, ?string $html, string $text, string $domain, string $mode): OutboxMessage
    {
        return DB::transaction(function () use ($user, $account, $idempotencyKey, $requestHash, $data, $recipients, $reference, $attachments, $html, $text, $domain, $mode) {
            $message = new OutboxMessage;
            $message->forceFill([
                'user_id' => $user->id,
                'email_account_id' => $account->id,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'kind' => 'send',
                'status' => OutboxMessage::STATUS_QUEUED,
                'message_id_header' => strtolower((string) Str::ulid()).'@'.$domain,
                'recipient_count' => count($recipients),
                'in_reply_to_metadata_id' => in_array($mode, ['reply', 'reply_all'], true) ? $reference?->id : null,
                'forward_of_metadata_id' => $mode === 'forward' && ($data['include_original_attachments'] ?? false) ? $reference?->id : null,
                'payload_enc' => [
                    'to' => self::normalise($data['to'] ?? []),
                    'cc' => self::normalise($data['cc'] ?? []),
                    'bcc' => self::normalise($data['bcc'] ?? []),
                    'subject' => self::subject((string) ($data['subject'] ?? '')),
                    'html' => $html,
                    'text' => $text,
                    'in_reply_to' => $reference !== null && $mode !== 'forward' ? $reference->message_id_header : null,
                    'references' => $reference !== null && $mode !== 'forward'
                        ? array_values(array_filter([...($reference->references_json ?? []), $reference->message_id_header]))
                        : [],
                ],
            ])->save();

            OutboxAttachment::query()->whereIn('id', $attachments)->update(['outbox_message_id' => $message->id]);

            return $message;
        });
    }

    /**
     * Explicit user decision to send again after a failure or an unknown outcome.
     */
    public function resend(OutboxMessage $message, bool $confirmPossibleDuplicate): OutboxMessage
    {
        if (! in_array($message->status, [OutboxMessage::STATUS_FAILED, OutboxMessage::STATUS_UNKNOWN], true)) {
            throw new ApiException('CONFLICT', 409, 'Only failed or uncertain messages can be resent.');
        }
        if ($message->status === OutboxMessage::STATUS_UNKNOWN && ! $confirmPossibleDuplicate) {
            throw new ApiException('CONFIRMATION_REQUIRED', 422, 'This message may already have been delivered. Check Sent, then confirm to send again.');
        }
        if ($message->payload_enc === null) {
            throw new ApiException('CONFLICT', 409, 'This message is no longer available to resend.');
        }

        $message->forceFill(['status' => OutboxMessage::STATUS_QUEUED, 'attempts' => 0, 'last_error_code' => null, 'next_attempt_at' => null])->save();
        $this->audit->record('mail.send.resend', 'success', $message->emailAccount->user, 'outbox_message', $message->id, ['after' => $message->getOriginal('status')]);
        SendMessageJob::dispatch($message->id);

        return $message;
    }

    /**
     * Stores an upload on the private disk under a random name (risk R-18).
     *
     * @return array<string, mixed>
     */
    public function stageAttachment(User $user, UploadedFile $file): array
    {
        $filename = PartDecoder::safeFilename($file->getClientOriginalName());
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) ?: 'application/octet-stream';
        $risky = AttachmentPolicy::isRisky($filename);

        if ($risky && config('mbuniemail.outbox.risky_extension_policy') === 'block') {
            throw new ApiException('ATTACHMENT_BLOCKED', 422, 'This file type is not allowed by company policy.');
        }

        $path = 'outbox/'.$user->id.'/'.Str::ulid();
        Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));

        $row = new OutboxAttachment;
        $row->forceFill([
            'user_id' => $user->id,
            'storage_path' => $path,
            'filename' => $filename,
            'mime_type' => mb_substr($detected, 0, 127),
            'size_bytes' => (int) $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'scan_status' => 'not_scanned',
        ])->save();

        return [
            'id' => $row->id,
            'filename' => $row->filename,
            'size' => $row->size_bytes,
            'mime_type' => $row->mime_type,
            'scan_status' => $row->scan_status,
            'warning' => AttachmentPolicy::warningFor($filename, $detected),
        ];
    }

    /** @param array<string, mixed> $data */
    private function referencedMessage(User $user, EmailAccount $account, array $data): ?MessageMetadata
    {
        if (empty($data['reply_to_message_id'])) {
            return null;
        }

        return MessageMetadata::query()->ownedBy($user)->where('email_account_id', $account->id)->find($data['reply_to_message_id'])
            ?? throw new ApiException('VALIDATION_FAILED', 422, 'The referenced message was not found in this account.');
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function claimableAttachments(User $user, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = OutboxAttachment::query()->where('user_id', $user->id)->whereNull('outbox_message_id')->whereIn('id', $ids)->get();
        if ($rows->count() !== count(array_unique($ids))) {
            throw new ApiException('VALIDATION_FAILED', 422, 'One or more attachments are not available.');
        }
        if ($rows->sum('size_bytes') > (int) config('mbuniemail.outbox.max_attachment_bytes')) {
            throw new ApiException('PAYLOAD_TOO_LARGE', 413, 'Attachments exceed the size limit.');
        }

        return $rows->pluck('id')->all();
    }

    /** Per-user and per-account hourly limits contain a compromised session (risk R-11). */
    private function enforceSendLimits(User $user, EmailAccount $account, int $recipients): void
    {
        $cfg = config('mbuniemail.outbox');
        foreach (['send-msgs:u:'.$user->id, 'send-msgs:a:'.$account->id] as $key) {
            if (RateLimiter::attempts($key) + 1 > (int) $cfg['messages_per_hour']) {
                throw ApiException::tooManyAttempts(RateLimiter::availableIn($key));
            }
        }
        foreach (['send-rcpt:u:'.$user->id, 'send-rcpt:a:'.$account->id] as $key) {
            if (RateLimiter::attempts($key) + $recipients > (int) $cfg['recipients_per_hour']) {
                throw ApiException::tooManyAttempts(RateLimiter::availableIn($key));
            }
        }

        RateLimiter::hit('send-msgs:u:'.$user->id, 3600);
        RateLimiter::hit('send-msgs:a:'.$account->id, 3600);
        RateLimiter::increment('send-rcpt:u:'.$user->id, 3600, $recipients);
        RateLimiter::increment('send-rcpt:a:'.$account->id, 3600, $recipients);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function recipients(array $data): array
    {
        return array_values(array_unique(array_map(
            fn (array $a) => $a['email'],
            [...self::normalise($data['to'] ?? []), ...self::normalise($data['cc'] ?? []), ...self::normalise($data['bcc'] ?? [])],
        )));
    }

    /**
     * Accepts "a@b" or {email, name}; returns unique lower-cased addresses.
     *
     * @param  list<string|array{email: string, name?: string|null}>  $list
     * @return list<array{email: string, name: string|null}>
     */
    private static function normalise(array $list): array
    {
        $out = [];
        foreach ($list as $item) {
            $email = strtolower(trim(is_array($item) ? (string) $item['email'] : (string) $item));
            $name = is_array($item) && isset($item['name']) ? trim(preg_replace('/[\r\n\x00]/', ' ', (string) $item['name']) ?? '') : null;
            $out[$email] = ['email' => $email, 'name' => $name !== '' ? $name : null];
        }

        return array_values($out);
    }

    private static function subject(string $subject): string
    {
        return mb_substr(trim(preg_replace('/[\r\n\x00]+/', ' ', $subject) ?? ''), 0, 998);
    }

    private static function htmlToText(string $html): string
    {
        $text = preg_replace(['#<br\s*/?>#i', '#</(p|div|tr|li|h[1-6])>#i'], ["\n", "\n"], $html) ?? '';

        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function canonical(array $data): array
    {
        ksort($data);

        return $data;
    }
}
