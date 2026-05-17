<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
requireRole('Admin');

$db = Database::getInstance()->getConnection();

$completedVisits = 0;
$registeredUsers = 0;
$panicSignals = 0;

try {
    $completedVisits = (int) ($db->query("SELECT COUNT(*) AS c FROM visit_requests WHERE LOWER(TRIM(status))='completed'")->fetch()['c'] ?? 0);
} catch (Throwable $e) {
}

try {
    $registeredUsers = (int) ($db->query('SELECT COUNT(*) AS c FROM users')->fetch()['c'] ?? 0);
} catch (\Throwable $e) {
}

try {
    $panicSignals = (int) ($db->query('SELECT COUNT(*) AS c FROM emergency_threads')->fetch()['c'] ?? 0);
} catch (\Throwable $e) {
}

$cards = [
    ['label' => 'Completed journeys', 'value' => $completedVisits],
    ['label' => 'Registered accounts', 'value' => $registeredUsers],
    ['label' => 'Emergency threads archived', 'value' => $panicSignals],
];

$pageTitle = 'Insights — Admin';
$active = 'reports';
?>

<?php include dirname(__DIR__) . '/layouts/header.php'; ?>
<?php include dirname(__DIR__) . '/layouts/sidebar_admin.php'; ?>

<main class="main-content">

    <style>
        @media print {
            .sidebar,
            .topbar-mobile,
            #report-print-controls {
                display: none !important;
            }

            body {
                padding: 0 !important;
            }

            .main-content {
                margin-left: 0 !important;
                padding: 12px !important;
            }
        }
    </style>

    <div id="report-print-controls" class="no-print cn-card cn-card-body mb-3">
        <button type="button" class="btn btn-primary">
            <i class="fas fa-file-pdf me-2" aria-hidden="true"></i><?= e('Print / Save as PDF') ?>
        </button>
        <span class="small ms-2" style="color:var(--text-secondary);"><?= e('Uses your browser’s print dialog; choose Save as PDF for a downloadable file.') ?></span>
        <script>
            (function () {
                document.currentScript.closest('#report-print-controls').querySelector('button').addEventListener('click', function () {
                    window.print();
                });
            })();
        </script>
    </div>

    <div class="cn-card cn-card-body mb-4">
        <h1 class="h4 mb-4"><?= e('Platform stewardship analytics') ?></h1>
        <div class="row g-4">
            <?php foreach ($cards as $card): ?>
                <div class="col-md-4">
                    <div class="cn-card cn-card-body text-center py-5">
                        <div class="display-5 fw-semibold"><?= (int) $card['value'] ?></div>
                        <div style="color:var(--text-secondary);"><?= e($card['label']) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="col-md-12">
                <div class="alert-cn alert-cn-success">
                    <?= e('Export this page with Print → Save as PDF for offline sharing.') ?></div>
            </div>
        </div>
    </div>

</main>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
