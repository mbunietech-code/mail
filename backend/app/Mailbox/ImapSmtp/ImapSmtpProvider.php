<?php

namespace App\Mailbox\ImapSmtp;

use App\Mailbox\Contracts\MailProvider;
use App\Mailbox\Data\Address;
use App\Mailbox\Data\AttachmentInfo;
use App\Mailbox\Data\Capabilities;
use App\Mailbox\Data\ChangeSet;
use App\Mailbox\Data\FolderInfo;
use App\Mailbox\Data\FolderStatus;
use App\Mailbox\Data\MessageContent;
use App\Mailbox\Data\MessageEnvelope;
use App\Mailbox\Data\MessageFlags;
use App\Mailbox\Data\MessageRef;
use App\Mailbox\Data\OutgoingMessage;
use App\Mailbox\Data\SearchCriteria;
use App\Mailbox\Data\SyncCursor;
use App\Mailbox\FolderRoles;
use App\Mailbox\HostGuard;
use App\Mailbox\PartDecoder;
use App\Mailbox\ProviderError;
use App\Mailbox\ProviderErrorCode;
use Carbon\CarbonImmutable;
use DirectoryTree\ImapEngine\Address as ImapAddress;
use DirectoryTree\ImapEngine\BodyStructurePart;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ListData;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Streams\ImapStream;
use DirectoryTree\ImapEngine\FolderInterface as Folder;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\Message;
use DirectoryTree\ImapEngine\MessageInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Address as MimeAddress;
use Symfony\Component\Mime\RawMessage;
use Throwable;

/**
 * Hostinger (and any standards-compliant) mailbox over IMAP + SMTP submission.
 * One instance holds at most one IMAP connection; call disconnect() when done.
 */
class ImapSmtpProvider implements MailProvider
{
    private const MAX_PART_BYTES = 30 * 1024 * 1024;

    private const SNIPPET_PART_MAX_BYTES = 256 * 1024;

    private ?Mailbox $mailbox = null;

    /** @var list<string>|null */
    private ?array $capabilityList = null;

    public function __construct(
        private readonly ImapSmtpSettings $settings,
        private readonly HostGuard $guard,
    ) {}

    // ---- Connection ---------------------------------------------------------------

    private function mailbox(): Mailbox
    {
        if ($this->mailbox !== null && $this->mailbox->connected()) {
            return $this->mailbox;
        }

        $ip = $this->guard->resolve($this->settings->imapHost);
        $mailbox = new Mailbox([
            'host' => $ip,
            'port' => $this->settings->imapPort,
            'encryption' => $this->settings->imapEncryption,
            'validate_cert' => true,
            'username' => $this->settings->username,
            'password' => $this->settings->password->reveal(),
            'timeout' => $this->settings->timeout,
            'debug' => false,
        ]);

        try {
            $mailbox->connect(new HardenedImapConnection(new ImapStream, $this->settings->imapHost));
        } catch (Throwable $e) {
            throw ErrorMapper::imap($e, 'login');
        }

        return $this->mailbox = $mailbox;
    }

    public function disconnect(): void
    {
        try {
            $this->mailbox?->disconnect();
        } catch (Throwable) {
            // Connection already gone.
        }
        $this->mailbox = null;
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function imap(string $operation, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ProviderError $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->disconnect();

            throw ErrorMapper::imap($e, $operation);
        }
    }

    private function folder(string $path): Folder
    {
        return $this->imap('select', function () use ($path) {
            $folder = $this->mailbox()->folders()->find($path);
            if ($folder === null) {
                throw ProviderError::of(ProviderErrorCode::MailboxGone, 'Folder not found.');
            }

            return $folder;
        });
    }

    // ---- Discovery ----------------------------------------------------------------

    public function capabilities(): Capabilities
    {
        $caps = $this->capabilityList ??= $this->imap('capability', fn () => array_map('strtoupper', $this->mailbox()->capabilities()));

        return new Capabilities(
            move: in_array('MOVE', $caps, true),
            uidPlus: in_array('UIDPLUS', $caps, true),
            condStore: in_array('CONDSTORE', $caps, true),
            specialUse: in_array('SPECIAL-USE', $caps, true),
            idle: in_array('IDLE', $caps, true),
        );
    }

    public function verify(): void
    {
        $this->capabilities();
        $this->listFolders();
        $this->withSmtp('verify', fn (TrackingEsmtpTransport $t) => $t->start());
    }

