<?php

namespace App\Mailbox\Data;

final readonly class AttachmentInfo
{
    public function __construct(
        public string $partId,
        public string $filename,
        public string $mimeType,
        public int $size,
        public ?string $contentId = null,
        public bool $isInline = false,
        public ?string $encoding = null,
    ) {}
}
