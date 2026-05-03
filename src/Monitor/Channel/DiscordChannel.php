<?php

declare(strict_types=1);

namespace AuditStash\Monitor\Channel;

use AuditStash\Monitor\Alert;
use Cake\Http\Client\Response;
use Stringable;

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
     * Discord embed color per severity (decimal RGB). Kept in sync with the
     * Slack palette — the integer values are the decimal form of each Slack
     * hex value so the two channels render the same severity color.
     *
     * @var array<string, int>
     */
    protected const SEVERITY_COLORS = [
        'critical' => 0xDC3545, // 14431557
        'high' => 0xFD7E14, // 16612884
        'medium' => 0xFFC107, // 16761095
        'low' => 0x0D6EFD, // 880381
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
            'color' => self::SEVERITY_COLORS[$severity] ?? 0x6C757D,
            'fields' => [
                ['name' => 'Source', 'value' => $this->fieldValue($auditLog->source ?? null), 'inline' => true],
                ['name' => 'Event', 'value' => $this->fieldValue($auditLog->type ?? null), 'inline' => true],
                ['name' => 'Primary key', 'value' => $this->fieldValue($auditLog->primary_key ?? null), 'inline' => true],
                ['name' => 'User', 'value' => $this->fieldValue($auditLog->user_display ?? $auditLog->user_id ?? null), 'inline' => true],
            ],
        ];

        // Discord rejects `timestamp: null`; only include the key when there's
        // an actual ISO 8601 string to ship.
        $timestamp = $auditLog->created?->toIso8601String();
        if ($timestamp !== null) {
            $embed['timestamp'] = $timestamp;
        }

        $url = $this->viewUrl($alert);
        if ($url !== null) {
            // Discord makes the embed title clickable when the embed has a `url`.
            $embed['url'] = $url;
        }

        $payload = [
            'embeds' => [$embed],
            // Defense-in-depth: never let an audit log row's source/type/user
            // value smuggle an `@everyone`/`@here`/`<@user>` mention into the
            // channel. Disable mention parsing entirely.
            'allowed_mentions' => ['parse' => []],
        ];

        foreach (['username', 'avatar_url'] as $optional) {
            if (!empty($this->config[$optional])) {
                $payload[$optional] = $this->config[$optional];
            }
        }

        return $payload;
    }

    /**
     * Normalize a raw audit-log column value for use as a Discord embed
     * field `value`. Discord rejects empty strings, so both `null` and `''`
     * collapse to `'n/a'`.
     *
     * @param mixed $value
     *
     * @return string
     */
    protected function fieldValue(mixed $value): string
    {
        if (is_scalar($value) || $value instanceof Stringable) {
            $value = (string)$value;
        } else {
            $value = '';
        }

        return $value === '' ? 'n/a' : $value;
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
