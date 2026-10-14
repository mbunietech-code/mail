<?php

namespace App\Mailbox\Fake;

use App\Mailbox\Contracts\MailProvider;
use App\Mailbox\Data\Capabilities;
use App\Mailbox\Data\ChangeSet;
use App\Mailbox\Data\FolderInfo;
use App\Mailbox\Data\FolderStatus;
use App\Mailbox\Data\MessageContent;
use App\Mailbox\Data\MessageEnvelope;
use App\Mailbox\Data\MessageRef;
use App\Mailbox\Data\OutgoingMessage;
use App\Mailbox\Data\SearchCriteria;
use App\Mailbox\Data\SyncCursor;
use App\Mailbox\ProviderError;
use App\Mailbox\ProviderErrorCode;

/**
 * In-memory provider for automated tests (TEST_PLAN §1). Never used outside tests.
 */
class FakeProvider implements MailProvider
{
    /** @var array<string, FolderInfo> */
    public array $folders = [];

    /** @var array<string, array<int, MessageEnvelope>> */
    public array $messages = [];

    /** @var array<string, array{text: ?string, html: ?string}> */
    public array $bodies = [];

    /** @var list<array{message: OutgoingMessage, rfc822: string}> */
    public array $submitted = [];

    /** @var array<string, list<string>> */
    public array $appended = [];

    /** @var array<string, ProviderError> method => error to throw */
    public array $failures = [];

    public int $uidValidity = 1;

    public function __construct(public Capabilities $caps = new Capabilities(move: true, uidPlus: true))
    {
        foreach (['INBOX' => 'inbox', 'Sent' => 'sent', 'Drafts' => 'drafts', 'Trash' => 'trash', 'Junk' => 'spam'] as $path => $role) {
            $this->folders[$path] = new FolderInfo($path, $path, $role, '.');
            $this->messages[$path] = [];
        }
    }

    public function failOn(string $method, ProviderErrorCode $code): static
    {
        $this->failures[$method] = ProviderError::of($code, "Fake {$method} failure");

        return $this;
    }

    public function addMessage(string $folder, MessageEnvelope $envelope, ?string $text = null, ?string $html = null): void
    {
        $this->messages[$folder][$envelope->uid] = $envelope;
        $this->bodies[$folder.'#'.$envelope->uid] = ['text' => $text, 'html' => $html];
    }

    private function guard(string $method): void
    {
        if (isset($this->failures[$method])) {
            throw $this->failures[$method];
        }
    }

    public function capabilities(): Capabilities
    {
        return $this->caps;
    }

    public function verify(): void
    {
        $this->guard('verify');
    }

    public function listFolders(): array
    {
        $this->guard('listFolders');

        return array_values($this->folders);
    }

    public function folderStatus(string $folder): FolderStatus
    {
        $this->guard('folderStatus');
        $messages = $this->messages[$folder] ?? throw ProviderError::of(ProviderErrorCode::MailboxGone);
        $uids = array_keys($messages);

        return new FolderStatus($this->uidValidity, ($uids === [] ? 0 : max($uids)) + 1, count($messages), count(array_filter($messages, fn ($m) => ! $m->flags->seen)));
    }

    public function fetchChanges(string $folder, SyncCursor $cursor, int $limit, ?\DateTimeInterface $windowStart = null, ?array $knownUids = null): ChangeSet
    {
        $this->guard('fetchChanges');
        $status = $this->folderStatus($folder);
        $changed = $cursor->uidValidity !== null && $cursor->uidValidity !== $status->uidValidity;
        $last = $changed ? 0 : $cursor->lastUid;

        $new = array_values(array_filter($this->messages[$folder], fn (MessageEnvelope $m) => $m->uid > $last));
        usort($new, fn ($a, $b) => $a->uid <=> $b->uid);
        $hasMore = $last > 0 && count($new) > $limit;
        $new = $last === 0 ? array_slice($new, -$limit) : array_slice($new, 0, $limit);
        $next = $new === [] ? $last : max(array_map(fn ($m) => $m->uid, $new));
        if ($last === 0) {
            $next = max($next, $status->uidNext - 1);
        }

        $flags = [];
        foreach ($knownUids ?? [] as $uid) {
            if (isset($this->messages[$folder][$uid])) {
                $flags[$uid] = $this->messages[$folder][$uid]->flags;
            }
        }

        return new ChangeSet($status, new SyncCursor($status->uidValidity, $next), $changed, $new, $flags, $knownUids === null ? null : array_keys($flags), $hasMore);
    }

    public function fetchMessage(MessageRef $ref): MessageContent
    {
        $this->guard('fetchMessage');
        $env = $this->messages[$ref->folder][$ref->uid] ?? throw ProviderError::of(ProviderErrorCode::MessageGone);
        $body = $this->bodies[$ref->folder.'#'.$ref->uid] ?? ['text' => null, 'html' => null];

        return new MessageContent($env, $body['text'], $body['html'], $env->attachments);
    }

    public function fetchAttachment(MessageRef $ref, string $partId): string
    {
        $this->guard('fetchAttachment');

        return 'fake-attachment-'.$partId;
    }

    public function setFlags(MessageRef $ref, ?bool $seen = null, ?bool $flagged = null): void
    {
        $this->guard('setFlags');
    }

    public function move(MessageRef $ref, string $toFolder): void
    {
        $this->guard('move');
        $env = $this->messages[$ref->folder][$ref->uid] ?? throw ProviderError::of(ProviderErrorCode::MessageGone);
        unset($this->messages[$ref->folder][$ref->uid]);
        $this->messages[$toFolder][$ref->uid] = $env;
    }

    public function expunge(MessageRef $ref): void
    {
        $this->guard('expunge');
        unset($this->messages[$ref->folder][$ref->uid]);
    }

    public function search(string $folder, SearchCriteria $criteria, int $limit = 100): array
    {
        $this->guard('search');

        return array_slice(array_keys(array_filter(
            $this->messages[$folder] ?? [],
            fn (MessageEnvelope $m) => $criteria->subject === null || stripos($m->subject, $criteria->subject) !== false,
        )), 0, $limit);
    }

    public function submit(OutgoingMessage $message, string $rfc822): void
    {
        $this->guard('submit');
        $this->submitted[] = ['message' => $message, 'rfc822' => $rfc822];
    }

    public function appendToFolder(string $folder, string $rfc822, array $flags = []): void
    {
        $this->guard('appendToFolder');
        $this->appended[$folder][] = $rfc822;
    }

    public function createFolder(string $path): void
    {
        $this->guard('createFolder');
        $this->folders[$path] = new FolderInfo($path, $path, 'custom', '.');
        $this->messages[$path] = [];
    }

    public function disconnect(): void {}
}
