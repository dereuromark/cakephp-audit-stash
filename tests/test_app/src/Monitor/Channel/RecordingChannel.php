<?php

declare(strict_types=1);

namespace TestApp\Monitor\Channel;

use AuditStash\Monitor\Alert;
use AuditStash\Monitor\Channel\ChannelInterface;

/**
 * Channel test double that records every alert it receives in a static
 * accumulator. The `returns` config key controls whether `send()` reports
 * success — used by AuditMonitor lifecycle tests to exercise both
 * outcomes through the channel pipeline.
 */
class RecordingChannel implements ChannelInterface
{
    /**
     * @var array<int, \AuditStash\Monitor\Alert>
     */
    public static array $delivered = [];

    private bool $returns;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->returns = (bool)($config['returns'] ?? true);
    }

    public function send(Alert $alert): bool
    {
        self::$delivered[] = $alert;

        return $this->returns;
    }

    public static function reset(): void
    {
        self::$delivered = [];
    }
}
