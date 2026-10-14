<?php

namespace Tests\Unit\Mailbox;

use App\Mailbox\Data\Address;
use App\Mailbox\Data\OutgoingMessage;
use App\Mailbox\Data\SearchCriteria;
use App\Mailbox\FolderRoles;
use App\Mailbox\ImapSmtp\ErrorMapper;
use App\Mailbox\MimeComposer;
use App\Mailbox\PartDecoder;
use App\Mailbox\ProviderErrorCode;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Tests\TestCase;

class MailboxHelpersTest extends TestCase
{
    public function test_folder_roles_from_special_use_and_names(): void
    {
        $this->assertSame('inbox', FolderRoles::detect('INBOX', '.', []));
        $this->assertSame('sent', FolderRoles::detect('Whatever', '/', ['\\Sent']));
        $this->assertSame('spam', FolderRoles::detect('INBOX.Junk', '.', []));
        $this->assertSame('sent', FolderRoles::detect('INBOX.Sent Items', '.', []));
        $this->assertSame('trash', FolderRoles::detect('Deleted Items', '/', []));
        $this->assertSame('custom', FolderRoles::detect('INBOX.Projects', '.', []));
    }

    public function test_part_decoding_handles_transfer_encodings_and_charsets(): void
    {
        $this->assertSame('Héllo', PartDecoder::decode(base64_encode('Héllo'), 'base64', 'UTF-8'));
        $this->assertSame('Café = ok', PartDecoder::decode('Caf=C3=A9 =3D ok', 'quoted-printable', 'utf-8'));
        $this->assertSame('Café', PartDecoder::decode(mb_convert_encoding('Café', 'ISO-8859-1', 'UTF-8'), '8bit', 'iso-8859-1'));
    }

    public function test_snippet_strips_markup_scripts_and_quoted_replies(): void
    {
        $html = '<style>p{}</style><script>alert(1)</script><p>Hello <b>team</b>&amp; partners</p>';
        $this->assertSame('Hello team& partners', PartDecoder::snippet(null, $html));
        $this->assertSame('New text', PartDecoder::snippet("New text\n> old quoted\n>> older", null));
        $this->assertSame(200, mb_strlen((string) PartDecoder::snippet(str_repeat('a ', 500), null)));
    }

    /** SEC-T35 */
    public function test_attachment_filenames_are_made_safe(): void
    {
        $this->assertSame('passwd', PartDecoder::safeFilename('../../etc/passwd'));
        $this->assertSame('evil.exe', PartDecoder::safeFilename('C:\\Windows\\evil.exe'));
        $this->assertSame('report.pdf', PartDecoder::safeFilename("rep\x00ort.pdf"));
        $this->assertSame('attachment', PartDecoder::safeFilename('...'));
    }

    /** SEC-T14 */
    public function test_header_injection_is_impossible_in_composed_messages(): void
    {
        $raw = (new MimeComposer)->compose(new OutgoingMessage(
            from: new Address('a@mt.co.tz', 'A'),
            to: [new Address('b@mt.co.tz')],
            cc: [],
            bcc: [new Address('secret-bcc@mt.co.tz')],
            subject: "Hello\r\nBcc: attacker@evil.example",
            html: '<p>x</p>',
            text: 'x',
            messageId: 'abc@mt.co.tz',
        ));

        [$headers] = explode("\r\n\r\n", $raw, 2);
        $this->assertDoesNotMatchRegularExpression('/^Bcc:/mi', $headers);
        $this->assertStringNotContainsString('secret-bcc@mt.co.tz', $raw, 'BCC must be envelope-only');
        $this->assertStringContainsString('Message-ID: <abc@mt.co.tz>', $headers);
    }

    public function test_search_criteria_reject_control_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SearchCriteria(text: "x\r\nA1 DELETE INBOX");
    }

    /** SEC-T45 (classification part): no reply after the terminator means unknown outcome. */
    public function test_smtp_errors_are_classified_for_safe_retry(): void
    {
        $io = new TransportException('Connection to "smtp" has been closed unexpectedly.');

        $this->assertSame(ProviderErrorCode::UnknownOutcome, ErrorMapper::smtp($io, terminatorSent: true)->errorCode);
        $this->assertSame(ProviderErrorCode::Unreachable, ErrorMapper::smtp($io, terminatorSent: false)->errorCode);
        $this->assertSame(ProviderErrorCode::Rejected, ErrorMapper::smtp(new UnexpectedResponseException('550 no such user', 550), true)->errorCode);
        $this->assertSame(ProviderErrorCode::Throttled, ErrorMapper::smtp(new UnexpectedResponseException('451 try later', 451), true)->errorCode);
        $this->assertSame(ProviderErrorCode::AuthFailed, ErrorMapper::smtp(new TransportException('Failed to authenticate on SMTP server', 535), false)->errorCode);
        $this->assertSame(ProviderErrorCode::QuotaExceeded, ErrorMapper::smtp(new UnexpectedResponseException('552 mailbox full', 552), true)->errorCode);
        $this->assertTrue(ProviderErrorCode::Unreachable->isRetryable());
        $this->assertFalse(ProviderErrorCode::UnknownOutcome->isRetryable());
        $this->assertFalse(ProviderErrorCode::Rejected->isRetryable());
    }

    public function test_imap_errors_are_classified(): void
    {
        $this->assertSame(ProviderErrorCode::TlsError, ErrorMapper::imap(new RuntimeException('TLS verification failed: certificate verify failed'), 'login')->errorCode);
        $this->assertSame(ProviderErrorCode::Throttled, ErrorMapper::imap(new RuntimeException('NO [LIMIT] Too many connections'), 'select')->errorCode);
        $this->assertSame(ProviderErrorCode::Unreachable, ErrorMapper::imap(new RuntimeException('Unable to connect to ssl://1.2.3.4:993 ()'), 'login')->errorCode);
    }
}
