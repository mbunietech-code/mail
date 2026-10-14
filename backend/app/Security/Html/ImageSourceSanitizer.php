<?php

namespace App\Security\Html;

use App\Security\ImageProxySigner;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Decides every <img src> (SRS FR-SET-002, risk R-09):
 *  - cid:…            kept; the client resolves it against the message's inline attachments
 *  - small data:image kept (PNG/GIF/JPEG/WebP only)
 *  - http(s) remote   removed unless the user consented, then rewritten to a signed
 *                     proxy URL, so the client never contacts the sender's server
 *  - anything else    removed
 * One instance is used per sanitisation pass and records what it did.
 */
final class ImageSourceSanitizer implements AttributeSanitizerInterface
{
    private const MAX_DATA_URI = 1_048_576;

    public int $blockedRemote = 0;

    /** @var list<string> */
    public array $contentIds = [];

    /**
     * @param  bool  $outbound  composing: the author's own remote images are kept as
     *                          written (never rewritten to the internal proxy)
     */
    public function __construct(
        private readonly bool $allowRemote,
        private readonly ImageProxySigner $signer,
        private readonly bool $outbound = false,
    ) {}

    public function getSupportedElements(): array
    {
        return ['img'];
    }

    public function getSupportedAttributes(): array
    {
        return ['src'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        $value = trim($value);
        $lower = strtolower($value);

        if (str_starts_with($lower, 'cid:')) {
            $cid = trim(substr($value, 4), '<> ');
            if ($cid === '' || strlen($cid) > 255 || preg_match('/[\s"\'<>]/', $cid)) {
                return null;
            }
            $this->contentIds[] = $cid;

            return 'cid:'.$cid;
        }

        if (str_starts_with($lower, 'data:')) {
            return strlen($value) <= self::MAX_DATA_URI && preg_match('#^data:image/(png|gif|jpe?g|webp);base64,[A-Za-z0-9+/=\s]+$#i', $value)
                ? $value
                : null;
        }

        if (preg_match('#^https?://#i', $value) && filter_var($value, FILTER_VALIDATE_URL) && strlen($value) <= 2048) {
            if ($this->outbound) {
                return $value;
            }
            if (! $this->allowRemote) {
                $this->blockedRemote++;

                return null;
            }

            return $this->signer->sign($value);
        }

        return null;
    }
}
