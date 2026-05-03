<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Monitor\Channel;

use AuditStash\AuditLogType;
use AuditStash\Model\Entity\AuditLog;
use AuditStash\Monitor\Alert;
use AuditStash\Monitor\Channel\DiscordChannel;
use Cake\Http\Client;
use Cake\Http\Client\Adapter\Mock as MockAdapter;
use Cake\Http\Client\Response;
use Cake\TestSuite\TestCase;
use Laminas\Diactoros\Request as PsrRequest;
use Psr\Http\Message\RequestInterface;

class DiscordChannelTest extends TestCase
{
    public function testFormatsAsEmbedWithSeverityColorAsDecimal(): void
    {
        $captured = null;
        $channel = $this->buildChannel(
            ['url' => 'https://discord.com/api/webhooks/.../...'],
            new Response(['HTTP/1.1 204 No Content'], ''),
            $captured,
        );

        $this->assertTrue($channel->send($this->buildAlert('critical')));
        $this->assertNotNull($captured);

        $payload = $this->decode($captured);
        $this->assertCount(1, $payload['embeds']);

        $embed = $payload['embeds'][0];
        $this->assertSame(14431557, $embed['color']);
        $this->assertStringContainsString('CRITICAL', $embed['title']);
        $this->assertStringContainsString('SensitiveField', $embed['title']);
        $this->assertSame('Password rotated for user 42', $embed['description']);

        $byName = array_column($embed['fields'], 'value', 'name');
        $this->assertSame('Users', $byName['Source']);
        $this->assertSame('update', $byName['Event']);
        $this->assertSame('42', $byName['Primary key']);
        $this->assertSame('admin@example.com', $byName['User']);
    }

    public function testTwoZeroFourCountsAsSuccess(): void
    {
        // Discord's normal success is 204 No Content, which `Response::isOk()` rejects.
        $channel = $this->buildChannel(
            ['url' => 'https://discord.com/api/webhooks/.../...'],
            new Response(['HTTP/1.1 204 No Content'], ''),
            $captured,
        );

        $this->assertTrue($channel->send($this->buildAlert('high')));
    }

    public function testIncludesOptionalUsernameAndAvatarOverrides(): void
    {
        $captured = null;
        $channel = $this->buildChannel([
            'url' => 'https://discord.com/api/webhooks/.../...',
            'username' => 'AuditStash',
            'avatar_url' => 'https://example.com/audit-bot.png',
        ], new Response(['HTTP/1.1 204 No Content'], ''), $captured);

        $channel->send($this->buildAlert('medium'));

        $payload = $this->decode($captured);
        $this->assertSame('AuditStash', $payload['username']);
        $this->assertSame('https://example.com/audit-bot.png', $payload['avatar_url']);
    }

    public function testNoUrlReturnsFalse(): void
    {
        $channel = new DiscordChannel([]);
        $this->assertFalse($channel->send($this->buildAlert('high')));
    }

    /**
     * @param array<string, mixed> $config
     * @param \Cake\Http\Client\Response $response
     * @param \Psr\Http\Message\RequestInterface|null $captured
     */
    private function buildChannel(array $config, Response $response, ?RequestInterface &$captured): DiscordChannel
    {
        $captured = null;
        $adapter = new MockAdapter();
        $adapter->addResponse(
            new PsrRequest('https://discord.com/api/webhooks/.../...', 'POST'),
            $response,
            [
                'match' => function (RequestInterface $request) use (&$captured): bool {
                    $captured = $request;

                    return true;
                },
            ],
        );

        return new class ($config, $adapter) extends DiscordChannel {
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
        /** @var array<string, mixed> $decoded */
        $decoded = (array)json_decode((string)$request->getBody(), true);

        return $decoded;
    }
}