    public function listFolders(): array
    {
        return $this->imap('list', function () {
            $folders = [];
            foreach ($this->mailbox()->folders()->get() as $folder) {
                $flags = $folder->flags();
                $lower = array_map('strtolower', $flags);
                $folders[] = new FolderInfo(
                    path: $folder->path(),
                    name: $folder->name(),
                    role: FolderRoles::detect($folder->path(), $folder->delimiter(), $flags),
                    delimiter: $folder->delimiter(),
                    selectable: ! in_array('\\noselect', $lower, true) && ! in_array('\\nonexistent', $lower, true),
                    flags: array_values($flags),
                );
            }

            return $folders;
        });
    }

    public function folderStatus(string $folder): FolderStatus
    {
        $status = $this->imap('status', fn () => $this->folder($folder)->status());

        return new FolderStatus(
            uidValidity: (int) ($status['UIDVALIDITY'] ?? 0),
            uidNext: (int) ($status['UIDNEXT'] ?? 0),
            messages: (int) ($status['MESSAGES'] ?? 0),
            unseen: (int) ($status['UNSEEN'] ?? 0),
            highestModSeq: isset($status['HIGHESTMODSEQ']) ? (int) $status['HIGHESTMODSEQ'] : null,
        );
    }

    // ---- Synchronisation ----------------------------------------------------------

    public function fetchChanges(string $folder, SyncCursor $cursor, int $limit, ?\DateTimeInterface $windowStart = null, ?array $knownUids = null): ChangeSet
    {
        $status = $this->folderStatus($folder);
        $uidValidityChanged = $cursor->uidValidity !== null && $cursor->uidValidity !== $status->uidValidity;
        $lastUid = $uidValidityChanged ? 0 : $cursor->lastUid;
        $box = $this->folder($folder);

        return $this->imap('fetch', function () use ($box, $status, $lastUid, $limit, $windowStart, $knownUids, $uidValidityChanged) {
            $query = $box->messages()->leaveUnread()->withHeaders()->withFlags()->withSize()->withBodyStructure();

            if ($lastUid === 0) {
                // Initial sync: newest messages inside the window only (SRS FR-SYNC-001).
                if ($windowStart !== null) {
                    $query->since($windowStart);
                }
                $messages = $query->setFetchOrderDesc()->limit($limit)->get();
                $hasMore = false;
            } else {
                $query->setFetchOrderAsc()->limit($limit);
                $query->uid($lastUid + 1, INF);
                $messages = $query->get();
                $hasMore = $messages->total() > $limit;
            }

            /** @var list<Message> $fresh */
            $fresh = array_values(array_filter($messages->all(), fn (MessageInterface $m) => $m instanceof Message && $m->uid() > $lastUid));
            usort($fresh, fn (Message $a, Message $b) => $a->uid() <=> $b->uid());

            $envelopes = $this->buildEnvelopes($box, $fresh);

            $maxSeen = $fresh === [] ? $lastUid : max(array_map(fn (Message $m) => $m->uid(), $fresh));
            $nextLast = $lastUid === 0 ? max($maxSeen, $status->uidNext - 1) : $maxSeen;

            [$flagUpdates, $present] = $knownUids !== null && $knownUids !== [] && ! $uidValidityChanged
                ? $this->refreshKnown($box, $knownUids)
                : [[], $knownUids === null ? null : []];

            return new ChangeSet(
                status: $status,
                nextCursor: new SyncCursor($status->uidValidity, max(0, $nextLast), $status->highestModSeq),
                uidValidityChanged: $uidValidityChanged,
                newMessages: $envelopes,
                flagUpdates: $flagUpdates,
                presentUids: $present,
                hasMore: $hasMore,
            );
        });
    }

    /**
     * Current flags of cached UIDs; UIDs missing from the reply were expunged.
     *
     * @param  list<int>  $uids
     * @return array{0: array<int, MessageFlags>, 1: list<int>}
     */
    private function refreshKnown(Folder $box, array $uids): array
    {
        $box->select();
        $flags = [];
        foreach (array_chunk($uids, 500) as $chunk) {
            foreach ($this->mailbox()->connection()->fetch(['FLAGS'], $chunk) as $response) {
                $data = $response instanceof UntaggedResponse ? $response->tokenAt(3) : null;
                if (! $data instanceof ListData) {
                    continue;
                }
                $uid = (int) $data->lookup('UID')?->value;
                $flags[$uid] = MessageFlags::fromImap(array_map('strval', $data->lookup('FLAGS')?->values() ?? []));
            }
        }

        return [$flags, array_keys($flags)];
    }

