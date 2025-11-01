<?php

declare(strict_types=1);

require __DIR__ . '/../lib/email_templates.php';

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function test_normalize_email_templates_from_json(): void
{
    $templates = [
        'pending_user' => [
            'subject' => 'Custom subject',
            'html' => '<p>Hi</p>',
        ],
        'account_approved' => [
            'subject' => '',
            'html' => '  ',
        ],
    ];
    $json = json_encode($templates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $normalized = normalize_email_templates($json ?: '');

    $defaults = default_email_templates();
    assert_same('Custom subject', $normalized['pending_user']['subject'], 'Custom subject should be preserved.');
    assert_same('<p>Hi</p>', $normalized['pending_user']['html'], 'Custom HTML should be preserved.');
    assert_same($defaults['account_approved']['subject'], $normalized['account_approved']['subject'], 'Empty subject should fall back to default.');
    assert_same($defaults['account_approved']['html'], $normalized['account_approved']['html'], 'Empty HTML should fall back to default.');
}

function test_normalize_email_templates_from_array(): void
{
    $input = [
        'pending_user' => [
            'subject' => '   ',
            'html' => '',
        ],
    ];
    $normalized = normalize_email_templates($input);
    $defaults = default_email_templates();

    assert_same($defaults['pending_user']['subject'], $normalized['pending_user']['subject'], 'Whitespace subject should revert to default.');
    assert_same($defaults['pending_user']['html'], $normalized['pending_user']['html'], 'Whitespace HTML should revert to default.');
}

function test_encode_email_templates_returns_json(): void
{
    $input = [
        'next_assessment' => [
            'subject' => 'Reminder',
            'html' => '<p>Hello</p>',
        ],
    ];
    $json = encode_email_templates($input);
    $decoded = json_decode($json, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('encode_email_templates should return valid JSON.');
    }

    assert_same('Reminder', $decoded['next_assessment']['subject'], 'Encoded JSON should include normalized subject.');
    assert_same('<p>Hello</p>', $decoded['next_assessment']['html'], 'Encoded JSON should include normalized HTML.');
}

function test_email_template_registry_is_consistent(): void
{
    $registry = email_template_registry();
    $defaults = default_email_templates();

    assert_same(array_keys($defaults), array_keys($registry), 'Registry should define the same templates as the defaults.');

    foreach ($defaults as $key => $template) {
        assert_same($template, $registry[$key]['defaults'], 'Registry defaults should match default_email_templates.');
        foreach ($registry[$key]['placeholders'] as $placeholderToken => $metadata) {
            if (!isset($metadata['key'], $metadata['fallback'])) {
                throw new RuntimeException('Registry placeholders require translation metadata.');
            }
            if (!is_string($metadata['key']) || !is_string($metadata['fallback'])) {
                throw new RuntimeException('Registry placeholder metadata must be strings.');
            }
        }
    }
}

function run_email_template_tests(): void
{
    test_normalize_email_templates_from_json();
    test_normalize_email_templates_from_array();
    test_encode_email_templates_returns_json();
    test_email_template_registry_is_consistent();
}

run_email_template_tests();

echo "Email template tests passed.\n";
