<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Monitor\Channel;

use AuditStash\AuditLogType;
use AuditStash\Model\Entity\AuditLog;
use AuditStash\Monitor\Alert;
use AuditStash\Monitor\Channel\SlackChannel;
use Cake\Http\Client;
use Cake\Http\Client\Adapter\Mock as MockAdapter;
use Cake\Http\Client\Response;
use Cake\TestSuite\TestCase;
use Laminas\Diactoros\Request as PsrRequest;
use Psr\Http\Message\RequestInterface;

class SlackChannelTest extends TestCase
{
    public function testFormatsAsBlockKitWithSeverityColor(): void
    {
        $captured = null;
        $channel = $this->buildChannel(
            ['url' => 'https://hooks.slack.com/services/T/B/secret'],
            new Response(['HTTP/1.1 200 OK'], 'ok'),
            $captured,
        );

        $sent = $channel->send($this->buildAlert('critical'));
        $this->assertTrue($sent);
        $this->assertNotNull($captured);

        $payload = $this->decode($captured);
        $this->assertArrayHasKey('attachments', $payload);
        $this->assertCount(1, $payload['attachments']);

        $attachment = $payload['attachments'][0];
        $this->assertSame('#dc3545', $attachment['color']);
        $this->assertSame('header', $attachment['blocks'][0]['type']);
        $this->assertStringContainsString('CRITICAL', $attachment['blocks'][0]['text']['text']);
        $this->assertStringContainsString('SensitiveField', $attachment['blocks'][0]['text']['text']);
        $this->assertSame('Password rotated for user 42', $attachment['blocks'][1]['text']['text']);
    }

    public function testIncludesOptionalUsernameIconAndChannelOverrides(): void
    {
        $captured = null;
        $channel = $this->buildChannel([
            'url' => 'https://hooks.slack.com/services/T/B/secret',
            'username' => 'AuditStash',
            'icon_emoji' => ':rotating_light:',
            'channel' => '#audit-alerts',
        ], new Response(['HTTP/1.1 200 OK'], 'ok'), $captured);

        $channel->send($this->buildAlert('high'));

        $payload = $this->decode($captured);
        $this->assertSame('AuditStash', $payload['username']);
        $this->assertSame(':rotating_light:', $payload['icon_emoji']);
        $this->assertSame('#audit-alerts', $payload['channel']);
    }

    public function testRejectsTwoHundredWithNonOkBody(): void
    {
        // Slack returns 200 even when the payload is rejected — body must be 'ok'.
        $captured = null;
        $channel = $this->buildChannel(
            ['url' => 'https://hooks.slack.com/services/T/B/secret'],
            new Response(['HTTP/1.1 200 OK'], 'invalid_payload'),
            $captured,
        );

        $this->assertFalse($channel->send($this->buildAlert('low')));
    }

    public function testNoUrlReturnsFalse(): void
    {
        $channel = new SlackChannel([]);
        $this->assertFalse($channel->send($this->buildAlert('high')));
    }

    /**
     * Builds a SlackChannel with `createClient()` stubbed to return a Client
     * backed by a Mock adapter. The request that flows through is captured
     * into `$captured` so the test can assert on the body.
     *
     * @param array<string, mixed> $config
     * @param \Cake\Http\Client\Response $response
     * @param \Psr\Http\Message\RequestInterface|null $captured
     */
    private function buildChannel(array $config, Response $response, ?RequestInterface &$captured): SlackChannel
    {
        $captured = null;
        $adapter = new MockAdapter();
        $adapter->addResponse(new PsrRequest('https://hooks.slack.com/services/T/B/secret', 'POST'), $response, [
            'match' => function (RequestInterface $request) use (&$captured): bool {
                $captured = $request;

                return true;
            },
        ]);

        return new class ($config, $adapter) extends SlackChannel {
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

        return new Alert('SensitiveField', $severity, 'Password rotated for user 42', $log, []);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(RequestInterface $request): array
    {
        $body = (string)$request->getBody();
        /** @var array<string, mixed> $decoded */
        $decoded = (array)json_decode($body, true);

        return $decoded;
    }
}
