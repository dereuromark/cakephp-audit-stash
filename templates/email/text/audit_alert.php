<?php
/**
 * @var \AuditStash\Monitor\Alert $alert
 * @var \AuditStash\Model\Entity\AuditLog $auditLog
 */
?>
AUDIT ALERT
===========

Severity: <?= strtoupper($alert->getSeverity()) ?>

Rule: <?= $alert->getRuleName() ?>


Message:
--------
<?= $alert->getMessage() ?>


Audit Log Details:
------------------
ID: <?= $auditLog->id ?>

Type: <?= ucfirst($auditLog->type) ?>

Table: <?= $auditLog->source ?>

Primary Key: <?= $auditLog->primary_key ?>

Transaction: <?= $auditLog->transaction ?>

Timestamp: <?= $auditLog->created ? $auditLog->created->format('Y-m-d H:i:s') : 'N/A' ?>


<?php if (!empty($alert->getContext())): ?>
Additional Context:
-------------------
<?php foreach ($alert->getContext() as $key => $value): ?>
<?= ucfirst(str_replace('_', ' ', $key)) ?>: <?= is_array($value) ? json_encode($value) : $value ?>

<?php endforeach; ?>
<?php endif; ?>

---
This is an automated alert from the AuditStash monitoring system.
