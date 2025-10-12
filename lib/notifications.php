<?php

declare(strict_types=1);

require_once __DIR__ . '/mailer.php';

function notify_supervisors_of_pending_user(PDO $pdo, array $cfg, array $user): void
{
    $recipients = [];
    $stmt = $pdo->query("SELECT email FROM users WHERE email IS NOT NULL AND email <> '' AND role IN ('supervisor','admin') AND account_status='active'");
    if ($stmt) {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $email = trim((string)($row['email'] ?? ''));
            if ($email !== '') {
                $recipients[] = $email;
            }
        }
    }
    if (!$recipients) {
        return;
    }

    $display = trim((string)($user['full_name'] ?? ''));
    if ($display === '') {
        $display = trim((string)($user['email'] ?? $user['username'] ?? 'New user'));
    }

    $profileUrl = url_for('admin/pending_accounts.php');
    $subject = sprintf('Approval needed: %s', $display);
    $body = "A new single sign-on user requires approval before accessing the HR Assessment portal.\n\n" .
        'Name: ' . $display . "\n" .
        'Email: ' . ($user['email'] ?? 'not provided') . "\n" .
        'Submitted on: ' . date('Y-m-d H:i') . "\n\n" .
        'Review pending accounts: ' . $profileUrl . "\n";

    send_notification_email($cfg, $recipients, $subject, $body);
}

function notify_user_account_approved(array $cfg, array $user, ?string $nextAssessmentDate): void
{
    $email = trim((string)($user['email'] ?? ''));
    if ($email === '') {
        return;
    }
    $loginUrl = url_for('login.php');
    $subject = 'Your HR Assessment access has been approved';
    $body = "Hello " . ($user['full_name'] ?? $user['username'] ?? 'team member') . ",\n\n" .
        "Your supervisor has approved your access to the HR Assessment portal. You can now sign in and complete your assessments." . "\n\n" .
        "Sign in: $loginUrl\n";
    if ($nextAssessmentDate) {
        $body .= "\nYour next assessment has been scheduled for: $nextAssessmentDate\n";
    }
    $body .= "\nThank you.";
    send_notification_email($cfg, [$email], $subject, $body);
}

function notify_user_next_assessment(array $cfg, array $user, string $nextAssessmentDate): void
{
    $email = trim((string)($user['email'] ?? ''));
    if ($email === '') {
        return;
    }
    $subject = 'Upcoming assessment scheduled';
    $body = "Hello " . ($user['full_name'] ?? $user['username'] ?? 'team member') . ",\n\n" .
        'A supervisor has scheduled your next assessment for ' . $nextAssessmentDate . ".\n" .
        'Please log in to the HR Assessment portal to prepare and complete any required steps.' . "\n\n" .
        'Portal: ' . url_for('login.php') . "\n\n" .
        'Thank you.';
    send_notification_email($cfg, [$email], $subject, $body);
}
