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
    /**
     * @var class-string<\Throwable>
     */
    protected string $throws;

    /**
     * @param array<string, mixed> $config Channel config; `throws` picks the class to throw
     */
    public function __construct(array $config = [])
    {
        $this->throws = $config['throws'] ?? RuntimeException::class;
    }

    public function send(Alert $alert): bool
    {
        throw new $this->throws('channel blew up');
    }
}
