<?php

namespace App\Mailbox\Data;

/**
 * A fully composed message ready for submission. `from` is always the account's own
 * address (enforced by the caller; risk R-11).
 */
final readonly class OutgoingMessage
{
    /**
     * @param  list<Address>  $to
     * @param  list<Address>  $cc
     * @param  list<Address>  $bcc
     * @param  list<string>  $references
     * @param  list<array{path: string, filename: string, mime: string}>  $attachments
     */
    public function __construct(
        public Address $from,
        public array $to,
        public array $cc,
        public array $bcc,
        public string $subject,
        public ?string $html,
        public ?string $text,
        public string $messageId,
        public ?string $inReplyTo = null,
        public array $references = [],
        public array $attachments = [],
    ) {}

    /** @return list<Address> */
    public function allRecipients(): array
    {
        return [...$this->to, ...$this->cc, ...$this->bcc];
    }
}
