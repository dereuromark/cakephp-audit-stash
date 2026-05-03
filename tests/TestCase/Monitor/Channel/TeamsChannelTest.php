<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Monitor\Channel;

use AuditStash\AuditLogType;
use AuditStash\Model\Entity\AuditLog;
use AuditStash\Monitor\Alert;
use AuditStash\Monitor\Channel\TeamsChannel;
use Cake\Http\Client;
use Cake\Http\Client\Adapter\Mock as MockAdapter;
use Cake\Http\Client\Response;
use Cake\TestSuite\TestCase;
use Laminas\Diactoros\Request as PsrRequest;
use Psr\Http\Message\RequestInterface;

class TeamsChannelTest extends TestCase
{
    public function testFormatsAsMessageCardWithSeverityColor(): void
    {
        $captured = null;
        $channel = $this->buildChannel(
            ['url' => 'https://outlook.office.com/webhook/.../IncomingWebhook/...'],
            new Response(['HTTP/1.1 200 OK'], '1'),
            $captured,
        );

        $this->assertTrue($channel->send($this->buildAlert('critical')));
        $this->assertNotNull($captured);

        $payload = $this->decode($captured);
        $this->assertSame('MessageCard', $payload['@type']);
        $this->assertSame('https://schema.org/extensions', $payload['@context']);
        $this->assertSame('DC3545', $payload['themeColor']);
        $this->assertStringContainsString('CRITICAL', $payload['title']);
        $this->assertSame('Password rotated for user 42', $payload['text']);

        $facts = $payload['sections'][0]['facts'];
        $byName = array_column($facts, 'value', 'name');
        $this->assertSame('Users', $byName['Source']);
        $this->assertSame('update', $byName['Event']);
        $this->assertSame('42', $byName['Primary key']);
        $this->assertSame('admin@example.com', $byName['User']);
        $this->assertSame('tx-abc', $byName['Transaction']);
    }

    public function testUserSuppliedThemeColorsOverrideDefaults(): void
    {
        $captured = null;
        $channel = $this->buildChannel([
            'url' => 'https://outlook.office.com/webhook/.../IncomingWebhook/...',
            'theme_colors' => ['critical' => 'C0392B'],
        ], new Response(['HTTP/1.1 200 OK'], '1'), $captured);

        $channel->send($this->buildAlert('critical'));

        $this->assertSame('C0392B', $this->decode($captured)['themeColor']);
    }

    public function testUnknownSeverityFallsBackToNeutralColor(): void
    {
        $captured = null;
        $channel = $this->buildChannel(
            ['url' => 'https://outlook.office.com/webhook/.../IncomingWebhook/...'],
            new Response(['HTTP/1.1 200 OK'], '1'),
            $captured,
        );

        $channel->send($this->buildAlert('chartreuse'));

        $this->assertSame('6C757D', $this->decode($captured)['themeColor']);
    }

    public function testNoUrlReturnsFalse(): void
    {
        $channel = new TeamsChannel([]);
        $this->assertFalse($channel->send($this->buildAlert('high')));
    }

    /**
     * @param array<string, mixed> $config
     * @param \Cake\Http\Client\Response $response
     * @param \Psr\Http\Message\RequestInterface|null $captured
     */
    private function buildChannel(array $config, Response $response, ?RequestInterface &$captured): TeamsChannel
    {
        $adapter = new MockAdapter();
        $adapter->addResponse(
            new PsrRequest('https://outlook.office.com/webhook/.../IncomingWebhook/...', 'POST'),
            $response,
            [
                'match' => function (RequestInterface $request) use (&$captured): bool {
                    $captured = $request;

                    return true;
                },
            ],
        );

        return new class ($config, $adapter) extends TeamsChannel {
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
