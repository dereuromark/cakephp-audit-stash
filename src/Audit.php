<?php

declare(strict_types=1);

namespace AuditStash;

use AuditStash\Event\AuditCustomEvent;
use AuditStash\Persister\TablePersister;
use Cake\Utility\Text;

/**
 * Static facade for emitting custom audit events that don't map to entity
 * create/update/delete.
 *
 * ```
 * use AuditStash\Audit;
 *
 * Audit::log('user.login', 'Users', $user->id, [
 *     'ip' => $request->clientIp(),
 *     'user_agent' => $request->getHeaderLine('User-Agent'),
 * ], meta: [
 *     'user_id' => $user->id,
 *     'user_display' => $user->name,
 * ]);
 * ```
 *
 * The persister can be swapped via `Audit::setPersister()` for testing or to
 * apply non-default config (e.g. hash chain enabled).
 */
class Audit
{
    protected static ?PersisterInterface $persister = null;

    /**
     * Persists a single custom audit event.
     *
     * @param string $type Event type identifier (e.g. "user.login"). Must fit
     *   the audit_logs.type column (VARCHAR(64)).
     * @param string $source Logical source / scope (e.g. "Users").
     * @param mixed $primaryKey Identifier for the subject of the action,
     *   typically the related entity id. May be null for actions not tied
     *   to a single record.
     * @param array|null $data Arbitrary payload describing what happened.
     *   Stored in the `changed` column.
     * @param array|null $original Optional prior state. Stored in `original`.
     * @param array|null $meta Metadata. `user_id` and `user_display` keys
     *   land in their dedicated columns; the rest goes into `meta`.
     * @param string|null $displayValue Human-friendly label for the row.
     *
     * @return void
     */
    public static function log(
        string $type,
        string $source,
        mixed $primaryKey = null,
        ?array $data = null,
        ?array $original = null,
        ?array $meta = null,
        ?string $displayValue = null,
    ): void {
        $event = new AuditCustomEvent(
            $type,
            Text::uuid(),
            $primaryKey,
            $source,
            $data,
            $original,
            null,
            $displayValue,
        );

        if ($meta !== null) {
            $event->setMetaInfo($meta);
        }

        static::persister()->logEvents([$event]);
    }

    /**
     * Override the persister used for emitting custom events. Useful for
     * tests, or for applying non-default config (e.g. enabling hashChain).
     *
     * @param \AuditStash\PersisterInterface|null $persister
     *
     * @return void
     */
    public static function setPersister(?PersisterInterface $persister): void
    {
        static::$persister = $persister;
    }

    /**
     * Returns the configured persister, lazily creating a default
     * `TablePersister` on first use.
     *
     * @return \AuditStash\PersisterInterface
     */
    protected static function persister(): PersisterInterface
    {
        if (static::$persister === null) {
            static::$persister = new TablePersister();
        }

        return static::$persister;
    }
}
