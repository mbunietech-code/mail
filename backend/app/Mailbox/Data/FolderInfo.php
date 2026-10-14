<?php

namespace App\Mailbox\Data;

final readonly class FolderInfo
{
    /** @param list<string> $flags */
    public function __construct(
        public string $path,
        public string $name,
        public string $role,
        public ?string $delimiter = null,
        public bool $selectable = true,
        public array $flags = [],
    ) {}
}
