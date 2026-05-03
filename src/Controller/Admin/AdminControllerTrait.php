<?php

declare(strict_types=1);

namespace AuditStash\Controller\Admin;

use Cake\Core\Configure;
use Cake\Event\EventInterface;
use Cake\Http\Exception\ForbiddenException;
use Cake\Log\Log;
use Closure;
use Throwable;

/**
 * Shared init for the AuditStash admin controllers (Dashboard, Coverage,
 * AuditLogs). Sets the layout, loads helpers/Flash, and applies the optional
 * `AuditStash.accessCheck` Closure as a defense-in-depth gate.
 */
trait AdminControllerTrait
{
    use LoadHelperTrait;

    /**
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();

        $this->loadComponent('Flash');
        $this->loadHelpers();

        $adminLayout = Configure::read('AuditStash.adminLayout');
        if ($adminLayout === false) {
            // Disable plugin layout, use the host app's default.
        } elseif ($adminLayout === null) {
            $this->viewBuilder()->setLayout('AuditStash.audit_stash');
        } else {
            $this->viewBuilder()->setLayout($adminLayout);
        }
    }

    /**
     * Optional defense-in-depth access gate.
     *
     * Audit logs commonly contain sensitive who-did-what records (PII, IP
     * addresses, what fields were changed). Set `AuditStash.accessCheck` to
     * a Closure that receives the current request and returns literal `true`
     * to grant access; anything else (returns false, returns a truthy
     * non-bool, throws) yields a 403.
     *
     * Unset = no-op (host AppController auth alone applies).
     *
     * @param \Cake\Event\EventInterface<\Cake\Controller\Controller> $event
     *
     * @throws \Cake\Http\Exception\ForbiddenException When the configured Closure rejects the request.
     *
     * @return void
     */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);

        $check = Configure::read('AuditStash.accessCheck');
        if ($check === null) {
            return;
        }
        if (!($check instanceof Closure)) {
            throw new ForbiddenException('AuditStash.accessCheck must be a Closure');
        }

        // Coexist with cakephp/authorization: the gate IS the authorization
        // decision, so silence the policy check.
        if ($this->components()->has('Authorization') && method_exists($this->components()->get('Authorization'), 'skipAuthorization')) {
            $this->components()->get('Authorization')->skipAuthorization();
        }

        try {
            $allowed = $check($this->request) === true;
        } catch (ForbiddenException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning(sprintf('AuditStash.accessCheck threw %s: %s', $e::class, $e->getMessage()));

            throw new ForbiddenException('AuditStash admin access denied');
        }

        if (!$allowed) {
            throw new ForbiddenException('AuditStash admin access denied');
        }
    }
}
