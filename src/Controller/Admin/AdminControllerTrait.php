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
 * AuditLogs). Sets the layout, loads helpers/Flash, and enforces the required
 * `AuditStash.adminAccess` Closure gate.
 *
 * Audit logs typically contain who-did-what records (PII, IP, before/after
 * field values), so a forgotten host-side route guard would expose more than
 * a typical admin page. The plugin therefore refuses to serve any of its
 * admin actions unless `AuditStash.adminAccess` is explicitly configured —
 * same posture as `cakephp-queue` / `cakephp-databaselog`. Pass
 * `fn() => true` if you want to delegate the decision back to the host
 * AppController / Authorization stack.
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
     * Required access gate.
     *
     * Audit logs commonly contain sensitive who-did-what records (PII, IP
     * addresses, before/after field values), so the plugin refuses to serve
     * any admin action unless `AuditStash.adminAccess` is explicitly set to
     * a Closure. The Closure receives the current request and must return
     * literal `true` to grant access; anything else (returns false, returns
     * a truthy non-bool, throws, isn't a Closure, isn't set at all) yields
     * a 403.
     *
     * To delegate the decision entirely to the host AppController /
     * Authorization stack, pass an explicit `fn() => true` — that's an
     * intentional choice rather than an accidental forgotten guard.
     *
     * @param \Cake\Event\EventInterface<\Cake\Controller\Controller> $event
     *
     * @throws \Cake\Http\Exception\ForbiddenException When the configured Closure rejects the request, is missing, or isn't a Closure.
     *
     * @return void
     */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);

        $check = Configure::read('AuditStash.adminAccess');
        if ($check === null) {
            throw new ForbiddenException(
                'AuditStash admin requires `AuditStash.adminAccess` to be configured. '
                . 'Set it to a Closure that returns true for authorized requests, '
                . 'or `fn() => true` to delegate fully to the host app.',
            );
        }
        if (!($check instanceof Closure)) {
            throw new ForbiddenException('AuditStash.adminAccess must be a Closure');
        }

        // Coexist with cakephp/authorization: the gate IS the authorization
        // decision, so silence the policy check.
        if ($this->components()->has('Authorization') && method_exists($this->components()->get('Authorization'), 'skipAuthorization')) {
            $this->components()->get('Authorization')->skipAuthorization();
        }

        try {
            $allowed = $check($this->request) === true;
        } catch (Throwable $e) {
            Log::warning(sprintf('AuditStash.adminAccess threw %s: %s', $e::class, $e->getMessage()));

            throw new ForbiddenException('AuditStash admin access denied');
        }

        if (!$allowed) {
            throw new ForbiddenException('AuditStash admin access denied');
        }
    }
}
