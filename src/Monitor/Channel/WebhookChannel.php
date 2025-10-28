<?php

declare(strict_types=1);

namespace AuditStash\Monitor\Channel;

use AuditStash\Monitor\Alert;
use Cake\Http\Client;
use Psr\Log\LoggerAwareTrait;

/**
 * Webhook notification channel.
 */
class WebhookChannel implements ChannelInterface
{
    use LoggerAwareTrait;

    /**
     * @param array $config Channel configuration
     */
    public function __construct(protected array $config = [])
    {
    }

    /**
     * @inheritDoc
     */
    public function send(Alert $alert): bool
    {
        $url = $this->config['url'] ?? null;
        if (!$url) {
            $this->logger?->warning('WebhookChannel: No URL configured');

            return false;
        }

        $headers = $this->config['headers'] ?? [];
        $retry = $this->config['retry'] ?? 1;

        $payload = $alert->toArray();

        $client = new Client();
        $attempt = 0;

        while ($attempt < $retry) {
            try {
                $response = $client->post($url, json_encode($payload), [
                    'headers' => array_merge([
                        'Content-Type' => 'application/json',
                    ], $headers),
                ]);

                if ($response->isOk()) {
                    return true;
                }

                $this->logger?->warning('WebhookChannel: Non-OK response', [
                    'status' => $response->getStatusCode(),
                    'attempt' => $attempt + 1,
                ]);
            } catch (\Exception $e) {
                $this->logger?->error('WebhookChannel: Request failed', [
                    'error' => $e->getMessage(),
                    'attempt' => $attempt + 1,
                ]);
            }

            $attempt++;
        }

        return false;
    }
}
