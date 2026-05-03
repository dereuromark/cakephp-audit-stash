<?php

declare(strict_types=1);

namespace TestApp\Monitor\Channel;

use AuditStash\Monitor\Alert;
use AuditStash\Monitor\Channel\ChannelInterface;
use RuntimeException;

/**
 * Channel test double whose `send()` throws. Used to verify that
 * AuditMonitor catches per-channel exceptions, marks the channel as
 * failed in the afterAlert results map, and continues delivering to the
 * remaining channels.
 */
class ExplodingChannel implements ChannelInterface
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
    }

    public function send(Alert $alert): bool
    {
        throw new RuntimeException('channel blew up');
    }
}
