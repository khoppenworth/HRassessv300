<?php

declare(strict_types=1);

function default_email_templates(): array
{
    return [
        'pending_user' => [
            'subject' => 'Approval needed: {{user_display}}',
            'html' => <<<'HTML'
<p>A new single sign-on user requires approval before accessing the HR Assessment portal.</p>
<ul>
  <li>Name: {{user_display}}</li>
  <li>Email: {{user_email}}</li>
  <li>Submitted on: {{submitted_at}}</li>
</ul>
<p><a href="{{pending_accounts_url}}">Review pending accounts</a></p>
HTML,
        ],
        'account_approved' => [
            'subject' => 'Your HR Assessment access has been approved',
            'html' => <<<'HTML'
<p>Hello {{user_name}},</p>
<p>Your supervisor has approved your access to the HR Assessment portal. You can now sign in and complete your assessments.</p>
{{next_assessment_block}}
<p><a href="{{login_url}}">Sign in to the portal</a></p>
<p>Thank you.</p>
HTML,
        ],
        'next_assessment' => [
            'subject' => 'Upcoming assessment scheduled',
            'html' => <<<'HTML'
<p>Hello {{user_name}},</p>
<p>A supervisor has scheduled your next assessment for {{next_assessment_date}}.</p>
<p>Please log in to the HR Assessment portal to prepare and complete any required steps.</p>
<p><a href="{{portal_url}}">Open the HR Assessment portal</a></p>
<p>Thank you.</p>
HTML,
        ],
        'assignment_update' => [
            'subject' => 'Questionnaire assignments updated',
            'html' => <<<'HTML'
<p>Hello {{user_name}},</p>
{{assignment_summary}}
{{next_assessment_block}}
{{assigner_block}}
<p>You can review your questionnaires here: <a href="{{dashboard_url}}">{{dashboard_url}}</a></p>
<p>Thank you.</p>
HTML,
        ],
    ];
}

function normalize_email_templates($value): array
{
    $defaults = default_email_templates();
    $decoded = [];

    if (is_string($value) && $value !== '') {
        $maybe = json_decode($value, true);
        if (is_array($maybe)) {
            $decoded = $maybe;
        }
    } elseif (is_array($value)) {
        $decoded = $value;
    }

    $result = $defaults;
    foreach ($decoded as $key => $template) {
        if (!isset($defaults[$key]) || !is_array($template)) {
            continue;
        }
        $subject = isset($template['subject']) ? (string)$template['subject'] : '';
        $html = isset($template['html']) ? (string)$template['html'] : '';
        $subject = trim($subject) !== '' ? $subject : $defaults[$key]['subject'];
        $html = trim($html) !== '' ? $html : $defaults[$key]['html'];
        $result[$key] = [
            'subject' => $subject,
            'html' => $html,
        ];
    }

    return $result;
}

function encode_email_templates(array $templates): string
{
    $normalized = normalize_email_templates($templates);
    $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json === false ? '{}' : $json;
}
