<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Monitor\Channel;

use AuditStash\AuditLogType;
use AuditStash\AuditStashPlugin;
use AuditStash\Model\Entity\AuditLog;
use AuditStash\Monitor\Alert;
use AuditStash\Monitor\Channel\DiscordChannel;
use AuditStash\Test\CapturingAdapter;
use Cake\Core\Configure;
use Cake\Http\Client;
use Cake\Http\Client\Response;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;
use Psr\Http\Message\RequestInterface;

class DiscordChannelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('App.fullBaseUrl', 'https://example.com');
        Router::reload();
        (new AuditStashPlugin())->routes(Router::createRouteBuilder('/'));
    }

    public function testFormatsAsEmbedWithSeverityColorAsDecimal(): void
    {
        $channel = $this->buildChannel(
            ['url' => 'https://discord.com/api/webhooks/.../...'],
            new Response(['HTTP/1.1 204 No Content'], ''),
        );

        $this->assertTrue($channel->send($this->buildAlert('critical')));
        $this->assertNotNull($channel->adapter->captured);

        $payload = $this->decode($channel->adapter->captured);
        $this->assertCount(1, $payload['embeds']);

        $embed = $payload['embeds'][0];
        $this->assertSame(0xDC3545, $embed['color']);
        $this->assertStringContainsString('CRITICAL', $embed['title']);
        $this->assertStringContainsString('SensitiveField', $embed['title']);
        $this->assertSame('Password rotated for user 42', $embed['description']);

        $byName = array_column($embed['fields'], 'value', 'name');
        $this->assertSame('Users', $byName['Source']);
        $this->assertSame('update', $byName['Event']);
        $this->assertSame('42', $byName['Primary key']);
        $this->assertSame('admin@example.com', $byName['User']);
    }

    public function testLowSeverityColorMatchesSlackPalette(): void
    {
        // Sanity check: the decimal must be the integer form of #0d6efd so
        // Slack and Discord render the same severity color.
        $channel = $this->buildChannel(
            ['url' => 'https://discord.com/api/webhooks/.../...'],
            new Response(['HTTP/1.1 204 No Content'], ''),
        );
        $channel->send($this->buildAlert('low'));

        $payload = $this->decode($channel->adapter->captured);
        $this->assertSame(0x0D6EFD, $payload['embeds'][0]['color']);
    }

    public function testTwoZeroFourCountsAsSuccess(): void
    {
        // Discord's normal success is 204 No Content, which `Response::isOk()` rejects.
        $channel = $this->buildChannel(
            ['url' => 'https://discord.com/api/webhooks/.../...'],
            new Response(['HTTP/1.1 204 No Content'], ''),
        );

        $this->assertTrue($channel->send($this->buildAlert('high')));
    }

    public function testIncludesOptionalUsernameAndAvatarOverrides(): void
    {
        $channel = $this->buildChannel([
            'url' => 'https://discord.com/api/webhooks/.../...',
            'username' => 'AuditStash',
            'avatar_url' => 'https://example.com/audit-bot.png',
        ], new Response(['HTTP/1.1 204 No Content'], ''));

        $channel->send($this->buildAlert('medium'));

        $payload = $this->decode($channel->adapter->captured);
        $this->assertSame('AuditStash', $payload['username']);
        $this->assertSame('https://example.com/audit-bot.png', $payload['avatar_url']);
    }

    public function testNoUrlReturnsFalse(): void
    {
        $channel = new DiscordChannel([]);
        $this->assertFalse($channel->send($this->buildAlert('high')));
    }

    public function testEmbedUrlPointsAtAdminView(): void
    {
        $channel = $this->buildChannel(
            ['url' => 'https://discord.com/api/webhooks/.../...'],
            new Response(['HTTP/1.1 204 No Content'], ''),
        );
        $channel->send($this->buildAlert('high'));

        $payload = $this->decode($channel->adapter->captured);
        $this->assertSame(
            'https://example.com/admin/audit-stash/audit-logs/view/7',
            $payload['embeds'][0]['url'],
        );
    }

    public function testOmitsEmbedUrlWhenAuditLogHasNoId(): void
    {
        $log = new AuditLog([
            'type' => AuditLogType::Update->value,
            'source' => 'Users',
            'primary_key' => 42,
        ]);
        $alert = new Alert('Test', 'high', 'm', $log, []);

        $channel = $this->buildChannel(
            ['url' => 'https://discord.com/api/webhooks/.../...'],
            new Response(['HTTP/1.1 204 No Content'], ''),
        );
        $channel->send($alert);

        $payload = $this->decode($channel->adapter->captured);
        $this->assertArrayNotHasKey('url', $payload['embeds'][0]);
    }

    /**
     * @param array<string, mixed> $config
     * @param \Cake\Http\Client\Response $response
     */
    private function buildChannel(array $config, Response $response): DiscordChannel
    {
        return new class ($config, $response) extends DiscordChannel {
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
        /** @var array<string, mixed> $decoded */
        $decoded = (array)json_decode((string)$request->getBody(), true);

        return $decoded;
    }
}
