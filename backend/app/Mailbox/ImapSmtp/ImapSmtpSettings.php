<?php

namespace App\Mailbox\ImapSmtp;

use App\Security\Secret;

final readonly class ImapSmtpSettings
{
    public function __construct(
        public string $imapHost,
        public int $imapPort,
        public string $imapEncryption,
        public string $smtpHost,
        public int $smtpPort,
        public string $smtpEncryption,
        public string $username,
        public Secret $password,
        public int $timeout = 20,
    ) {}
}
