<?php

declare(strict_types=1);

namespace AuditStash\Monitor\Channel;

use AuditStash\Monitor\Alert;

/**
 * Generic webhook notification channel.
 *
 * Posts the raw `Alert::toArray()` payload as JSON to the configured URL.
 * Use this when the receiving service is custom or wants the un-massaged
 * AuditStash event shape. For the major chat platforms there are dedicated
 * subclasses (`SlackChannel`, `DiscordChannel`) that format the payload
 * into each service's native message shape.
 */
class WebhookChannel extends AbstractWebhookChannel
{
    /**
     * @inheritDoc
     */
    protected function formatPayload(Alert $alert): array
    {
        return $alert->toArray();
    }
}
