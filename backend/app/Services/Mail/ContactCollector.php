<?php

namespace App\Services\Mail;

use App\Models\EmailAccount;
use App\Models\Folder;
use App\Models\MessageMetadata;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds the user's private autocomplete list (FR-CONT-001) from people they actually
 * correspond with: senders of received mail and recipients of sent mail.
 */
class ContactCollector
{
    /** @param list<MessageMetadata> $rows newly cached messages */
    public function collect(EmailAccount $account, Folder $folder, array $rows): void
    {
        if ($rows === [] || ! in_array($folder->role, ['inbox', 'sent'], true)) {
            return;
        }

        $own = strtolower($account->email_address);
        $people = [];
        foreach ($rows as $row) {
            $addresses = $folder->role === 'sent'
                ? [...($row->to_json ?? []), ...($row->cc_json ?? [])]
                : [['email' => $row->from_email, 'name' => $row->from_name]];

            foreach ($addresses as $address) {
                $email = strtolower((string) ($address['email'] ?? ''));
                if ($email === '' || $email === $own || ! filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/^(no-?reply|mailer-daemon|postmaster)@/', $email)) {
                    continue;
                }
                $people[$email] ??= ['name' => $address['name'] ?? null, 'count' => 0, 'last' => $row->internal_date];
                $people[$email]['count']++;
                if ($row->internal_date->gt($people[$email]['last'])) {
                    $people[$email]['last'] = $row->internal_date;
                }
            }
        }

        foreach ($people as $email => $p) {
            DB::table('contacts')->upsert(
                [[
                    'id' => (string) Str::ulid(),
                    'user_id' => $account->user_id,
                    'email' => $email,
                    'name' => $p['name'] !== null ? mb_substr((string) $p['name'], 0, 160) : null,
                    'source' => 'derived',
                    'use_count' => $p['count'],
                    'last_used_at' => $p['last'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]],
                ['user_id', 'email'],
                [
                    'use_count' => DB::raw('use_count + VALUES(use_count)'),
                    'last_used_at' => DB::raw('GREATEST(COALESCE(last_used_at, VALUES(last_used_at)), VALUES(last_used_at))'),
                    'name' => DB::raw('COALESCE(name, VALUES(name))'),
                    'updated_at' => now(),
                ],
            );
        }
    }
}
