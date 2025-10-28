<?php
/**
 * @var \AuditStash\Monitor\Alert $alert
 * @var \AuditStash\Model\Entity\AuditLog $auditLog
 */

$severityColors = [
    'critical' => '#dc3545',
    'high' => '#fd7e14',
    'medium' => '#ffc107',
    'low' => '#0dcaf0',
];
$severityColor = $severityColors[$alert->getSeverity()] ?? '#6c757d';
?>
<!DOCTYPE html>
<html>
<head>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background-color: <?= $severityColor ?>;
            color: white;
            padding: 20px;
            border-radius: 5px 5px 0 0;
        }
        .severity {
            font-size: 24px;
            font-weight: bold;
            margin: 0;
        }
        .rule-name {
            font-size: 16px;
            margin: 5px 0 0 0;
        }
        .content {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 0 0 5px 5px;
        }
        .section {
            background-color: white;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 5px;
            border-left: 4px solid <?= $severityColor ?>;
        }
        .section h3 {
            margin-top: 0;
            color: #495057;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        td {
            padding: 8px;
            border-bottom: 1px solid #dee2e6;
        }
        td:first-child {
            font-weight: bold;
            width: 40%;
            color: #6c757d;
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: #6c757d;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <p class="severity">⚠️ <?= strtoupper($alert->getSeverity()) ?> ALERT</p>
            <p class="rule-name"><?= h($alert->getRuleName()) ?></p>
        </div>

        <div class="content">
            <div class="section">
                <h3>Alert Message</h3>
                <p><?= h($alert->getMessage()) ?></p>
            </div>

            <div class="section">
                <h3>Audit Log Details</h3>
                <table>
                    <tr>
                        <td>ID</td>
                        <td><?= h($auditLog->id) ?></td>
                    </tr>
                    <tr>
                        <td>Type</td>
                        <td><?= h(ucfirst($auditLog->type)) ?></td>
                    </tr>
                    <tr>
                        <td>Table</td>
                        <td><code><?= h($auditLog->source) ?></code></td>
                    </tr>
                    <tr>
                        <td>Primary Key</td>
                        <td><?= h($auditLog->primary_key) ?></td>
                    </tr>
                    <tr>
                        <td>Transaction</td>
                        <td><code><?= h($auditLog->transaction) ?></code></td>
                    </tr>
                    <tr>
                        <td>Timestamp</td>
                        <td><?= $auditLog->created ? h($auditLog->created->format('Y-m-d H:i:s')) : 'N/A' ?></td>
                    </tr>
                </table>
            </div>

            <?php if (!empty($alert->getContext())): ?>
            <div class="section">
                <h3>Additional Context</h3>
                <table>
                    <?php foreach ($alert->getContext() as $key => $value): ?>
                    <tr>
                        <td><?= h(ucfirst(str_replace('_', ' ', $key))) ?></td>
                        <td><?= h(is_array($value) ? json_encode($value, JSON_PRETTY_PRINT) : $value) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <div class="footer">
            This is an automated alert from the AuditStash monitoring system.
        </div>
    </div>
</body>
</html>
