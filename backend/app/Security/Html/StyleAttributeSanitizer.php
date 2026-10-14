<?php

namespace App\Security\Html;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Keeps only presentational CSS declarations from inline `style` attributes. Anything
 * that can load a resource (url(), @import, image-set) or execute (expression,
 * behavior, -moz-binding) is removed, so CSS cannot be used for tracking or XSS.
 */
final class StyleAttributeSanitizer implements AttributeSanitizerInterface
{
    private const ALLOWED_PROPERTIES = [
        'color', 'background-color', 'font', 'font-family', 'font-size', 'font-style', 'font-weight', 'font-variant',
        'text-align', 'text-decoration', 'text-indent', 'text-transform', 'letter-spacing', 'word-spacing', 'line-height',
        'white-space', 'vertical-align', 'direction',
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'border', 'border-top', 'border-right', 'border-bottom', 'border-left', 'border-color', 'border-style', 'border-width',
        'border-collapse', 'border-spacing', 'border-radius',
        'width', 'max-width', 'min-width', 'height', 'max-height', 'min-height',
        'display', 'list-style-type', 'table-layout',
    ];

    private const FORBIDDEN_VALUE = '/url\s*\(|expression\s*\(|image-set|javascript:|vbscript:|@import|behavior|-moz-binding|\\\\|&#|<|>/i';

    public function getSupportedElements(): ?array
    {
        return null;
    }

    public function getSupportedAttributes(): array
    {
        return ['style'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        $kept = [];
        foreach (explode(';', $value) as $declaration) {
            if (! str_contains($declaration, ':')) {
                continue;
            }
            [$property, $propertyValue] = array_map('trim', explode(':', $declaration, 2));
            $property = strtolower($property);

            if (! in_array($property, self::ALLOWED_PROPERTIES, true)
                || $propertyValue === ''
                || mb_strlen($propertyValue) > 200
                || preg_match(self::FORBIDDEN_VALUE, $propertyValue)
                || ($property === 'display' && ! preg_match('/^(none|block|inline|inline-block|table|table-row|table-cell)$/i', $propertyValue))) {
                continue;
            }
            $kept[] = $property.': '.$propertyValue;
        }

        return $kept === [] ? null : implode('; ', $kept);
    }
}
