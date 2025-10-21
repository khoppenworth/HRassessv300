<?php

declare(strict_types=1);

require __DIR__ . '/../lib/mailer.php';

function expect_equal($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        $expectedExport = var_export($expected, true);
        $actualExport = var_export($actual, true);
        throw new RuntimeException($message . "\nExpected: " . $expectedExport . "\nActual: " . $actualExport);
    }
}

function run_smtp_config_tests(): void
{
    // Basic configuration is preserved and trimmed.
    $cfg = [
        'smtp_enabled' => 1,
        'smtp_host' => ' smtp.mail.local ',
        'smtp_port' => 2525,
        'smtp_encryption' => 'TLS',
        'smtp_from_email' => 'alerts@example.org ',
        'smtp_from_name' => ' HR Alerts ',
        'smtp_username' => 'mailer',
        'smtp_password' => 'secret',
        'smtp_timeout' => 45,
    ];
    $result = app_smtp_config($cfg);
    expect_equal(true, $result['enabled'], 'SMTP should be enabled when smtp_enabled is truthy.');
    expect_equal('smtp.mail.local', $result['host'], 'smtp_host should be trimmed.');
    expect_equal(2525, $result['port'], 'smtp_port should be converted to int.');
    expect_equal('tls', $result['encryption'], 'smtp_encryption should be lowercased.');
    expect_equal('alerts@example.org', $result['from_email'], 'smtp_from_email should be trimmed.');
    expect_equal('HR Alerts', $result['from_name'], 'smtp_from_name should be trimmed.');
    expect_equal('mailer', $result['username'], 'smtp_username should be preserved.');
    expect_equal('secret', $result['password'], 'smtp_password should be preserved.');
    expect_equal(45, $result['timeout'], 'smtp_timeout should be converted to int.');

    // Missing optional fields fall back to defaults.
    $cfg = [
        'smtp_enabled' => '1',
        'smtp_host' => 'mail.example.com',
        'smtp_port' => '',
        'smtp_encryption' => 'invalid',
        'footer_email' => 'fallback@example.com',
        'site_name' => 'Example Co',
    ];
    $result = app_smtp_config($cfg);
    expect_equal(true, $result['enabled'], 'smtp_enabled string "1" should be treated as enabled.');
    expect_equal(587, $result['port'], 'Invalid smtp_port should fall back to 587.');
    expect_equal('none', $result['encryption'], 'Unknown encryption should fall back to "none".');
    expect_equal('fallback@example.com', $result['from_email'], 'smtp_from_email should fall back to footer_email.');
    expect_equal('Example Co', $result['from_name'], 'smtp_from_name should fall back to site_name.');

    // Disabled SMTP should be represented as false.
    $cfg = [
        'smtp_enabled' => 0,
        'smtp_host' => 'smtp.disabled.test',
        'smtp_from_email' => 'nope@example.com',
    ];
    $result = app_smtp_config($cfg);
    expect_equal(false, $result['enabled'], 'smtp_enabled 0 should disable SMTP.');
}

run_smtp_config_tests();

echo "SMTP configuration tests passed.\n";
