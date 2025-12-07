<?php

declare(strict_types=1);

namespace AuditStash\Meta;

use Cake\Event\EventInterface;
use Cake\Event\EventListenerInterface;
use Cake\Http\ServerRequest as Request;

/**
 * Event listener that is capable of enriching the audit logs
 * with the current request info.
 */
class RequestMetadata implements EventListenerInterface
{
    /**
     * The current request.
     *
     * @var \Cake\Http\ServerRequest
     */
    protected Request $request;

    /**
     * The current user ID.
     *
     * @var string|int|null
     */
    protected string|int|null $userId;

    /**
     * The current user's display value (name, email, or any identifier for display).
     *
     * @var string|null
     */
    protected ?string $userDisplay;

    /**
     * Constructor.
     *
     * @param \Cake\Http\ServerRequest $request The current request
     * @param string|int|null $userId The current user ID (for linking/filtering)
     * @param string|null $userDisplay The current user's display value (optional, for human-readable display)
     */
    public function __construct(
        Request $request,
        int|string|null $userId = null,
        ?string $userDisplay = null,
    ) {
        $this->request = $request;
        $this->userId = $userId;
        $this->userDisplay = $userDisplay;
    }

    /**
     * Returns an array with the events this class listens to.
     *
     * @return array
     */
    public function implementedEvents(): array
    {
        return ['AuditStash.beforeLog' => 'beforeLog'];
    }

    /**
     * Enriches all the passed audit logs to add the request info metadata.
     *
     * @param \Cake\Event\EventInterface $event The AuditStash.beforeLog event
     * @param array<\AuditStash\Event\BaseEvent> $logs The audit log event objects
     *
     * @return void
     */
    public function beforeLog(EventInterface $event, array $logs): void
    {
        $meta = [
            'ip' => $this->request->clientIp(),
            'url' => $this->request->getRequestTarget(),
            'user_id' => $this->userId,
            'user_display' => $this->userDisplay,
        ];

        foreach ($logs as $log) {
            $log->setMetaInfo($log->getMetaInfo() + $meta);
        }
    }
}
