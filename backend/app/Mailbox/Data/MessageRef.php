<?php

namespace App\Mailbox\Data;

/** Locates one message at the provider: IMAP (folder, uidvalidity, uid) or Gmail id. */
final readonly class MessageRef
{
    public function __construct(
        public string $folder,
        public int $uid,
        public int $uidValidity = 0,
        public ?string $providerId = null,
    ) {}
}
