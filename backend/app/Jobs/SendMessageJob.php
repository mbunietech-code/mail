<?php

namespace App\Jobs;

use App\Mailbox\Contracts\MailProvider;
use App\Mailbox\Data\Address;
use App\Mailbox\Data\MessageRef;
use App\Mailbox\Data\OutgoingMessage;
use App\Mailbox\MimeComposer;
use App\Mailbox\ProviderError;
use App\Mailbox\ProviderErrorCode;
use App\Mailbox\ProviderFactory;
use App\Models\EmailAccount;
use App\Models\Folder;
use App\Models\MessageMetadata;
use App\Models\OutboxAttachment;
use App\Models\OutboxMessage;
use App\Services\Audit\AuditLogger;
use App\Services\Mail\SyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Submits one outbox message (SDD §8.2). At most one SMTP submission per outbox row:
 *  - an atomic `queued → sending` claim means only one worker can ever submit it;
 *  - a failure before the message data was sent is retried with backoff;
 *  - a failure after it is recorded as `unknown` and never retried automatically;
 *  - definitive rejections are `failed`.
 * The job payload is only the outbox id.
 */
class SendMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1; // retries are explicit re-dispatches after a safe failure

    public int $timeout = 120;

    public function __construct(public readonly string $outboxId)
    {
        $this->onQueue('send');
    }

    public function handle(ProviderFactory $providers, MimeComposer $composer, AuditLogger $audit): void
    {
        $claimed = OutboxMessage::query()
            ->whereKey($this->outboxId)
            ->where('status', OutboxMessage::STATUS_QUEUED)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->update(['status' => OutboxMessage::STATUS_SENDING, 'claimed_at' => now(), 'attempts' => DB::raw('attempts + 1')]);

        if ($claimed !== 1) {
            return; // already sent, being sent by another worker, or not yet due
        }

        $message = OutboxMessage::query()->with('emailAccount.providerProfile', 'emailAccount.user')->findOrFail($this->outboxId);
        $account = $message->emailAccount;
        $payload = (array) $message->payload_enc;
        $temporaryFiles = [];
        $provider = null;
        $submitted = false;

        try {
            $provider = $providers->forAccount($account);
            $attachments = $this->attachments($message, $provider, $temporaryFiles);

            $outgoing = new OutgoingMessage(
                from: new Address($account->email_address, $account->display_name ?: $account->user->name),
                to: self::addresses($payload['to'] ?? []),
                cc: self::addresses($payload['cc'] ?? []),
                bcc: self::addresses($payload['bcc'] ?? []),
                subject: (string) ($payload['subject'] ?? ''),
                html: $payload['html'] ?? null,
                text: $payload['text'] ?? null,
                messageId: (string) $message->message_id_header,
                inReplyTo: $payload['in_reply_to'] ?? null,
                references: $payload['references'] ?? [],
                attachments: $attachments,
            );
            $rfc822 = $composer->compose($outgoing);

            $provider->submit($outgoing, $rfc822);
            $submitted = true;

            $message->forceFill(['status' => OutboxMessage::STATUS_SENT, 'sent_at' => now(), 'last_error_code' => null])->save();
            $audit->record('mail.send.sent', 'success', $account->user, 'outbox_message', $message->id, ['recipients' => $message->recipient_count]);

            try {
                $this->afterSent($message, $account, $provider, $rfc822);
            } catch (Throwable $e) {
                report($e); // delivered: post-send housekeeping must never change the outcome
            }
        } catch (ProviderError $e) {
            $this->handleFailure($message, $account, $e, $audit);
        } catch (Throwable $e) {
            report($e);
            // After a successful submission the message is delivered, whatever failed
            // locally; otherwise this was a local error before anything was sent.
            $message->forceFill($submitted
                ? ['status' => OutboxMessage::STATUS_SENT, 'sent_at' => now()]
                : ['status' => OutboxMessage::STATUS_FAILED, 'last_error_code' => 'INTERNAL_ERROR'])->save();
        } finally {
            $provider?->disconnect();
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    private function handleFailure(OutboxMessage $message, EmailAccount $account, ProviderError $e, AuditLogger $audit): void
    {
        report($e);
        $code = $e->errorCode;

        if ($code === ProviderErrorCode::UnknownOutcome) {
            $message->forceFill(['status' => OutboxMessage::STATUS_UNKNOWN, 'last_error_code' => $code->value])->save();
            $audit->record('mail.send.unknown_outcome', 'failure', $account->user, 'outbox_message', $message->id);

            return;
        }

        if ($code->isRetryable() && $message->attempts < (int) config('mbuniemail.outbox.max_attempts')) {
            $delay = max($e->retryAfterSeconds ?? 0, SyncService::backoffSeconds($message->attempts));
            $message->forceFill(['status' => OutboxMessage::STATUS_QUEUED, 'last_error_code' => $code->value, 'next_attempt_at' => now()->addSeconds($delay)])->save();
            self::dispatch($message->id)->delay(now()->addSeconds($delay));

            return;
        }

        if ($code === ProviderErrorCode::AuthFailed) {
            $account->forceFill(['status' => EmailAccount::STATUS_AUTH_FAILED, 'last_error_code' => $code->value])->save();
        }

        $message->forceFill(['status' => OutboxMessage::STATUS_FAILED, 'last_error_code' => $code->value])->save();
        $audit->record('mail.send.failed', 'failure', $account->user, 'outbox_message', $message->id, ['code' => $code->value]);
    }

    /**
     * Copy to Sent (unless the provider does it itself) and mark the original answered.
     * Failures here never change the send outcome.
     */
    private function afterSent(OutboxMessage $message, EmailAccount $account, MailProvider $provider, string $rfc822): void
    {
        if ($account->providerProfile->driver === 'imap_smtp' && ! $account->providerProfile->saves_sent_automatically) {
            $sent = Folder::query()->where('email_account_id', $account->id)->where('role', 'sent')->first();
            if ($sent !== null) {
                try {
                    $provider->appendToFolder($sent->remote_name, $rfc822, ['\\Seen']);
                } catch (ProviderError $e) {
                    report($e); // the message was delivered; only the Sent copy is missing
                }
            }
        }

        if ($message->in_reply_to_metadata_id !== null) {
            $original = MessageMetadata::query()->with('folder')->find($message->in_reply_to_metadata_id);
            if ($original !== null) {
                // On the server too, so webmail and the next sync agree.
                $provider->setFlags(new MessageRef($original->folder->remote_name, $original->uid, $original->uidvalidity, $original->provider_message_id), answered: true);
                $original->forceFill(['is_answered' => true])->save();
            }
        }

        SyncAccountJob::dispatch($account->id);
    }

    /**
     * @param  list<string>  $temporaryFiles
     * @return list<array{path: string, filename: string, mime: string}>
     */
    private function attachments(OutboxMessage $message, MailProvider $provider, array &$temporaryFiles): array
    {
        $out = [];
        foreach (OutboxAttachment::query()->where('outbox_message_id', $message->id)->get() as $a) {
            $out[] = ['path' => Storage::disk('local')->path($a->storage_path), 'filename' => $a->filename, 'mime' => $a->mime_type];
        }

        // Forward with original attachments: fetched from the provider at send time.
        if ($message->forward_of_metadata_id !== null) {
            $original = MessageMetadata::query()->with('folder', 'attachments')->find($message->forward_of_metadata_id);
            foreach ($original === null ? [] : $original->attachments as $a) {
                if ($a->is_inline) {
                    continue;
                }
                $file = tempnam(sys_get_temp_dir(), 'mbm-fwd') ?: throw new \RuntimeException('Cannot create temporary file.');
                file_put_contents($file, $provider->fetchAttachment(new MessageRef($original->folder->remote_name, $original->uid, $original->uidvalidity, $original->provider_message_id), $a->part_id));
                $temporaryFiles[] = $file;
                $out[] = ['path' => $file, 'filename' => $a->filename, 'mime' => $a->mime_type];
            }
        }

        return $out;
    }

    /**
     * @param  list<array{email: string, name: string|null}>  $list
     * @return list<Address>
     */
    private static function addresses(array $list): array
    {
        return array_map(fn (array $a) => new Address($a['email'], $a['name'] ?? null), $list);
    }
}
