<?php

namespace Tests\Integration;

use App\Mailbox\Data\Address;
use App\Mailbox\Data\MessageRef;
use App\Mailbox\Data\OutgoingMessage;
use App\Mailbox\Data\SearchCriteria;
use App\Mailbox\Data\SyncCursor;
use App\Mailbox\HostGuard;
use App\Mailbox\ImapSmtp\ImapSmtpProvider;
use App\Mailbox\ImapSmtp\ImapSmtpSettings;
use App\Mailbox\MimeComposer;
use App\Mailbox\ProviderError;
use App\Mailbox\ProviderErrorCode;
use App\Security\Secret;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Real IMAP/SMTP over TLS against the local GreenMail fake server
 * (infra/docker-compose.dev.yml). Never touches real mailboxes. Skipped when
 * GreenMail or its test CA is not available.
 */
#[Group('integration')]
class ImapSmtpGreenMailTest extends TestCase
{
    protected bool $seed = false;

    private string $password;

    protected function setUp(): void
    {
        parent::setUp();

        $this->password = (string) env('GREENMAIL_PASSWORD', '');
        $ca = (string) config('mbuniemail.providers.test_ca_file');
        if ($this->password === '' || ! is_readable($ca) || ! @fsockopen('127.0.0.1', 3993, $errno, $errstr, 1)) {
            $this->markTestSkipped('GreenMail (infra/docker-compose.dev.yml) and its dev CA are required.');
        }
        config(['mbuniemail.providers.allow_loopback_hosts' => true]);
    }

    private function provider(string $user = 'test1', ?string $password = null, int $imapPort = 3993, string $imapEnc = 'ssl', int $smtpPort = 3465, string $smtpEnc = 'ssl'): ImapSmtpProvider
    {
        return new ImapSmtpProvider(new ImapSmtpSettings(
            'localhost', $imapPort, $imapEnc, 'localhost', $smtpPort, $smtpEnc,
            "{$user}@mt.co.tz", new Secret($password ?? $this->password), 5,
        ), new HostGuard);
    }

    private function send(ImapSmtpProvider $from, string $subject, array $attachments = []): string
    {
        $id = Str::ulid().'@mt.co.tz';
        $message = new OutgoingMessage(
            new Address('test1@mt.co.tz', 'Test One'),
            [new Address('test2@mt.co.tz', 'Test Two')], [], [],
            $subject, '<p>Hello <b>integration</b></p>', "Hello integration\n> quoted", $id,
            attachments: $attachments,
        );
        $from->submit($message, (new MimeComposer)->compose($message));

        return $id;
    }

    /** Waits for a message with $subject to appear in test2's INBOX. */
    private function awaitUid(ImapSmtpProvider $inbox, string $subject): int
    {
        for ($i = 0; $i < 25; $i++) {
            $uids = $inbox->search('INBOX', new SearchCriteria(subject: $subject));
            if ($uids !== []) {
                return (int) $uids[0];
            }
            usleep(200_000);
        }
        $this->fail("Message '{$subject}' was not delivered.");
    }

    public function test_verify_succeeds_over_verified_tls(): void
    {
        $this->provider()->verify();
        $this->assertTrue($this->provider()->capabilities()->move);
    }

    public function test_wrong_password_is_auth_failed(): void
    {
        try {
            $this->provider(password: 'definitely-wrong')->verify();
            $this->fail('login should fail');
        } catch (ProviderError $e) {
            $this->assertSame(ProviderErrorCode::AuthFailed, $e->errorCode);
        }
    }

    /** NFR-SEC-001: an untrusted certificate is never accepted. */
    public function test_untrusted_certificate_is_rejected_as_tls_error(): void
    {
        config(['mbuniemail.providers.test_ca_file' => null]);

        foreach (['imap' => fn () => $this->provider()->verify(), 'smtp' => fn () => $this->send($this->provider(), 'x')] as $label => $call) {
            try {
                $call();
                $this->fail("{$label} accepted an untrusted certificate");
            } catch (ProviderError $e) {
                $this->assertSame(ProviderErrorCode::TlsError, $e->errorCode, $label);
            }
        }
    }

