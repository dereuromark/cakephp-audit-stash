<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Monitor\Channel;

use AuditStash\AuditLogType;
use AuditStash\Model\Entity\AuditLog;
use AuditStash\Monitor\Alert;
use AuditStash\Monitor\Channel\WebhookChannel;
use AuditStash\Test\CapturingAdapter;
use Cake\Http\Client;
use Cake\Http\Client\Response;
use Cake\TestSuite\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Pins the existing `WebhookChannel` payload contract — the un-massaged
 * `Alert::toArray()` shape. Existing user wiring against this class must
 * continue to behave identically after the AbstractWebhookChannel refactor.
 */
class WebhookChannelTest extends TestCase
{
    public function testPostsRawAlertToArrayPayload(): void
    {
        $channel = $this->buildChannel(
            ['url' => 'https://hooks.example.com/audit'],
            new Response(['HTTP/1.1 200 OK'], '{}'),
        );

        $alert = $this->buildAlert('high');
        $this->assertTrue($channel->send($alert));

        $payload = $this->decode($channel->adapter->captured);
        $this->assertSame($alert->toArray(), $payload);
        $this->assertSame('SensitiveField', $payload['rule_name']);
        $this->assertSame('high', $payload['severity']);
        $this->assertArrayHasKey('audit_log', $payload);
        $this->assertSame(7, $payload['audit_log']['id']);
    }

    public function testRetriesOnNonOkResponseUntilExhausted(): void
    {
        $channel = new class (
            ['url' => 'https://hooks.example.com/audit', 'retry' => 2],
            new Response(['HTTP/1.1 500 Internal Server Error'], 'boom'),
            new Response(['HTTP/1.1 500 Internal Server Error'], 'boom'),
        ) extends WebhookChannel {
            public CapturingAdapter $adapter;

            public function __construct(array $config, Response ...$responses)
            {
                parent::__construct($config);
                $this->adapter = new CapturingAdapter(...$responses);
            }

            protected function createClient(): Client
            {
                return new Client(['adapter' => $this->adapter]);
            }
        };

        $this->assertFalse($channel->send($this->buildAlert('low')));
        $this->assertCount(2, $channel->adapter->requests);
    }

    public function testNoUrlReturnsFalse(): void
    {
        $channel = new WebhookChannel([]);
        $this->assertFalse($channel->send($this->buildAlert('low')));
    }

    /**
     * @param array<string, mixed> $config
     * @param \Cake\Http\Client\Response $response
     */
    private function buildChannel(array $config, Response $response): WebhookChannel
    {
        return new class ($config, $response) extends WebhookChannel {
            public CapturingAdapter $adapter;

            public function __construct(array $config, Response $response)
            {
                parent::__construct($config);
                $this->adapter = new CapturingAdapter($response);
            }

            protected function createClient(): Client
            {
                return new Client(['adapter' => $this->adapter]);
            }
        };
    }

    private function buildAlert(string $severity): Alert
    {
        $log = new AuditLog([
            'id' => 7,
            'type' => AuditLogType::Update->value,
            'source' => 'Users',
            'primary_key' => 42,
            'transaction_key' => 'tx-abc',
            'user_id' => '7',
            'user_display' => 'admin@example.com',
        ]);

        return new Alert('SensitiveField', $severity, 'Password rotated', $log, []);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(RequestInterface $request): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = (array)json_decode((string)$request->getBody(), true);

        return $decoded;
    }
}
