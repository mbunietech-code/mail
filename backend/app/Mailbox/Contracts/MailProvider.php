<?php

namespace App\Mailbox\Contracts;

use App\Mailbox\Data\Capabilities;
use App\Mailbox\Data\ChangeSet;
use App\Mailbox\Data\FolderInfo;
use App\Mailbox\Data\FolderStatus;
use App\Mailbox\Data\MessageContent;
use App\Mailbox\Data\MessageRef;
use App\Mailbox\Data\OutgoingMessage;
use App\Mailbox\Data\SearchCriteria;
use App\Mailbox\Data\SyncCursor;
use App\Mailbox\ProviderError;

/**
 * Anti-corruption boundary between MbunieMail and a mail provider (SDD §5.3).
 * Implementations: ImapSmtpProvider (Hostinger), GmailProvider, FakeProvider (tests).
 * Every method throws ProviderError on failure.
 */
interface MailProvider
{
    public function capabilities(): Capabilities;

    /** Proves the stored credential can both read and send. */
    public function verify(): void;

    /** @return list<FolderInfo> */
    public function listFolders(): array;

    public function folderStatus(string $folder): FolderStatus;

    /**
     * Next batch of changes after $cursor. On the initial sync only the newest
     * $limit messages since $windowStart are returned.
     *
     * @param  list<int>|null  $knownUids  UIDs already cached, for flag refresh and expunge detection
     */
    public function fetchChanges(string $folder, SyncCursor $cursor, int $limit, ?\DateTimeInterface $windowStart = null, ?array $knownUids = null): ChangeSet;

    public function fetchMessage(MessageRef $ref): MessageContent;

    /**
     * Decoded bytes of one attachment part.
     */
    public function fetchAttachment(MessageRef $ref, string $partId): string;

    public function setFlags(MessageRef $ref, ?bool $seen = null, ?bool $flagged = null): void;

    public function move(MessageRef $ref, string $toFolder): void;

    /** Permanently removes the message (callers move to Trash first for normal deletes). */
    public function expunge(MessageRef $ref): void;

    /** @return list<int|string> UIDs (IMAP) or provider ids (Gmail) */
    public function search(string $folder, SearchCriteria $criteria, int $limit = 100): array;

    /**
     * Submits for delivery. Throws ProviderError: Rejected (permanent), UnknownOutcome
     * (dropped after DATA — must NOT be retried automatically), or a retryable code if
     * the failure happened before the message data was sent.
     */
    public function submit(OutgoingMessage $message, string $rfc822): void;

    /** @param list<string> $flags */
    public function appendToFolder(string $folder, string $rfc822, array $flags = []): void;

    public function createFolder(string $path): void;

    public function disconnect(): void;
}
