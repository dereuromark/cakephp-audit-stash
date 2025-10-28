<?php

declare(strict_types=1);

namespace AuditStash\Monitor\Channel;

use AuditStash\Monitor\Alert;

/**
 * Interface for notification channels.
 */
interface ChannelInterface
{
    /**
     * Send an alert through this channel.
     *
     * @param \AuditStash\Monitor\Alert $alert The alert to send
     *
     * @return bool True if the alert was sent successfully
     */
    public function send(Alert $alert): bool;
}
