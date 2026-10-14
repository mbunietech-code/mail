<?php

namespace App\Mailbox;

use App\Mailbox\Data\Address;
use App\Mailbox\Data\OutgoingMessage;
use Symfony\Component\Mime\Address as MimeAddress;
use Symfony\Component\Mime\Email;

/**
 * Builds the RFC 5322 bytes for an outgoing message. The same bytes are submitted and
 * appended to Sent, so both copies are identical. Symfony Mime encodes headers and
 * rejects CR/LF injection (risk R-07).
 */
class MimeComposer
{
    public function compose(OutgoingMessage $message): string
    {
        $email = (new Email)
            ->from(self::address($message->from))
            ->to(...array_map(self::address(...), $message->to))
            ->subject($message->subject);

        if ($message->cc !== []) {
            $email->cc(...array_map(self::address(...), $message->cc));
        }
        // BCC recipients are envelope-only: never written into the message headers.

        if ($message->text !== null) {
            $email->text($message->text);
        }
        if ($message->html !== null) {
            $email->html($message->html);
        }
        foreach ($message->attachments as $attachment) {
            $email->attachFromPath($attachment['path'], $attachment['filename'], $attachment['mime']);
        }

        $headers = $email->getHeaders();
        $headers->addIdHeader('Message-ID', self::bareId($message->messageId));
        if ($message->inReplyTo !== null) {
            $headers->addIdHeader('In-Reply-To', self::bareId($message->inReplyTo));
        }
        if ($message->references !== []) {
            $headers->addIdHeader('References', array_map(self::bareId(...), $message->references));
        }
        $headers->addTextHeader('X-Mailer', 'MbunieMail');

        return $email->toString();
    }

    private static function address(Address $address): MimeAddress
    {
        return new MimeAddress($address->email, $address->name ?? '');
    }

    private static function bareId(string $id): string
    {
        return trim($id, " <>\t");
    }
}
