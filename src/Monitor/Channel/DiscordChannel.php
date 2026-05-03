<?php

declare(strict_types=1);

namespace AuditStash\Monitor\Channel;

use AuditStash\Monitor\Alert;
use Cake\Http\Client\Response;

/**
 * Discord notification channel.
 *
 * Posts an Alert to a Discord webhook as an
 * [embed](https://discord.com/developers/docs/resources/channel#embed-object)
 * so the alert renders with title, color sidebar, and structured fields.
 *
 * ```php
 * 'channels' => [
 *     'discord' => [
 *         'class' => \AuditStash\Monitor\Channel\DiscordChannel::class,
 *         'url' => 'https://discord.com/api/webhooks/.../...',
 *         // optional:
 *         'username' => 'AuditStash',
 *         'avatar_url' => 'https://example.com/audit-bot.png',
 *     ],
 * ],
 * ```
 *
 * Discord returns 204 No Content on success (or 200 with the message
 * resource if the URL has `?wait=true`), so `isAcceptable()` accepts both.
 */
class DiscordChannel extends AbstractWebhookChannel
{
    /**
     * Discord embed color per severity (decimal RGB).
     *
     * @var array<string, int>
     */
    protected const SEVERITY_COLORS = [
        'critical' => 14431557, // #DC3545
        'high' => 16612884, // #FD7E14
        'medium' => 16763904, // #FFC107
        'low' => 873452, // #0D6EFD
    ];

    /**
     * @inheritDoc
     */
    protected function formatPayload(Alert $alert): array
    {
        $auditLog = $alert->getAuditLog();
        $severity = $alert->getSeverity();

        $embed = [
            'title' => sprintf('[%s] %s', strtoupper($severity), $alert->getRuleName()),
            'description' => $alert->getMessage(),
            'color' => self::SEVERITY_COLORS[$severity] ?? 7105644,
            'fields' => [
                ['name' => 'Source', 'value' => (string)($auditLog->source ?? 'n/a'), 'inline' => true],
                ['name' => 'Event', 'value' => (string)($auditLog->type ?? 'n/a'), 'inline' => true],
                ['name' => 'Primary key', 'value' => (string)($auditLog->primary_key ?? 'n/a'), 'inline' => true],
                ['name' => 'User', 'value' => (string)($auditLog->user_display ?? $auditLog->user_id ?? 'n/a'), 'inline' => true],
            ],
            'timestamp' => $auditLog->created?->toIso8601String(),
        ];

        $payload = ['embeds' => [$embed]];

        foreach (['username', 'avatar_url'] as $optional) {
            if (!empty($this->config[$optional])) {
                $payload[$optional] = $this->config[$optional];
            }
        }

        return $payload;
    }

    /**
     * Discord returns 204 No Content for fire-and-forget webhooks; the
     * default `isOk()` check accepts only 200/201, so we explicitly accept
     * 204 here too.
     *
     * @inheritDoc
     */
    protected function isAcceptable(Response $response): bool
    {
        $status = $response->getStatusCode();

        return $status === 204 || $response->isOk();
    }
}
