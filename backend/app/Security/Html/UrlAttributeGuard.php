<?php

namespace App\Security\Html;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\TextSanitizer\UrlSanitizer;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Same checks as Symfony's built-in UrlAttributeSanitizer (via its vetted UrlSanitizer),
 * except <img src>, which is delegated to ImageSourceSanitizer so that inline `cid:`
 * images survive (the built-in parser rejects them). Every other URL-bearing attribute
 * is validated exactly as before.
 */
final class UrlAttributeGuard implements AttributeSanitizerInterface
{
    public function getSupportedElements(): ?array
    {
        return null;
    }

    public function getSupportedAttributes(): array
    {
        return ['src', 'href', 'lowsrc', 'background', 'ping', 'action', 'formaction', 'poster', 'cite', 'data', 'codebase', 'archive', 'longdesc'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        if ($element === 'img' && $attribute === 'src') {
            return $value; // decided by ImageSourceSanitizer
        }

        if (in_array($element, ['a', 'area'], true) || in_array($attribute, ['action', 'formaction', 'cite'], true)) {
            return UrlSanitizer::sanitize($value, $config->getAllowedLinkSchemes(), $config->getForceHttpsUrls(), $config->getAllowedLinkHosts(), $config->getAllowRelativeLinks());
        }

        return UrlSanitizer::sanitize($value, $config->getAllowedMediaSchemes(), $config->getForceHttpsUrls(), $config->getAllowedMediaHosts(), $config->getAllowRelativeMedias());
    }
}
