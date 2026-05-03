<?php

declare(strict_types=1);

namespace AuditStash\Monitor\Channel;

use AuditStash\Monitor\Alert;
use Cake\Http\Client;
use Cake\Http\Client\Response;
use Cake\Routing\Router;
use Exception;
use JsonException;
use Psr\Log\LoggerAwareTrait;
use Throwable;

/**
 * Common base for webhook-style channels.
 *
 * Handles the shared concerns — POST, JSON encoding, retry loop, error
 * logging — and delegates the platform-specific payload shape to
 * `formatPayload()` in the subclass.
 *
 * Subclasses may also override `isAcceptable()` if the target service uses
 * a non-standard success convention (e.g. requires the literal string `ok`
 * in the body, or accepts 204 No Content as success).
 *
 * Common config keys consumed by all subclasses:
 * - `url` (string, required) — webhook URL
 * - `headers` (array) — extra headers merged on top of `Content-Type: application/json`
 * - `retry` (int) — total attempts before giving up (default: 1)
 * - `timeout` (int|null) — request timeout in seconds (default: HTTP client default)
 *
 * Subclasses are free to consume additional keys (e.g. `theme_color`,
 * `username`) by reading from `$this->config` directly.
 */
abstract class AbstractWebhookChannel implements ChannelInterface
{
    use LoggerAwareTrait;

    /**
     * @param array $config Channel configuration
     */
    public function __construct(protected array $config = [])
    {
    }

    /**
     * Render the alert into the JSON payload the target service expects.
     *
     * @param \AuditStash\Monitor\Alert $alert
     *
     * @return array<string, mixed>
     */
    abstract protected function formatPayload(Alert $alert): array;

    /**
     * @inheritDoc
     */
    public function send(Alert $alert): bool
    {
        $url = $this->config['url'] ?? null;
        if (!$url) {
            $this->logger?->warning(static::class . ': No URL configured');

            return false;
        }

        $headers = (array)($this->config['headers'] ?? []);
        $retry = max(1, (int)($this->config['retry'] ?? 1));

        $payload = $this->formatPayload($alert);
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            // A silent fallback to an empty body would surface as
            // "Slack returned 400 Bad Request" with no breadcrumb back to
            // the encoding failure. Log and bail out instead.
            $this->logger?->error(static::class . ': Payload encoding failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $client = $this->createClient();
        $options = [
            'headers' => array_merge([
                'Content-Type' => 'application/json',
            ], $headers),
        ];
        if (isset($this->config['timeout'])) {
            $options['timeout'] = (int)$this->config['timeout'];
        }

        for ($attempt = 1; $attempt <= $retry; $attempt++) {
            try {
                $response = $client->post($url, $body, $options);

                if ($this->isAcceptable($response)) {
                    return true;
                }

                $this->logger?->warning(static::class . ': Non-OK response', [
                    'status' => $response->getStatusCode(),
                    'body' => $this->snippet($response->getStringBody()),
                    'attempt' => $attempt,
                ]);
            } catch (Exception $e) {
                $this->logger?->error(static::class . ': Request failed', [
                    'error' => $e->getMessage(),
                    'attempt' => $attempt,
                ]);
            }
        }

        return false;
    }

    /**
     * Returns true when the response should count as a successful delivery.
     * Default is "any 2xx". Override for services with non-standard
     * conventions (e.g. Slack's `ok` body string).
     *
     * @param \Cake\Http\Client\Response $response
     *
     * @return bool
     */
    protected function isAcceptable(Response $response): bool
    {
        return $response->isOk();
    }

    /**
     * HTTP client factory. Overridable so tests can inject a mock adapter.
     *
     * @return \Cake\Http\Client
     */
    protected function createClient(): Client
    {
        return new Client();
    }

    /**
     * Log-friendly truncation of a response body. Avoids dumping multi-KB
     * HTML error pages into the logs while still keeping enough to debug.
     *
     * @param string $body
     *
     * @return string
     */
    protected function snippet(string $body): string
    {
        if (strlen($body) <= 200) {
            return $body;
        }

        return substr($body, 0, 197) . '...';
    }

    /**
     * Builds an absolute URL to the admin view of the audit log entry that
     * triggered the alert, so chat recipients can click through to the
     * record. Uses `Router::url()` and therefore respects
     * `AuditStash.routePath` and `App.fullBaseUrl`.
     *
     * Returns `null` when the URL cannot be resolved (e.g. the audit row
     * has no `id`, the plugin's routes aren't loaded in the current
     * runtime, or routing throws). Subclasses can use that as the signal
     * to omit the link from the rendered payload.
     *
     * @param \AuditStash\Monitor\Alert $alert
     *
     * @return string|null
     */
    protected function viewUrl(Alert $alert): ?string
    {
        // Use `get()` rather than the typed `->id` property so we cover the
        // pre-save / unhydrated case as well as a hydrated row.
        $id = $alert->getAuditLog()->get('id');
        if (!$id) {
            return null;
        }

        try {
            return Router::url([
                'prefix' => 'Admin',
                'plugin' => 'AuditStash',
                'controller' => 'AuditLogs',
                'action' => 'view',
                $id,
            ], true);
        } catch (Throwable) {
            return null;
        }
    }
}
