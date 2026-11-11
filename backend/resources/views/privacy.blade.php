<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Privacy policy · MbunieMail</title>
    <link rel="stylesheet" href="{{ asset('assets/admin.css') }}">
</head>
<body>
<main class="narrow">
    <div class="card">
        <h1>MbunieMail privacy policy</h1>
        <p class="muted">Last updated: 4 November 2026</p>

        <p>MbunieMail is the email app of MbunieTech for its staff, on Android and Windows.
            This policy explains what data the app and its server handle, why, and who it is shared with.</p>

        <h2>Who is responsible</h2>
        <p>MbunieTech, Dar es Salaam, Tanzania, is the data controller. We process personal data under the
            Tanzania Personal Data Protection Act, 2022.</p>

        <h2>Data we handle</h2>
        <ul>
            <li><strong>Your MbunieMail account:</strong> name, email address, a hash of your password, two-step
                sign-in (MFA) settings and your signed-in devices.</li>
            <li><strong>Mailboxes you link:</strong> the mailbox address and its password or Google sign-in token,
                stored encrypted, so the server can reach your mailbox.</li>
            <li><strong>Email:</strong> to show your mail, the server reads it from your mail provider. It keeps
                message details (sender, recipients, subject, date, folder, read and starred state) for the list
                view. Message bodies and attachments are passed through to the app and are not stored on the
                server, except attachments you upload while writing a message, which are deleted within an hour.</li>
            <li><strong>Contacts:</strong> addresses you have written to or received from, to suggest recipients.</li>
            <li><strong>Settings:</strong> your app preferences, such as theme and notification options.</li>
            <li><strong>Device data:</strong> a push notification token for new-mail alerts, the app version, and
                your device's IP address and app identifier in security logs.</li>
            <li><strong>Security records:</strong> sign-ins, account changes and administrator actions, with time,
                IP address and device. These never contain the content of your email.</li>
        </ul>
        <p>The app does not collect your location, contacts from your phone, photos (unless you choose to attach
            them), or advertising identifiers, and contains no advertising or analytics trackers. Fingerprint or face
            unlock is checked by your device; MbunieMail never receives your biometric data.</p>

        <h2>Why we use it</h2>
        <p>Only to provide the email service: signing you in, syncing and sending your mail, sending new-mail
            notifications, keeping accounts secure, and investigating misuse. We do not sell your data or use it for
            advertising.</p>

        <h2>Who we share it with</h2>
        <ul>
            <li><strong>Your mail provider</strong> (for company mailboxes, Hostinger; for linked Gmail accounts, Google),
                to read and send your mail.</li>
            <li><strong>Google Firebase Cloud Messaging</strong>, to deliver notifications. By default a notification
                shows only the sender's name; the subject is included only if you turn that on.</li>
            <li><strong>Our hosting provider</strong>, which runs our servers.</li>
            <li>Authorities, only when the law requires it.</li>
        </ul>
        <p>Some of these providers process data outside Tanzania. MbunieTech administrators can manage accounts but
            cannot read your email through MbunieMail.</p>

        <h2>Security</h2>
        <p>All traffic uses encrypted connections (HTTPS and TLS). Mailbox passwords and tokens are stored encrypted.
            On your device, sign-in tokens are kept in the system's secure storage and the local mail cache is cleared
            when you sign out or when the session is revoked.</p>

        <h2>How long we keep it</h2>
        <ul>
            <li>Message details and mailbox credentials: while the mailbox is linked; deleted within 24 hours after
                you unlink it or your account is closed.</li>
            <li>Security records: up to 2 years. Server logs: 90 days. Encrypted backups: 30 days.</li>
        </ul>

        <h2>Your rights and deleting your data</h2>
        <p>You can see and change your settings and devices in the app, unlink a mailbox at any time (which deletes
            its stored data), and sign out of any device. To get a copy of your data, correct it, or have your
            MbunieMail account and its data deleted, contact your MbunieTech administrator{{ $contact ? ' or write to ' : '' }}@if ($contact)<a href="mailto:{{ $contact }}">{{ $contact }}</a>@endif.
            You may also complain to the Personal Data Protection Commission of Tanzania.</p>

        <h2>Children</h2>
        <p>MbunieMail is for MbunieTech staff and is not intended for children.</p>

        <h2>Changes</h2>
        <p>We will update this page when the way we handle data changes, and change the date above.</p>
    </div>
</main>
</body>
</html>
