<?php

namespace App\Mailbox\Data;

final readonly class FolderStatus
{
    public function __construct(
        public int $uidValidity,
        public int $uidNext,
        public int $messages,
        public int $unseen,
        public ?int $highestModSeq = null,
    ) {}
}
