<?php

declare(strict_types=1);

namespace AuditStash\Monitor\Channel;

use AuditStash\Monitor\Alert;

/**
 * Microsoft Teams notification channel.
 *
 * Posts an Alert to a Teams incoming webhook in the
 * [MessageCard](https://learn.microsoft.com/en-us/outlook/actionable-messages/message-card-reference)
 * (`O365 Connector Card`) format. This shape is what classic incoming-webhook
 * URLs accept and renders as a structured card with title, severity-colored
 * accent, and a fact list — without requiring the newer Power Automate
 * Workflows pipeline.
 *
 * ```php
 * 'channels' => [
 *     'teams' => [
 *         'class' => \AuditStash\Monitor\Channel\TeamsChannel::class,
 *         'url' => 'https://outlook.office.com/webhook/.../IncomingWebhook/...',
 *         // optional:
 *         'theme_colors' => [
 *             'critical' => 'C0392B', // hex without leading #
 *             'high' => 'D35400',
 *         ],
 *     ],
 * ],
 * ```
 *
 * Teams returns 200 with body `1` on success and 400 with a descriptive
 * payload on schema errors, so the default `isAcceptable()` (any 2xx) is
 * sufficient — no custom override needed.
 */
class TeamsChannel extends AbstractWebhookChannel
{
    /**
     * Default MessageCard themeColor per severity (hex without leading #).
     *
     * @var array<string, string>
     */
    protected const SEVERITY_COLORS = [
        'critical' => 'DC3545',
        'high' => 'FD7E14',
        'medium' => 'FFC107',
        'low' => '0D6EFD',
    ];

    /**
     * @inheritDoc
     */
    protected function formatPayload(Alert $alert): array
    {
        $auditLog = $alert->getAuditLog();
        $severity = $alert->getSeverity();

        $userColors = (array)($this->config['theme_colors'] ?? []);
        $color = $userColors[$severity] ?? self::SEVERITY_COLORS[$severity] ?? '6C757D';

        return [
            '@type' => 'MessageCard',
            '@context' => 'https://schema.org/extensions',
            'summary' => sprintf('AuditStash alert: %s', $alert->getRuleName()),
            'themeColor' => $color,
            'title' => sprintf('[%s] %s', strtoupper($severity), $alert->getRuleName()),
            'text' => $alert->getMessage(),
            'sections' => [
                [
                    'facts' => [
                        ['name' => 'Source', 'value' => (string)($auditLog->source ?? 'n/a')],
                        ['name' => 'Event', 'value' => (string)($auditLog->type ?? 'n/a')],
                        ['name' => 'Primary key', 'value' => (string)($auditLog->primary_key ?? 'n/a')],
                        ['name' => 'User', 'value' => (string)($auditLog->user_display ?? $auditLog->user_id ?? 'n/a')],
                        ['name' => 'Transaction', 'value' => (string)($auditLog->transaction_key ?? 'n/a')],
                    ],
                ],
            ],
        ];
    }
}
