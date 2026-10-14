<?php

namespace App\Mailbox\Data;

use Carbon\CarbonImmutable;

/**
 * Metadata needed for message lists and threading — never the full body.
 */
final readonly class MessageEnvelope
{
    /**
     * @param  list<string>  $references
     * @param  list<Address>  $to
     * @param  list<Address>  $cc
     * @param  list<Address>  $replyTo
     * @param  list<AttachmentInfo>  $attachments
     */
    public function __construct(
        public int $uid,
        public ?string $providerId,
        public ?string $messageId,
        public ?string $inReplyTo,
        public array $references,
        public ?Address $from,
        public array $to,
        public array $cc,
        public array $replyTo,
        public string $subject,
        public ?CarbonImmutable $sentAt,
        public CarbonImmutable $internalDate,
        public int $size,
        public MessageFlags $flags,
        public array $attachments = [],
        public ?string $snippet = null,
        public ?string $providerThreadId = null,
        public ?string $authenticationResults = null,
    ) {}

    public function hasAttachments(): bool
    {
        foreach ($this->attachments as $attachment) {
            if (! $attachment->isInline) {
                return true;
            }
        }

        return false;
    }
}
