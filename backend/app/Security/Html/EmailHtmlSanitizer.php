<?php

namespace App\Security\Html;

use App\Security\ImageProxySigner;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\UrlAttributeSanitizer;

/**
 * Allow-list sanitiser for untrusted email HTML (SDD §8.6, risk R-08). The output is
 * still rendered by the client without a JavaScript engine; this is the server-side
 * half of the defence.
 */
class EmailHtmlSanitizer
{
    public const MAX_INPUT_BYTES = 2_000_000;

    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'noscript', 'template',
        'svg', 'math', 'form', 'input', 'button', 'select', 'option', 'textarea', 'video', 'audio', 'source',
        'track', 'canvas', 'link', 'meta', 'base', 'title', 'head', 'dialog', 'portal',
    ];

    private const PRESENTATIONAL_ATTRIBUTES = [
        'align' => ['p', 'div', 'table', 'td', 'th', 'tr', 'img', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr'],
        'valign' => ['td', 'th', 'tr'],
        'bgcolor' => ['table', 'td', 'th', 'tr'],
        'width' => ['table', 'td', 'th', 'img', 'col', 'hr'],
        'height' => ['table', 'td', 'th', 'tr', 'img'],
        'border' => ['table', 'img'],
        'cellpadding' => ['table'],
        'cellspacing' => ['table'],
        'colspan' => ['td', 'th'],
        'rowspan' => ['td', 'th'],
        'alt' => ['img'],
        'title' => ['a', 'img', 'abbr'],
        'dir' => '*',
        'lang' => '*',
    ];

    public function __construct(private readonly ImageProxySigner $signer) {}

    /** HTML written by an employee (compose, signatures): same allow-list, images untouched. */
    public function sanitizeOutbound(string $html): string
    {
        return $this->sanitize($html, false, outbound: true)->html;
    }

    public function sanitize(string $html, bool $allowRemoteImages = false, bool $outbound = false): SanitizedHtml
    {
        $images = new ImageSourceSanitizer($allowRemoteImages, $this->signer, $outbound);

        $config = (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowRelativeLinks(false)
            ->allowMediaSchemes(['http', 'https', 'data'])
            ->allowRelativeMedias(false)
            ->allowElement('img', ['src', 'alt', 'title', 'width', 'height'])
            ->allowAttribute('style', '*')
            ->withAttributeSanitizer(new UrlAttributeGuard)
            ->withAttributeSanitizer(new StyleAttributeSanitizer)
            ->withAttributeSanitizer($images)
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->dropAttribute('target', '*')
            ->dropAttribute('id', '*')
            ->dropAttribute('name', '*')
            ->dropAttribute('class', '*')
            ->dropAttribute('srcset', '*')
            ->dropAttribute('background', '*')
            ->dropAttribute('ping', '*')
            ->dropAttribute('formaction', '*')
            ->withMaxInputLength(self::MAX_INPUT_BYTES);

        // Replace (not supplement) Symfony's URL sanitizer; removal is by instance identity.
        foreach ($config->getAttributeSanitizers() as $sanitizer) {
            if ($sanitizer instanceof UrlAttributeSanitizer) {
                $config = $config->withoutAttributeSanitizer($sanitizer);
            }
        }

        foreach (self::DROP_WITH_CONTENT as $element) {
            $config = $config->dropElement($element);
        }
        foreach (self::PRESENTATIONAL_ATTRIBUTES as $attribute => $elements) {
            $config = $config->allowAttribute($attribute, $elements);
        }

        // Strip a leading <html>/<body> wrapper's head section before parsing: email
        // HTML often carries large <style> blocks that would otherwise count to the limit.
        $input = preg_replace('#<head\b[^>]*>.*?</head>#is', '', $html) ?? '';

        $clean = (new HtmlSanitizer($config))->sanitize($input);

        return new SanitizedHtml($clean, $images->blockedRemote, array_values(array_unique($images->contentIds)));
    }
}
