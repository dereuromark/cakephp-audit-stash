<?php
/**
 * AuditStash Admin Layout
 *
 * Self-contained Bootstrap 5 layout for the AuditStash admin interface.
 * Uses CDN resources to avoid conflicts with host application styling.
 *
 * @var \Cake\View\View $this
 */

use Cake\Core\Configure;

$dashboardAutoRefresh = (int)Configure::read('AuditStash.dashboardAutoRefresh');
$controller = $this->getRequest()->getParam('controller');
$action = $this->getRequest()->getParam('action');
$isIndex = $controller === 'AuditLogs' && $action === 'index';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?= $this->Html->charset() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if ($dashboardAutoRefresh && $isIndex) { ?>
    <meta http-equiv="refresh" content="<?= $dashboardAutoRefresh ?>">
    <?php } ?>
    <title><?= $this->fetch('title') ? $this->fetch('title') . ' - ' : '' ?>AuditStash Admin</title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" integrity="sha512-Evv84Mr4kqVGRNSgIGL/F/aIDqQb7xQ2vcrdIwxfjThSH8CSR7PBEakCr51Ck+w+/U6swU2Im1vVX0SVk9ABhg==" crossorigin="anonymous" referrerpolicy="no-referrer" />

    <style>
        :root {
            --audit-primary: #6f42c1;
            --audit-primary-light: #8e68d4;
            --audit-primary-dark: #5c37a3;
            --audit-success: #198754;
            --audit-warning: #ffc107;
            --audit-danger: #dc3545;
            --audit-info: #0dcaf0;
            --audit-secondary: #6c757d;
            --audit-dark: #212529;
            --audit-light: #f8f9fa;
            --audit-sidebar-bg: linear-gradient(135deg, #4a2c6a 0%, #2d1a42 100%);
            --audit-sidebar-width: 260px;
            --audit-header-height: 56px;
        }

        body {
            background-color: #f5f6fa;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            min-height: 100vh;
        }

        /* Header */
        .audit-header {
            background: var(--audit-sidebar-bg);
            height: var(--audit-header-height);
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1030;
            display: flex;
            align-items: center;
            padding: 0 1rem;
        }

        .audit-header .navbar-brand {
            color: #fff;
            font-weight: 600;
            font-size: 1.1rem;
        }

        .audit-header .navbar-brand:hover {
            color: rgba(255, 255, 255, 0.9);
        }

        .audit-header .navbar-brand i {
            margin-right: 0.5rem;
        }

        /* Sidebar */
        .audit-sidebar {
            position: fixed;
            top: var(--audit-header-height);
            left: 0;
            bottom: 0;
            width: var(--audit-sidebar-width);
            background: var(--audit-sidebar-bg);
            padding: 1rem 0;
            overflow-y: auto;
            z-index: 1020;
        }

        .audit-sidebar .nav-section {
            padding: 0 1rem;
            margin-bottom: 1.5rem;
        }

        .audit-sidebar .nav-section-title {
            color: rgba(255, 255, 255, 0.5);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
            padding: 0 0.75rem;
        }

        .audit-sidebar .nav-link {
            color: rgba(255, 255, 255, 0.8);
            padding: 0.5rem 0.75rem;
            border-radius: 0.375rem;
            margin-bottom: 0.25rem;
            transition: all 0.15s ease;
        }

        .audit-sidebar .nav-link:hover {
            color: #fff;
            background: rgba(255, 255, 255, 0.1);
        }

        .audit-sidebar .nav-link.active {
            color: #fff;
            background: rgba(255, 255, 255, 0.15);
            font-weight: 500;
        }

        .audit-sidebar .nav-link i {
            width: 1.25rem;
            margin-right: 0.5rem;
            text-align: center;
        }

        /* Main content */
        .audit-main {
            margin-left: var(--audit-sidebar-width);
            margin-top: var(--audit-header-height);
            padding: 1.5rem;
            min-height: calc(100vh - var(--audit-header-height));
        }

        @media (max-width: 991.98px) {
            .audit-sidebar {
                display: none;
            }
            .audit-main {
                margin-left: 0;
            }
        }

        /* Cards */
        .card {
            border: none;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border-radius: 0.5rem;
        }

        .card-header {
            background-color: #fff;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            font-weight: 600;
        }

        /* Stats cards */
        .stats-card {
            padding: 1.25rem;
            border-radius: 0.5rem;
            background: #fff;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }

        .stats-card .stats-value {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1.2;
        }

        .stats-card .stats-label {
            font-size: 0.875rem;
            color: var(--audit-secondary);
            margin-top: 0.25rem;
        }

        /* Table styling */
        .table {
            margin-bottom: 0;
        }

        .table th {
            border-top: none;
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--audit-secondary);
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        .table td {
            vertical-align: middle;
        }

        /* Badges */
        .badge {
            font-weight: 500;
            padding: 0.35em 0.65em;
        }

        /* Pagination */
        .pagination {
            margin-bottom: 0.5rem;
        }

        .pagination .page-link {
            border-radius: 0.375rem;
            margin: 0 0.125rem;
            border: none;
            color: var(--audit-primary);
        }

        .pagination .page-item.active .page-link {
            background-color: var(--audit-primary);
            border-color: var(--audit-primary);
        }

        /* Buttons */
        .btn-primary {
            background-color: var(--audit-primary);
            border-color: var(--audit-primary);
        }

        .btn-primary:hover {
            background-color: var(--audit-primary-dark);
            border-color: var(--audit-primary-dark);
        }

        .btn-outline-primary {
            color: var(--audit-primary);
            border-color: var(--audit-primary);
        }

        .btn-outline-primary:hover {
            background-color: var(--audit-primary);
            border-color: var(--audit-primary);
        }

        /* Footer */
        .audit-footer {
            padding: 1rem 0;
            font-size: 0.875rem;
            color: var(--audit-secondary);
            border-top: 1px solid rgba(0, 0, 0, 0.05);
            margin-top: 2rem;
        }

        /* Flash messages */
        .alert {
            border: none;
            border-radius: 0.5rem;
        }

        /* Mobile nav toggle */
        .mobile-nav-toggle {
            display: none;
            color: #fff;
            background: transparent;
            border: none;
            font-size: 1.25rem;
            padding: 0.5rem;
        }

        @media (max-width: 991.98px) {
            .mobile-nav-toggle {
                display: block;
            }
        }

        /* Diff styles */
        .audit-diff, .audit-diff-inline { font-size: 0.9rem; }
        .audit-diff td, .audit-diff-inline td { vertical-align: top; }
        pre { background-color: #f8f9fa; padding: 0.5rem; border-radius: 0.25rem; }
        .diff-wrapper { width: 100%; border-collapse: collapse; font-family: monospace; font-size: 13px; }
        .diff-wrapper th, .diff-wrapper td { padding: 4px 8px; border: 1px solid #dee2e6; vertical-align: top; }
        .diff-wrapper .line-num { width: 40px; background: #f8f9fa; color: #6c757d; text-align: right; user-select: none; }
        .diff-wrapper .sign { width: 20px; text-align: center; font-weight: bold; }
        .diff-wrapper tr.unchanged td { background: #f8f9fa; }
        .diff-wrapper tr.added td { background: #e6ffec; }
        .diff-wrapper tr.removed td { background: #ffebe9; }
        .diff-wrapper tr.changed td { background: #fef6d9; }
        .diff-wrapper tr.separator td { background: #f0f0f0; font-style: italic; }
        .diff-wrapper ins { background: #94f094; text-decoration: none; padding: 1px 2px; font-weight: bold; }
        .diff-wrapper del { background: #f09494; text-decoration: none; padding: 1px 2px; font-weight: bold; }
        .diff-wrapper .old { background-color: #ffebe9; }
        .diff-wrapper .new { background-color: #e6ffec; }
    </style>
    <?= $this->fetch('css') ?>
</head>
<body>
    <!-- Header -->
    <header class="audit-header">
        <button class="mobile-nav-toggle me-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#auditMobileNav">
            <i class="fas fa-bars"></i>
        </button>
        <a class="navbar-brand" href="<?= $this->Url->build(['plugin' => 'AuditStash', 'prefix' => 'Admin', 'controller' => 'AuditLogs', 'action' => 'index']) ?>">
            <i class="fas fa-clipboard-list"></i>
            AuditStash Admin
        </a>
    </header>

    <!-- Sidebar -->
    <?= $this->element('AuditStash.AuditStash/sidebar') ?>

    <!-- Mobile Navigation -->
    <?= $this->element('AuditStash.AuditStash/mobile_nav') ?>

    <!-- Main Content -->
    <main class="audit-main">
        <!-- Flash messages -->
        <?= $this->element('AuditStash.flash/success') ?>
        <?= $this->element('AuditStash.flash/error') ?>
        <?= $this->element('AuditStash.flash/warning') ?>
        <?= $this->element('AuditStash.flash/info') ?>

        <?= $this->fetch('content') ?>

        <!-- Footer -->
        <footer class="audit-footer">
            <div class="d-flex justify-content-between align-items-center">
                <span>AuditStash Plugin</span>
                <span>PHP <?= PHP_VERSION ?></span>
            </div>
        </footer>
    </main>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });

        // Form confirmation
        document.querySelectorAll('form[data-confirm]').forEach(function(form) {
            form.addEventListener('submit', function(e) {
                if (!confirm(form.dataset.confirm)) {
                    e.preventDefault();
                }
            });
        });
    });
    </script>
    <?= $this->fetch('script') ?>
    <?= $this->fetch('postLink') ?>
</body>
</html>