    /**
     * @param  list<Message>  $messages
     * @return list<MessageEnvelope>
     */
    private function buildEnvelopes(Folder $box, array $messages): array
    {
        if ($messages === []) {
            return [];
        }

        $internalDates = $this->internalDates($box, array_map(fn (Message $m) => $m->uid(), $messages));
        $snippets = $this->snippets($box, $messages);

        return array_map(fn (Message $m) => $this->envelope($m, $internalDates[$m->uid()] ?? null, $snippets[$m->uid()] ?? null), $messages);
    }

    /**
     * @param  list<int>  $uids
     * @return array<int, CarbonImmutable>
     */
    private function internalDates(Folder $box, array $uids): array
    {
        $box->select();
        $dates = [];
        foreach ($this->mailbox()->connection()->fetch(['INTERNALDATE'], $uids) as $response) {
            $data = $response instanceof UntaggedResponse ? $response->tokenAt(3) : null;
            if (! $data instanceof ListData) {
                continue;
            }
            try {
                $dates[(int) $data->lookup('UID')?->value] = CarbonImmutable::parse((string) $data->lookup('INTERNALDATE')?->value)->utc();
            } catch (Throwable) {
                // Leave unset; the Date header is used instead.
            }
        }

        return $dates;
    }

    /**
     * Fetches only the first readable text part of each message (bounded size), grouped
     * by part number so a batch needs few round trips.
     *
     * @param  list<Message>  $messages
     * @return array<int, string|null>
     */
    private function snippets(Folder $box, array $messages): array
    {
        $byPart = [];
        $parts = [];
        foreach ($messages as $m) {
            $part = $this->previewPart($m);
            if ($part !== null && ($part->size() ?? 0) <= self::SNIPPET_PART_MAX_BYTES) {
                $byPart[$part->partNumber()][] = $m->uid();
                $parts[$m->uid()] = $part;
            }
        }

        $box->select();
        $snippets = [];
        foreach ($byPart as $partNumber => $uids) {
            foreach ($this->mailbox()->connection()->bodyPart((string) $partNumber, $uids, true) as $response) {
                $data = $response instanceof UntaggedResponse ? $response->tokenAt(3) : null;
                if (! $data instanceof ListData) {
                    continue;
                }
                $uid = (int) $data->lookup('UID')?->value;
                $raw = (string) ($data->lookup("[{$partNumber}]")->value ?? '');
                $part = $parts[$uid] ?? null;
                if ($part === null) {
                    continue;
                }
                $decoded = PartDecoder::decode($raw, $part->encoding(), $part->charset() ?? 'UTF-8');
                $snippets[$uid] = $part->isHtml() ? PartDecoder::snippet(null, $decoded) : PartDecoder::snippet($decoded, null);
            }
        }

        return $snippets;
    }

    private function previewPart(Message $m): ?BodyStructurePart
    {
        $structure = $m->bodyStructure();
        if ($structure === null) {
            return null;
        }

        $html = null;
        foreach ($structure->flatten() as $part) {
            if ($part->isAttachment()) {
                continue;
            }
            if ($part->isText()) {
                return $part;
            }
            if ($part->isHtml() && $html === null) {
                $html = $part;
            }
        }

        return $html;
    }

    private function envelope(Message $m, ?CarbonImmutable $internalDate, ?string $snippet): MessageEnvelope
    {
        $date = $m->date();
        $sentAt = $date !== null ? CarbonImmutable::instance($date)->utc() : null;

        return new MessageEnvelope(
            uid: $m->uid(),
            providerId: null,
            messageId: self::bareId($m->messageId()),
            inReplyTo: self::bareId($m->inReplyTo()[0] ?? null),
            references: self::idList((string) $m->header('References')?->getRawValue()),
            from: self::address($m->from()),
            to: array_values(array_filter(array_map(self::address(...), $m->to()))),
            cc: array_values(array_filter(array_map(self::address(...), $m->cc()))),
            replyTo: ($replyTo = self::address($m->replyTo())) !== null ? [$replyTo] : [],
            subject: mb_substr((string) $m->subject(), 0, 998),
            sentAt: $sentAt,
            internalDate: $internalDate ?? $sentAt ?? CarbonImmutable::now('UTC'),
            size: (int) ($m->size() ?? 0),
            flags: MessageFlags::fromImap(array_map('strval', $m->flags())),
            attachments: $this->attachmentsOf($m),
            snippet: $snippet,
            authenticationResults: $m->header('Authentication-Results')?->getRawValue(),
        );
    }

