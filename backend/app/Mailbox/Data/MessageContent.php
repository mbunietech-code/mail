<?php

namespace App\Mailbox\Data;

/**
 * Decoded message body for reading. HTML here is UNTRUSTED and must be sanitised
 * before it leaves the server (SDD §8.6).
 */
final readonly class MessageContent
{
    /** @param list<AttachmentInfo> $attachments */
    public function __construct(
        public MessageEnvelope $envelope,
        public ?string $text,
        public ?string $html,
        public array $attachments,
    ) {}
}
