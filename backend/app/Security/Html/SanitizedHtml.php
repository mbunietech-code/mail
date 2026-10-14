<?php

namespace App\Security\Html;

final readonly class SanitizedHtml
{
    /** @param list<string> $contentIds inline images referenced as cid: */
    public function __construct(
        public string $html,
        public int $blockedRemoteImages,
        public array $contentIds,
    ) {}
}
