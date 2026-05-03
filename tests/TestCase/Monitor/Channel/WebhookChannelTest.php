<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Monitor\Channel;

use AuditStash\AuditLogType;
use AuditStash\Model\Entity\AuditLog;
use AuditStash\Monitor\Alert;
use AuditStash\Monitor\Channel\WebhookChannel;
use Cake\Http\Client;
use Cake\Http\Client\Adapter\Mock as MockAdapter;
use Cake\Http\Client\Response;
use Cake\TestSuite\TestCase;
use Laminas\Diactoros\Request as PsrRequest;
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
        $captured = null;
        $channel = $this->buildChannel(
            ['url' => 'https://hooks.example.com/audit'],
            new Response(['HTTP/1.1 200 OK'], '{}'),
            $captured,
        );

        $alert = $this->buildAlert('high');
        $this->assertTrue($channel->send($alert));

        $payload = $this->decode($captured);
        $this->assertSame($alert->toArray(), $payload);
        $this->assertSame('SensitiveField', $payload['rule_name']);
        $this->assertSame('high', $payload['severity']);
        $this->assertArrayHasKey('audit_log', $payload);
        $this->assertSame(7, $payload['audit_log']['id']);
    }

    public function testRetriesOnNonOkResponseUntilExhausted(): void
    {
        $adapter = new MockAdapter();
        $adapter->addResponse(
            new PsrRequest('https://hooks.example.com/audit', 'POST'),
            new Response(['HTTP/1.1 500 Internal Server Error'], 'boom'),
            ['match' => fn () => true],
        );
        $adapter->addResponse(
            new PsrRequest('https://hooks.example.com/audit', 'POST'),
            new Response(['HTTP/1.1 500 Internal Server Error'], 'boom'),
            ['match' => fn () => true],
        );

        $channel = new class (
            ['url' => 'https://hooks.example.com/audit', 'retry' => 2],
            $adapter,
        ) extends WebhookChannel {
            public function __construct(array $config, private MockAdapter $adapter)
            {
                parent::__construct($config);
            }

            protected function createClient(): Client
            {
                return new Client(['adapter' => $this->adapter]);
            }
        };

        $this->assertFalse($channel->send($this->buildAlert('low')));
    }

    public function testNoUrlReturnsFalse(): void
    {
        $channel = new WebhookChannel([]);
        $this->assertFalse($channel->send($this->buildAlert('low')));
    }

    /**
     * @param array<string, mixed> $config
     * @param \Cake\Http\Client\Response $response
     * @param \Psr\Http\Message\RequestInterface|null $captured
     */
    private function buildChannel(array $config, Response $response, ?RequestInterface &$captured): WebhookChannel
    {
        $captured = null;
        $adapter = new MockAdapter();
        $adapter->addResponse(
            new PsrRequest('https://hooks.example.com/audit', 'POST'),
            $response,
            [
                'match' => function (RequestInterface $request) use (&$captured): bool {
                    $captured = $request;

                    return true;
                },
            ],
        );

        return new class ($config, $adapter) extends WebhookChannel {
            public function __construct(array $config, private MockAdapter $adapter)
            {
                parent::__construct($config);
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
