<?php

declare(strict_types=1);

namespace AuditStash\Meta;

use Cake\Event\EventInterface;
use Cake\Event\EventListenerInterface;
use Cake\Http\ServerRequest;

/**
 * Event listener that enriches audit logs with environment/source information.
 *
 * Automatically detects the request source:
 * - 'web' - Standard web request
 * - 'cli' - Command line (bin/cake commands)
 * - 'api' - API request (based on Accept header or URL pattern)
 * - 'queue' - Queue worker (when explicitly set)
 *
 * Optionally captures request-derived forensic fields when a request is
 * provided and the field is opted into via `capture`:
 * - 'user_agent' - User-Agent header
 * - 'referer' - Referer header
 * - 'session_id' - PHP session id (only included if a session is active)
 *
 * These are off by default — they often contain PII / fingerprintable data
 * and may have GDPR implications, so consumers must opt in explicitly.
 *
 * Usage in Application.php or bootstrap:
 *
 * ```php
 * // Auto-detect source
 * EventManager::instance()->on(new EnvironmentMetadata());
 *
 * // Explicitly set source (e.g., in queue worker)
 * EventManager::instance()->on(new EnvironmentMetadata('queue'));
 *
 * // With optional extra data
 * EventManager::instance()->on(new EnvironmentMetadata(null, [
 *     'deployment' => 'production',
 *     'server' => gethostname(),
 * ]));
 *
 * // Capture forensic fields from the request
 * EventManager::instance()->on(new EnvironmentMetadata(
 *     request: $this->getRequest(),
 *     capture: ['user_agent', 'referer', 'session_id'],
 * ));
 * ```
 */
class EnvironmentMetadata implements EventListenerInterface
{
    /**
     * Request-derived fields that can be opted into via `capture`.
     *
     * @var array<string>
     */
    public const SUPPORTED_CAPTURE_FIELDS = ['user_agent', 'referer', 'session_id'];

    /**
     * The request source type.
     */
    protected string $source;

    /**
     * Extra metadata to include.
     *
     * @var array<string, mixed>
     */
    protected array $extraData;

    /**
     * Optional request object for more accurate detection.
     */
    protected ?ServerRequest $request;

    /**
     * Request-derived field names to capture into meta.
     *
     * @var array<string>
     */
    protected array $capture;

    /**
     * Constructor.
     *
     * @param string|null $source Explicit source type, or null for auto-detection
     * @param array<string, mixed> $extraData Additional metadata to include
     * @param \Cake\Http\ServerRequest|null $request Optional request for API detection / forensic capture
     * @param array<string> $capture Request-derived fields to include in meta. Supported:
     *   `user_agent`, `referer`, `session_id`. Ignored when `$request` is null.
     *   Off by default because these fields can carry PII.
     */
    public function __construct(
        ?string $source = null,
        array $extraData = [],
        ?ServerRequest $request = null,
        array $capture = [],
    ) {
        $this->request = $request;
        $this->extraData = $extraData;
        $this->source = $source ?? $this->detectSource();
        $this->capture = array_values(array_intersect($capture, self::SUPPORTED_CAPTURE_FIELDS));
    }

    /**
     * Returns an array with the events this class listens to.
     *
     * @return array<string, string>
     */
    public function implementedEvents(): array
    {
        return ['AuditStash.beforeLog' => 'beforeLog'];
    }

    /**
     * Enriches all the passed audit logs with environment metadata.
     *
     * @param \Cake\Event\EventInterface $event The AuditStash.beforeLog event
     * @param array<\AuditStash\Event\BaseEvent> $logs The audit log event objects
     *
     * @return void
     */
    public function beforeLog(EventInterface $event, array $logs): void
    {
        $meta = [
            'request_source' => $this->source,
        ] + $this->captureRequestFields() + $this->extraData;

        foreach ($logs as $log) {
            $log->setMetaInfo($log->getMetaInfo() + $meta);
        }
    }

    /**
     * Build the request-derived meta entries for the configured capture set.
     * Skips fields that have no value (empty headers, no active session) so
     * the audit row does not store useless empty strings.
     *
     * @return array<string, mixed>
     */
    protected function captureRequestFields(): array
    {
        if (!($this->request instanceof ServerRequest) || !$this->capture) {
            return [];
        }

        $captured = [];
        foreach ($this->capture as $field) {
            $value = match ($field) {
                'user_agent' => $this->request->getHeaderLine('User-Agent'),
                'referer' => $this->request->getHeaderLine('Referer'),
                'session_id' => $this->resolveSessionId(),
                default => '',
            };

            if ($value !== '' && $value !== null) {
                $captured[$field] = $value;
            }
        }

        return $captured;
    }

    /**
     * Returns the active session id, or null when no session has been started
     * (CLI requests, queue workers, anonymous API hits).
     *
     * @return string|null
     */
    protected function resolveSessionId(): ?string
    {
        if (!($this->request instanceof ServerRequest)) {
            return null;
        }

        $session = $this->request->getSession();
        if (!$session->started()) {
            return null;
        }

        $id = $session->id();

        return $id !== '' ? $id : null;
    }

    /**
     * Get the detected or configured source.
     *
     * @return string
     */
    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * Auto-detect the request source based on the environment.
     *
     * @return string One of: 'cli', 'api', 'web'
     */
    protected function detectSource(): string
    {
        // CLI detection (bin/cake commands)
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            return 'cli';
        }

        // API detection (if request is available)
        if ($this->request instanceof ServerRequest && $this->isApiRequest($this->request)) {
            return 'api';
        }

        // Default to web
        return 'web';
    }

    /**
     * Check if the request appears to be an API request.
     *
     * @param \Cake\Http\ServerRequest $request The request to check
     *
     * @return bool
     */
    protected function isApiRequest(ServerRequest $request): bool
    {
        // Check Accept header for JSON/XML
        $accept = $request->getHeaderLine('Accept');
        if (str_contains($accept, 'application/json')) {
            return true;
        }
        // Only consider XML an API request if browser HTML types are not present
        // (browsers like Firefox send "text/html,...,application/xml;q=0.9" for normal form submissions)
        if (
            str_contains($accept, 'application/xml') &&
            !str_contains($accept, 'text/html') &&
            !str_contains($accept, 'application/xhtml+xml')
        ) {
            return true;
        }

        // Check Content-Type for JSON/XML
        $contentType = $request->getHeaderLine('Content-Type');
        if (str_contains($contentType, 'application/json') || str_contains($contentType, 'application/xml')) {
            return true;
        }

        // Check URL pattern (common API prefixes)
        $path = $request->getPath();
        if (preg_match('#^/api(/|$)#i', $path)) {
            return true;
        }

        // Check for common API-specific headers
        if ($request->hasHeader('X-Api-Key') || $request->hasHeader('Authorization')) {
            // Only if it looks like an API auth header (Bearer token, API key, etc.)
            $auth = $request->getHeaderLine('Authorization');
            if (str_starts_with($auth, 'Bearer ') || str_starts_with($auth, 'Basic ')) {
                return true;
            }
        }

        return false;
    }
}
