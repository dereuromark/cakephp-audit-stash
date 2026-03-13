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
 * ```
 */
class EnvironmentMetadata implements EventListenerInterface
{
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
     * Constructor.
     *
     * @param string|null $source Explicit source type, or null for auto-detection
     * @param array<string, mixed> $extraData Additional metadata to include
     * @param \Cake\Http\ServerRequest|null $request Optional request for API detection
     */
    public function __construct(
        ?string $source = null,
        array $extraData = [],
        ?ServerRequest $request = null,
    ) {
        $this->request = $request;
        $this->extraData = $extraData;
        $this->source = $source ?? $this->detectSource();
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
        ] + $this->extraData;

        foreach ($logs as $log) {
            $log->setMetaInfo($log->getMetaInfo() + $meta);
        }
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
        if ($this->request !== null && $this->isApiRequest($this->request)) {
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
        if (str_contains($accept, 'application/json') || str_contains($accept, 'application/xml')) {
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
