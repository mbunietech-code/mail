<?php

namespace App\Mailbox\Data;

final readonly class Capabilities
{
    public function __construct(
        public bool $move = false,
        public bool $uidPlus = false,
        public bool $condStore = false,
        public bool $specialUse = false,
        public bool $idle = false,
        public bool $labels = false,
        public bool $folderManagement = true,
        public bool $serverSearch = true,
    ) {}

    /** @return array<string, bool> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