    /** STARTTLS is mandatory: a server that does not offer it is refused, never used in plaintext. */
    public function test_no_downgrade_to_plaintext_when_starttls_is_unavailable(): void
    {
        try {
            $this->send($this->provider(smtpPort: 3025, smtpEnc: 'starttls'), 'plaintext attempt');
            $this->fail('SMTP sent without TLS');
        } catch (ProviderError $e) {
            $this->assertNotSame(ProviderErrorCode::UnknownOutcome, $e->errorCode);
        }

        try {
            $this->provider(imapPort: 3143, imapEnc: 'starttls')->listFolders();
            $this->fail('IMAP logged in without TLS');
        } catch (ProviderError) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_send_receive_sync_read_and_attachment_round_trip(): void
    {
        $subject = 'Round trip '.Str::random(8);
        $file = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($file, "%PDF-1.4 integration bytes \x00\x01");
        $messageId = $this->send($this->provider(), $subject, [['path' => $file, 'filename' => 'report.pdf', 'mime' => 'application/pdf']]);
        @unlink($file);

        $inbox = $this->provider('test2');
        $uid = $this->awaitUid($inbox, $subject);
        $status = $inbox->folderStatus('INBOX');

        $changes = $inbox->fetchChanges('INBOX', new SyncCursor($status->uidValidity, $uid - 1), 50);
        $envelope = collect($changes->newMessages)->firstWhere('uid', $uid);
        $this->assertNotNull($envelope);
        $this->assertSame($subject, $envelope->subject);
        $this->assertSame($messageId, $envelope->messageId);
        $this->assertSame('test1@mt.co.tz', $envelope->from?->email);
        $this->assertSame('Hello integration', $envelope->snippet, 'quoted lines excluded');
        $this->assertTrue($envelope->hasAttachments());
        $this->assertFalse($envelope->flags->seen, 'sync must not mark messages read');

        $content = $inbox->fetchMessage(new MessageRef('INBOX', $uid, $status->uidValidity));
        $this->assertStringContainsString('Hello integration', (string) $content->text);
        $this->assertStringContainsString('<b>integration</b>', (string) $content->html);
        $this->assertSame("%PDF-1.4 integration bytes \x00\x01", $inbox->fetchAttachment(new MessageRef('INBOX', $uid), $content->attachments[0]->partId));
        $this->assertSame('report.pdf', $content->attachments[0]->filename);

        $this->assertFalse($inbox->fetchChanges('INBOX', $changes->nextCursor, 50, null, [$uid])->flagUpdates[$uid]->seen, 'reading must not mark read');
    }

    public function test_incremental_sync_flags_move_and_expunge_detection(): void
    {
        $sender = $this->provider();
        $inbox = $this->provider('test2');

        $this->send($sender, $s1 = 'Incremental A '.Str::random(6));
        $uid1 = $this->awaitUid($inbox, $s1);
        $status = $inbox->folderStatus('INBOX');
        $cursor = new SyncCursor($status->uidValidity, $uid1);

        $none = $inbox->fetchChanges('INBOX', $cursor, 50, null, [$uid1]);
        $this->assertSame([], $none->newMessages, 'nothing new after the cursor');
        $this->assertSame([$uid1], $none->presentUids);

        $this->send($sender, $s2 = 'Incremental B '.Str::random(6));
        $uid2 = $this->awaitUid($inbox, $s2);
        $inbox->setFlags(new MessageRef('INBOX', $uid1), seen: true, flagged: true);

        $changes = $inbox->fetchChanges('INBOX', $cursor, 50, null, [$uid1]);
        $this->assertSame([$uid2], array_map(fn ($m) => $m->uid, $changes->newMessages), 'only the new message');
        $this->assertTrue($changes->flagUpdates[$uid1]->seen);
        $this->assertTrue($changes->flagUpdates[$uid1]->flagged);
        $this->assertSame($uid2, $changes->nextCursor->lastUid);

        // Moving out of INBOX is detected as "no longer present" on the next pass.
        $folder = 'Archive'.Str::random(4);
        $inbox->createFolder($folder);
        $inbox->move(new MessageRef('INBOX', $uid1), $folder);
        $after = $inbox->fetchChanges('INBOX', $changes->nextCursor, 50, null, [$uid1, $uid2]);
        $this->assertSame([$uid2], $after->presentUids);
        $this->assertSame(1, $inbox->folderStatus($folder)->messages);
    }

    public function test_append_to_folder_and_role_detection(): void
    {
        $mailbox = $this->provider();
        $folders = collect($mailbox->listFolders())->pluck('path')->all();
        if (! in_array('Sent', $folders, true)) {
            $mailbox->createFolder('Sent');
        }

        $before = $mailbox->folderStatus('Sent')->messages;
        $message = new OutgoingMessage(new Address('test1@mt.co.tz'), [new Address('x@example.org')], [], [], 'Appended '.Str::random(5), null, 'body', Str::ulid().'@mt.co.tz');
        $mailbox->appendToFolder('Sent', (new MimeComposer)->compose($message), ['\\Seen']);

        $this->assertSame($before + 1, $mailbox->folderStatus('Sent')->messages);
        $this->assertSame('sent', collect($mailbox->listFolders())->firstWhere('path', 'Sent')?->role);
    }

    public function test_search_with_hostile_input_is_safe(): void
    {
        // Quotes and backslashes are escaped by the library; control characters are refused earlier.
        $this->assertSame([], $this->provider()->search('INBOX', new SearchCriteria(subject: 'x" OR ALL "\\')));
    }
}
