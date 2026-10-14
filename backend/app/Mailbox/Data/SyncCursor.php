<?php

namespace App\Mailbox\Data;

final readonly class SyncCursor
{
    public function __construct(
        public ?int $uidValidity = null,
        public int $lastUid = 0,
        public ?int $highestModSeq = null,
        public ?string $historyId = null,
    ) {}

    public function isInitial(): bool
    {
        return $this->lastUid === 0 && $this->historyId === null;
    }
}
