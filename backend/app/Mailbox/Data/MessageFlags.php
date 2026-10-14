<?php

namespace App\Mailbox\Data;

final readonly class MessageFlags
{
    public function __construct(
        public bool $seen = false,
        public bool $flagged = false,
        public bool $answered = false,
        public bool $draft = false,
    ) {}

    /** @param list<string> $imapFlags */
    public static function fromImap(array $imapFlags): self
    {
        $flags = array_map('strtolower', $imapFlags);

        return new self(
            in_array('\\seen', $flags, true),
            in_array('\\flagged', $flags, true),
            in_array('\\answered', $flags, true),
            in_array('\\draft', $flags, true),
        );
    }
}
