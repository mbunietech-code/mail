<?php

namespace App\Mailbox\Data;

/**
 * Result of one incremental sync step for a folder.
 */
final readonly class ChangeSet
{
    /**
     * @param  list<MessageEnvelope>  $newMessages  messages newer than the cursor (ascending)
     * @param  array<int, MessageFlags>  $flagUpdates  current flags of already-known UIDs (uid => flags)
     * @param  list<int>|null  $presentUids  all UIDs at or below the cursor that still exist (null = not computed)
     */
    public function __construct(
        public FolderStatus $status,
        public SyncCursor $nextCursor,
        public bool $uidValidityChanged,
        public array $newMessages,
        public array $flagUpdates,
        public ?array $presentUids,
        public bool $hasMore,
    ) {}
}
