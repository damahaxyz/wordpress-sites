<?php
/** Called only by the server-root monitor. Recipient is an existing administrator. */
$mode = $args[0] ?? 'failure';
$admins = get_users(['role' => 'administrator']);
if (count($admins) === 1) {
    $recipient = $admins[0]->user_email;
} else {
    $recipient = get_option('admin_email');
    if (! in_array($recipient, array_map(static fn ($u) => $u->user_email, $admins), true)) {
        WP_CLI::error('Site administrator recipient requires operator review.');
    }
}
if (! is_email($recipient)) {
    WP_CLI::error('Administrator email is unavailable.');
}
$messages = [
    'test' => ['Trovesia security monitoring enabled', 'This is the requested security-alert delivery test. Server permission, integrity, authentication and dynamic-page checks are enabled. No customer data or credentials are included.'],
    'failure' => ['Trovesia security check needs attention', 'A server security or dynamic-page health check failed. Please ask the site operator to review the latest root-private security-reports/check-*.log and the trovesia-security-watch service journal. No credentials or customer data are included in this alert.'],
    'recovery' => ['Trovesia security checks recovered', 'The server security and dynamic-page checks have returned to normal. The site operator can review the root-private check report for details.'],
    'authentication' => ['Trovesia sign-in or account security alert', 'The monitor detected a sign-in throttle, blocked privileged sign-in, or privileged account/plugin change. Please review the protected authentication audit and request IDs. No passwords, codes, email addresses, cookies or customer details are included.'],
];
if (! isset($messages[$mode])) {
    WP_CLI::error('Unknown alert type.');
}
[$subject, $body] = $messages[$mode];
if (! wp_mail($recipient, $subject, $body . "\n\nSite: " . home_url('/') . "\nTime (UTC): " . gmdate('c'))) {
    WP_CLI::error('Alert mail was not accepted by the configured mail transport.');
}
WP_CLI::success('Security alert accepted by configured mail transport for the existing administrator.');
