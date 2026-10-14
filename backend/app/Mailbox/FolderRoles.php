<?php

namespace App\Mailbox;

/**
 * Maps provider folders to standard roles: RFC 6154 SPECIAL-USE attributes first,
 * then common names (e.g. Hostinger/Dovecot "INBOX.Sent", Outlook "Sent Items").
 */
final class FolderRoles
{
    private const ATTRIBUTES = [
        '\\sent' => 'sent', '\\drafts' => 'drafts', '\\trash' => 'trash',
        '\\junk' => 'spam', '\\archive' => 'archive', '\\all' => 'archive',
    ];

    private const NAMES = [
        'sent' => 'sent', 'sent items' => 'sent', 'sent messages' => 'sent', 'sent mail' => 'sent',
        'drafts' => 'drafts', 'draft' => 'drafts',
        'trash' => 'trash', 'deleted' => 'trash', 'deleted items' => 'trash', 'deleted messages' => 'trash', 'bin' => 'trash',
        'junk' => 'spam', 'spam' => 'spam', 'junk e-mail' => 'spam', 'junk email' => 'spam',
        'archive' => 'archive', 'archives' => 'archive',
    ];

    /** @param list<string> $flags */
    public static function detect(string $path, ?string $delimiter, array $flags): string
    {
        if (strcasecmp($path, 'INBOX') === 0) {
            return 'inbox';
        }

        foreach ($flags as $flag) {
            if ($role = self::ATTRIBUTES[strtolower($flag)] ?? null) {
                return $role;
            }
        }

        $leaf = $delimiter !== null && $delimiter !== '' && str_contains($path, $delimiter)
            ? substr($path, strrpos($path, $delimiter) + strlen($delimiter))
            : $path;

        return self::NAMES[strtolower(trim($leaf))] ?? 'custom';
    }
}
