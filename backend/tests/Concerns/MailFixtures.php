<?php

namespace Tests\Concerns;

use App\Mailbox\Data\Address;
use App\Mailbox\Data\AttachmentInfo;
use App\Mailbox\Data\MessageEnvelope;
use App\Mailbox\Data\MessageFlags;
use App\Mailbox\Fake\FakeProvider;
use App\Mailbox\ProviderFactory;
use App\Models\EmailAccount;
use App\Models\User;
use App\Security\Secret;
use App\Services\Accounts\AccountService;
use Carbon\CarbonImmutable;

/**
 * Fake-provider fixtures. DEV/TEST DATA ONLY — never used outside the test suite.
 */
trait MailFixtures
{
    protected FakeProvider $fake;

    protected function useFakeProvider(): FakeProvider
    {
        $this->fake = new FakeProvider;
        ProviderFactory::fake($this->fake);

        return $this->fake;
    }

    protected function linkAccount(User $user, string $email = 'developer@mt.co.tz'): EmailAccount
    {
        return app(AccountService::class)->linkImapAccount($user, $email, new Secret('fake-mailbox-pass'), null)->load('providerProfile');
    }

    /**
     * @param  list<AttachmentInfo>  $attachments
     * @param  list<string>  $references
     */
    protected function envelope(
        int $uid,
        string $subject = 'Hello',
        string $from = 'partner@example.org',
        ?string $messageId = null,
        ?string $inReplyTo = null,
        array $references = [],
        bool $seen = false,
        array $attachments = [],
        ?string $snippet = 'Preview text',
        ?CarbonImmutable $date = null,
        ?string $fromName = 'Partner',
        ?string $authResults = null,
    ): MessageEnvelope {
        $date ??= CarbonImmutable::now('UTC')->subMinutes(100 - $uid);

        return new MessageEnvelope(
            uid: $uid,
            providerId: null,
            messageId: $messageId ?? "msg-{$uid}@example.org",
            inReplyTo: $inReplyTo,
            references: $references,
            from: new Address($from, $fromName),
            to: [new Address('developer@mt.co.tz', 'Developer')],
            cc: [],
            replyTo: [],
            subject: $subject,
            sentAt: $date,
            internalDate: $date,
            size: 1234,
            flags: new MessageFlags(seen: $seen),
            attachments: $attachments,
            snippet: $snippet,
            authenticationResults: $authResults,
        );
    }
}
