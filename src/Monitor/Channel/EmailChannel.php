<?php

declare(strict_types=1);

namespace AuditStash\Monitor\Channel;

use AuditStash\Monitor\Alert;
use Cake\Mailer\Mailer;
use Psr\Log\LoggerAwareTrait;

/**
 * Email notification channel.
 */
class EmailChannel implements ChannelInterface
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
        try {
            $mailer = new Mailer('default');

            $to = $this->config['to'] ?? null;
            if (!$to) {
                $this->logger?->warning('EmailChannel: No recipient configured');

                return false;
            }

            $from = $this->config['from'] ?? 'audit@localhost';
            $template = $this->config['template'] ?? 'AuditStash.audit_alert';

            $mailer
                ->setTo($to)
                ->setFrom($from)
                ->setSubject($this->getSubject($alert))
                ->setEmailFormat('both')
                ->setViewVars([
                    'alert' => $alert,
                    'auditLog' => $alert->getAuditLog(),
                ])
                ->viewBuilder()
                    ->setTemplate($template);

            $mailer->deliver();

            return true;
        } catch (\Exception $e) {
            $this->logger?->error('EmailChannel: Failed to send alert', [
                'error' => $e->getMessage(),
                'alert' => $alert->toArray(),
            ]);

            return false;
        }
    }

    /**
     * Get email subject for the alert.
     *
     * @param \AuditStash\Monitor\Alert $alert The alert
     *
     * @return string
     */
    protected function getSubject(Alert $alert): string
    {
        $severity = strtoupper($alert->getSeverity());
        $ruleName = $alert->getRuleName();

        return sprintf('[%s] Audit Alert: %s', $severity, $ruleName);
    }
}