    /** @return list<AttachmentInfo> */
    private function attachmentsOf(Message $m): array
    {
        $out = [];
        foreach ($m->bodyStructure()?->flatten() ?? [] as $part) {
            $isInlineImage = $part->isInline() && $part->id() !== null && $part->type() === 'image';
            if (! $part->isAttachment() && ! $isInlineImage) {
                continue;
            }
            $out[] = new AttachmentInfo(
                partId: $part->partNumber(),
                filename: PartDecoder::safeFilename($part->filename()),
                mimeType: strtolower($part->contentType()),
                size: (int) ($part->size() ?? 0),
                contentId: self::bareId($part->id()),
                isInline: $isInlineImage,
                encoding: $part->encoding(),
            );
        }

        return $out;
    }

    // ---- Reading ------------------------------------------------------------------

    private function message(MessageRef $ref): Message
    {
        $box = $this->folder($ref->folder);
        if ($ref->uidValidity !== 0 && $this->folderStatus($ref->folder)->uidValidity !== $ref->uidValidity) {
            throw ProviderError::of(ProviderErrorCode::MessageGone, 'UIDVALIDITY changed.');
        }

        $message = $this->imap('fetch', fn () => $box->messages()->leaveUnread()->withHeaders()->withFlags()->withSize()->withBodyStructure()->find($ref->uid));
        if (! $message instanceof Message) {
            throw ProviderError::of(ProviderErrorCode::MessageGone, 'Message not found.');
        }

        return $message;
    }

    public function fetchMessage(MessageRef $ref): MessageContent
    {
        $message = $this->message($ref);

        return $this->imap('fetch', function () use ($message) {
            $text = null;
            $html = null;
            foreach ($message->bodyStructure()?->flatten() ?? [] as $part) {
                if ($part->isAttachment() || ($part->size() ?? 0) > self::MAX_PART_BYTES) {
                    continue;
                }
                if ($text === null && $part->isText()) {
                    $text = PartDecoder::decode((string) $message->bodyPart($part->partNumber()), $part->encoding(), $part->charset() ?? 'UTF-8');
                } elseif ($html === null && $part->isHtml()) {
                    $html = PartDecoder::decode((string) $message->bodyPart($part->partNumber()), $part->encoding(), $part->charset() ?? 'UTF-8');
                }
            }

            $envelope = $this->envelope($message, null, PartDecoder::snippet($text, $html));

            return new MessageContent($envelope, $text, $html, $envelope->attachments);
        });
    }

    public function fetchAttachment(MessageRef $ref, string $partId): string
    {
        if (! preg_match('/^\d+(\.\d+)*$/', $partId)) {
            throw ProviderError::of(ProviderErrorCode::MessageGone, 'Invalid part.');
        }

        $message = $this->message($ref);

        return $this->imap('fetch', function () use ($message, $partId) {
            foreach ($message->bodyStructure()?->flatten() ?? [] as $part) {
                if ($part->partNumber() !== $partId) {
                    continue;
                }
                if (($part->size() ?? 0) > self::MAX_PART_BYTES) {
                    throw ProviderError::of(ProviderErrorCode::NotSupported, 'Attachment too large.');
                }

                return PartDecoder::decode((string) $message->bodyPart($partId), $part->encoding());
            }

            throw ProviderError::of(ProviderErrorCode::MessageGone, 'Attachment part not found.');
        });
    }

    // ---- Mutations ----------------------------------------------------------------

    public function setFlags(MessageRef $ref, ?bool $seen = null, ?bool $flagged = null): void
    {
        $box = $this->folder($ref->folder);
        $this->imap('store', function () use ($box, $ref, $seen, $flagged) {
            $box->select();
            $connection = $this->mailbox()->connection();
            foreach (['\\Seen' => $seen, '\\Flagged' => $flagged] as $flag => $value) {
                if ($value !== null) {
                    $connection->store([$flag], $ref->uid, null, $value ? '+' : '-');
                }
            }
        });
    }

    public function move(MessageRef $ref, string $toFolder): void
    {
        $this->folder($toFolder); // must exist
        $message = $this->message($ref);
        $caps = $this->capabilities();

        $this->imap('move', function () use ($message, $toFolder, $caps) {
            if ($caps->move || $caps->uidPlus) {
                $message->move($toFolder, true);

                return;
            }
            // Without MOVE/UIDPLUS a targeted expunge is impossible; copy and flag only.
            $message->copy($toFolder);
            $message->flag('\\Deleted', '+');
        });
    }

