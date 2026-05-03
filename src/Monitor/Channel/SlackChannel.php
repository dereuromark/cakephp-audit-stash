<?php

declare(strict_types=1);

namespace AuditStash\Monitor\Channel;

use AuditStash\Monitor\Alert;
use Cake\Http\Client\Response;
use Stringable;

/**
 * Slack notification channel.
 *
 * Posts an Alert to a Slack incoming webhook in
 * [Block Kit](https://api.slack.com/block-kit) format so the message renders
 * natively (header, severity-colored attachment, structured context fields)
 * instead of as a raw JSON dump.
 *
 * ```php
 * 'channels' => [
 *     'slack' => [
 *         'class' => \AuditStash\Monitor\Channel\SlackChannel::class,
 *         'url' => 'https://hooks.slack.com/services/T.../B.../...',
 *         // optional:
 *         'username' => 'AuditStash',
 *         'icon_emoji' => ':rotating_light:',
 *         'channel' => '#audit-alerts',
 *     ],
 * ],
 * ```
 *
 * Slack incoming webhooks return the literal string `ok` in the body on
 * success, so `isAcceptable()` is overridden to check that explicitly —
 * Slack returns 200 even for some shape errors with a non-`ok` body.
 */
class SlackChannel extends AbstractWebhookChannel
{
    /**
     * Slack attachment color per severity level.
     *
     * @var array<string, string>
     */
    protected const SEVERITY_COLORS = [
        'critical' => '#dc3545',
        'high' => '#fd7e14',
        'medium' => '#ffc107',
        'low' => '#0d6efd',
    ];

    /**
     * @inheritDoc
     */
    protected function formatPayload(Alert $alert): array
    {
        $auditLog = $alert->getAuditLog();
        $severity = $alert->getSeverity();

        $blocks = [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => sprintf(
                        '[%s] %s',
                        strtoupper($severity),
                        $alert->getRuleName(),
                    ),
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => $alert->getMessage(),
                ],
            ],
            [
                'type' => 'section',
                'fields' => [
                    $this->mrkdwnField('Source', $auditLog->source ?? null),
                    $this->mrkdwnField('Event', $auditLog->type ?? null),
                    $this->mrkdwnField('Primary key', $auditLog->primary_key ?? null),
                    $this->mrkdwnField('User', $auditLog->user_display ?? $auditLog->user_id ?? null),
                ],
            ],
        ];

        $url = $this->viewUrl($alert);
        if ($url !== null) {
            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => sprintf('<%s|View entry in admin →>', $url),
                ],
            ];
        }

        $payload = [
            'attachments' => [
                [
                    'color' => self::SEVERITY_COLORS[$severity] ?? '#6c757d',
                    'blocks' => $blocks,
                ],
            ],
        ];

        foreach (['username', 'icon_emoji', 'channel'] as $optional) {
            if (!empty($this->config[$optional])) {
                $payload[$optional] = $this->config[$optional];
            }
        }

        return $payload;
    }

    /**
     * Builds a Slack `mrkdwn` field with a bold label, a real newline, and an
     * HTML-entity-escaped value. Slack mrkdwn parses `&`, `<`, and `>` as
     * markup characters, so user-controlled values must be entity-escaped to
     * avoid breaking the layout (or rendering as a `<channel>` link).
     *
     * @param string $label
     * @param mixed $value
     *
     * @return array{type: string, text: string}
     */
    protected function mrkdwnField(string $label, mixed $value): array
    {
        if (is_scalar($value) || $value instanceof Stringable) {
            $value = (string)$value;
        } else {
            $value = '';
        }
        if ($value === '') {
            $value = 'n/a';
        }

        $value = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $value);

        return [
            'type' => 'mrkdwn',
            'text' => sprintf("*%s*\n%s", $label, $value),
        ];
    }

    /**
     * Slack webhooks return body `ok` on success regardless of any extra
     * 200-class responses returned for invalid payload shapes.
     *
     * @inheritDoc
     */
    protected function isAcceptable(Response $response): bool
    {
        return $response->isOk() && trim($response->getStringBody()) === 'ok';
    }
}
