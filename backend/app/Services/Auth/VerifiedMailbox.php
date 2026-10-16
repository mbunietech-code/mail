<?php

namespace App\Services\Auth;

use App\Mailbox\Contracts\MailProvider;
use App\Mailbox\Data\FolderInfo;
use App\Models\ProviderProfile;

/** A mailbox whose password the provider has just accepted at sign-in. */
final class VerifiedMailbox
{
    /**
     * @param  list<FolderInfo>  $folders
     */
    public function __construct(
        public readonly string $email,
        public readonly ProviderProfile $profile,
        public readonly MailProvider $provider,
        public readonly array $folders,
    ) {}
}