    public function expunge(MessageRef $ref): void
    {
        $message = $this->message($ref);
        $this->imap('expunge', fn () => $message->delete(true));
    }

    public function search(string $folder, SearchCriteria $criteria, int $limit = 100): array
    {
        if ($criteria->isEmpty()) {
            return [];
        }
        $box = $this->folder($folder);

        return $this->imap('search', function () use ($box, $criteria, $limit) {
            $query = $box->messages()->leaveUnread()->withoutHeaders()->withoutFlags()->withoutBody()->withoutSize()->withoutBodyStructure();
            if ($criteria->text !== null) {
                $query->text($criteria->text);
            }
            if ($criteria->from !== null) {
                $query->from($criteria->from);
            }
            if ($criteria->to !== null) {
                $query->to($criteria->to);
            }
            if ($criteria->subject !== null) {
                $query->subject($criteria->subject);
            }
            if ($criteria->since !== null) {
                $query->since($criteria->since);
            }
            if ($criteria->before !== null) {
                $query->before($criteria->before);
            }
            if ($criteria->unseen !== null) {
                $criteria->unseen ? $query->unseen() : $query->seen();
            }
            if ($criteria->flagged !== null) {
                $criteria->flagged ? $query->flagged() : $query->unflagged();
            }

            return array_values(array_map(fn (MessageInterface $m) => $m->uid(), $query->setFetchOrderDesc()->limit($limit)->get()->all()));
        });
    }

    public function appendToFolder(string $folder, string $rfc822, array $flags = []): void
    {
        $this->folder($folder);
        $this->imap('append', fn () => $this->mailbox()->connection()->append($folder, $rfc822, $flags));
    }

    public function createFolder(string $path): void
    {
        if ($path === '' || mb_strlen($path) > 100 || preg_match('/[\x00-\x1F\x7F*%]/', $path)) {
            throw ProviderError::of(ProviderErrorCode::NotSupported, 'Invalid folder name.');
        }
        $this->imap('create', fn () => $this->mailbox()->folders()->create($path));
    }

    // ---- Sending ------------------------------------------------------------------

    public function submit(OutgoingMessage $message, string $rfc822): void
    {
        $envelope = new Envelope(
            new MimeAddress($message->from->email),
            array_map(fn (Address $a) => new MimeAddress($a->email), $message->allRecipients()),
        );

        $this->withSmtp('submit', fn (TrackingEsmtpTransport $t) => $t->send(new RawMessage($rfc822), $envelope));
    }

    /**
     * @param  callable(TrackingEsmtpTransport): mixed  $callback
     */
    private function withSmtp(string $operation, callable $callback): void
    {
        $ip = $this->guard->resolve($this->settings->smtpHost);
        $implicitTls = $this->settings->smtpEncryption === 'ssl';

        $transport = new TrackingEsmtpTransport($ip, $this->settings->smtpPort, $implicitTls);
        if (! $implicitTls) {
            $transport->setRequireTls(true); // STARTTLS mandatory, never opportunistic
        }
        $stream = $transport->getStream();
        if (! $stream instanceof SocketStream) {
            throw ProviderError::of(ProviderErrorCode::ProtocolError, 'Unexpected SMTP stream type.');
        }
        $stream->setStreamOptions(TlsPolicy::streamContext($this->settings->smtpHost));
        $stream->setTimeout($this->settings->timeout);
        $transport->setLocalDomain((string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost'));
        $transport->setUsername($this->settings->username);
        $transport->setPassword($this->settings->password->reveal());

        try {
            $callback($transport);
        } catch (TransportExceptionInterface $e) {
            throw ErrorMapper::smtp($e, $transport->terminatorSent, $operation);
        } finally {
            try {
                $transport->stop();
            } catch (Throwable) {
                // ignore
            }
        }
    }

    // ---- Helpers ------------------------------------------------------------------

    private static function address(?ImapAddress $address): ?Address
    {
        if ($address === null || $address->email() === '') {
            return null;
        }

        return new Address(strtolower($address->email()), $address->name() ?: null);
    }

    private static function bareId(?string $id): ?string
    {
        $id = $id === null ? '' : trim($id, " <>\t\r\n");

        return $id === '' ? null : mb_substr($id, 0, 255);
    }

    /** @return list<string> */
    private static function idList(string $raw): array
    {
        preg_match_all('/<([^<>\s]+)>/', $raw, $m);

        return array_slice(array_values(array_unique($m[1])), -50);
    }
}
