<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Monitor\Channel;

use AuditStash\AuditLogType;
use AuditStash\AuditStashPlugin;
use AuditStash\Model\Entity\AuditLog;
use AuditStash\Monitor\Alert;
use AuditStash\Monitor\Channel\SlackChannel;
use AuditStash\Test\CapturingAdapter;
use Cake\Core\Configure;
use Cake\Http\Client;
use Cake\Http\Client\Response;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;
use Psr\Http\Message\RequestInterface;

class SlackChannelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Pin a known fullBaseUrl + load the plugin's routes so
        // `viewUrl()` resolves to a deterministic absolute URL.
        Configure::write('App.fullBaseUrl', 'https://example.com');
        Router::reload();
        (new AuditStashPlugin())->routes(Router::createRouteBuilder('/'));
    }

    public function testFormatsAsBlockKitWithSeverityColor(): void
    {
        $channel = $this->buildChannel(
            ['url' => 'https://hooks.slack.com/services/T/B/secret'],
            new Response(['HTTP/1.1 200 OK'], 'ok'),
        );

        $sent = $channel->send($this->buildAlert('critical'));
        $this->assertTrue($sent);
        $this->assertNotNull($channel->adapter->captured);

        $payload = $this->decode($channel->adapter->captured);
        $this->assertArrayHasKey('attachments', $payload);
        $this->assertCount(1, $payload['attachments']);

        $attachment = $payload['attachments'][0];
        $this->assertSame('#dc3545', $attachment['color']);
        $this->assertSame('header', $attachment['blocks'][0]['type']);
        $this->assertStringContainsString('CRITICAL', $attachment['blocks'][0]['text']['text']);
        $this->assertStringContainsString('SensitiveField', $attachment['blocks'][0]['text']['text']);
        $this->assertSame('Password rotated for user 42', $attachment['blocks'][1]['text']['text']);
    }

    public function testFieldsUseRealNewlineAndEntityEscapeMrkdwn(): void
    {
        // Source contains chars Slack mrkdwn would otherwise interpret as
        // markup / channel mention; the field value must be entity-escaped.
        $log = new AuditLog([
            'id' => 7,
            'type' => AuditLogType::Update->value,
            'source' => '<Foo & Bar>',
            'primary_key' => 42,
            'transaction_key' => 'tx-abc',
            'user_id' => '7',
            'user_display' => 'admin@example.com',
        ]);
        $alert = new Alert('SensitiveField', 'high', 'm', $log, []);

        $channel = $this->buildChannel(
            ['url' => 'https://hooks.slack.com/services/T/B/secret'],
            new Response(['HTTP/1.1 200 OK'], 'ok'),
        );
        $channel->send($alert);

        $payload = $this->decode($channel->adapter->captured);
        $fields = $payload['attachments'][0]['blocks'][2]['fields'];
        $byLabel = [];
        foreach ($fields as $field) {
            [$label, $value] = explode("\n", (string)$field['text'], 2);
            $byLabel[trim($label, '*')] = $value;
        }

        $this->assertSame('&lt;Foo &amp; Bar&gt;', $byLabel['Source']);
        $this->assertSame('update', $byLabel['Event']);
        $this->assertSame('42', $byLabel['Primary key']);
        $this->assertSame('admin@example.com', $byLabel['User']);
    }

    public function testIncludesOptionalUsernameIconAndChannelOverrides(): void
    {
        $channel = $this->buildChannel([
            'url' => 'https://hooks.slack.com/services/T/B/secret',
            'username' => 'AuditStash',
            'icon_emoji' => ':rotating_light:',
            'channel' => '#audit-alerts',
        ], new Response(['HTTP/1.1 200 OK'], 'ok'));

        $channel->send($this->buildAlert('high'));

        $payload = $this->decode($channel->adapter->captured);
        $this->assertSame('AuditStash', $payload['username']);
        $this->assertSame(':rotating_light:', $payload['icon_emoji']);
        $this->assertSame('#audit-alerts', $payload['channel']);
    }

    public function testRejectsTwoHundredWithNonOkBody(): void
    {
        // Slack returns 200 even when the payload is rejected — body must be 'ok'.
        $channel = $this->buildChannel(
            ['url' => 'https://hooks.slack.com/services/T/B/secret'],
            new Response(['HTTP/1.1 200 OK'], 'invalid_payload'),
        );

        $this->assertFalse($channel->send($this->buildAlert('low')));
    }

    public function testNoUrlReturnsFalse(): void
    {
        $channel = new SlackChannel([]);
        $this->assertFalse($channel->send($this->buildAlert('high')));
    }

    public function testAppendsBacklinkSectionWithViewUrl(): void
    {
        $channel = $this->buildChannel(
            ['url' => 'https://hooks.slack.com/services/T/B/secret'],
            new Response(['HTTP/1.1 200 OK'], 'ok'),
        );
        $channel->send($this->buildAlert('high'));

        $payload = $this->decode($channel->adapter->captured);
        $blocks = $payload['attachments'][0]['blocks'];
        $linkBlock = end($blocks);

        $this->assertSame('section', $linkBlock['type']);
        $this->assertSame('mrkdwn', $linkBlock['text']['type']);
        $this->assertStringContainsString(
            '<https://example.com/admin/audit-stash/audit-logs/view/7|View entry in admin →>',
            $linkBlock['text']['text'],
        );
    }

    public function testOmitsBacklinkWhenAuditLogHasNoId(): void
    {
        $log = new AuditLog([
            'type' => AuditLogType::Update->value,
            'source' => 'Users',
            'primary_key' => 42,
        ]);
        $alert = new Alert('Test', 'high', 'm', $log, []);

        $channel = $this->buildChannel(
            ['url' => 'https://hooks.slack.com/services/T/B/secret'],
            new Response(['HTTP/1.1 200 OK'], 'ok'),
        );
        $channel->send($alert);

        $payload = $this->decode($channel->adapter->captured);
        $blocks = $payload['attachments'][0]['blocks'];
        $this->assertCount(3, $blocks, 'expected only header / message / fields, no link block');
        // Last block is the fields grid, not a backlink section.
        $this->assertArrayHasKey('fields', end($blocks));
    }

    /**
     * Builds a SlackChannel whose `createClient()` is wired to a
     * `CapturingAdapter` exposed as `$channel->adapter` so tests can assert
     * on the captured request body.
     *
     * @param array<string, mixed> $config
     * @param \Cake\Http\Client\Response $response
     */
    private function buildChannel(array $config, Response $response): SlackChannel
    {
        return new class ($config, $response) extends SlackChannel {
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
