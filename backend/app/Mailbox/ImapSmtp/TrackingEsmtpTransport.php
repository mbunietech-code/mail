<?php

namespace App\Mailbox\ImapSmtp;

use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * Records whether the end-of-data terminator was sent. If the connection fails before
 * that point the server discards the message, so retrying is safe; after it, the server
 * may have accepted the message without us seeing the reply, so the outcome is unknown
 * and MUST NOT be retried automatically (SDD §8.2, risk R-23).
 */
class TrackingEsmtpTransport extends EsmtpTransport
{
    public bool $terminatorSent = false;

    public function executeCommand(string $command, array $codes): string
    {
        if ($command === "\r\n.\r\n") {
            $this->terminatorSent = true;
        }

        return parent::executeCommand($command, $codes);
    }
}
