<?php

namespace App\Mailbox\Data;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Structured search request. Values are validated here and passed to the provider as
 * quoted arguments — never concatenated into raw protocol commands (risk R-07).
 */
final readonly class SearchCriteria
{
    public function __construct(
        public ?string $text = null,
        public ?string $from = null,
        public ?string $to = null,
        public ?string $subject = null,
        public ?CarbonImmutable $since = null,
        public ?CarbonImmutable $before = null,
        public ?bool $unseen = null,
        public ?bool $flagged = null,
    ) {
        foreach (['text' => $text, 'from' => $from, 'to' => $to, 'subject' => $subject] as $field => $value) {
            if ($value !== null && (mb_strlen($value) > 200 || preg_match('/[\x00\r\n]/', $value))) {
                throw new InvalidArgumentException("Invalid search value for {$field}.");
            }
        }
    }

    public function isEmpty(): bool
    {
        return $this->text === null && $this->from === null && $this->to === null && $this->subject === null
            && $this->since === null && $this->before === null && $this->unseen === null && $this->flagged === null;
    }
}
