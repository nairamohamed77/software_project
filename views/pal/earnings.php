<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
requireRole('Pal');
require_once dirname(__DIR__, 2) . '/models/Points.php';

$db = Database::getInstance()->getConnection();
$pStmt = $db->prepare('SELECT pal_ID, points_balance FROM pal_profiles WHERE User_ID=? LIMIT 1');
$pStmt->execute([currentUserId()]);
$palRow = $pStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$palPid = (int) ($palRow['pal_ID'] ?? 0);
$balPal = (int) ($palRow['points_balance'] ?? 0);

$ledger = Points::ledgerForUser(currentUserId(), 60);

$pageTitle = 'Pal Earnings — CareNest';
$active = 'earnings';

$cashSaved = '';
$alertClass = 'alert-cn-success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['cashout_submit'] ?? '') === '1' && $palPid > 0) {
    try {
        $amount = max(50, (int) ($_POST['amount'] ?? 0));
        $method = substr(trim((string) ($_POST['method'] ?? '')), 0, 200);
        if ($method === '') {
            throw new InvalidArgumentException('Please describe how payouts reach you.');
        }

        $bq = $db->prepare('SELECT points_balance FROM pal_profiles WHERE pal_ID=? LIMIT 1');
        $bq->execute([$palPid]);
        $balPal = (int) ($bq->fetch()['points_balance'] ?? 0);
        if ($amount > $balPal) {
            throw new RuntimeException('Not enough accrued points.');
        }

        $stmtDest = $db->prepare(
            'SELECT destination_ID FROM cashout_destinations WHERE pal_ID=? AND COALESCE(is_default,0)=1 LIMIT 1'
        );
        $stmtDest->execute([$palPid]);
        $destId = (int) ($stmtDest->fetch()['destination_ID'] ?? 0);

        if ($destId <= 0) {
            $insD = $db->prepare(
                "INSERT INTO cashout_destinations (pal_ID, destination_type, provider_name, account_identifier, is_default, is_verified)
                 VALUES (?,?,?,?,1,0)"
            );
            $insD->execute([$palPid, 'Wallet', 'CareNest payouts', substr($method, 0, 150)]);
            $destId = (int) $db->lastInsertId();
        }

        $insReq = $db->prepare(
            'INSERT INTO cashout_requests (pal_ID, destination_ID, points_requested, status) VALUES (?,?,?, \'Pending\')'
        );
        $insReq->execute([$palPid, $destId, $amount]);

        $cashSaved = 'Cash-out request queued for review.';
        $alertClass = 'alert-cn-success';

        $pStmt->execute([currentUserId()]);
        $palRow = $pStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $balPal = (int) ($palRow['points_balance'] ?? 0);
    } catch (Throwable $e) {
        $cashSaved = $e->getMessage();
        $alertClass = 'alert-cn-danger';
    }
}

?>

<?php include dirname(__DIR__) . '/layouts/header.php'; ?>
<?php include dirname(__DIR__) . '/layouts/sidebar_pal.php'; ?>

<main class="main-content">
    <?php if ($cashSaved !== ''): ?>
        <div class="alert-cn <?= e($alertClass) ?> mb-4"><?= e($cashSaved) ?></div>
    <?php endif; ?>

    <div class="cn-card cn-card-body mb-4 cn-balance-banner">
        <h1 class="h4"><?= e('Earned balances') ?></h1>
        <div class="display-6 fw-bold"><?= (int) $balPal ?></div>
        <div style="color:var(--text-secondary);"><?= e('SilverPoints until admins release your payout.') ?></div>
    </div>

    <div class="cn-card cn-card-body mb-4">
        <h2 class="h6"><?= e('Cash-out request') ?></h2>
        <form method="post" class="row g-4">
            <input type="hidden" name="cashout_submit" value="1">
            <div class="col-md-4">
                <label class="cn-label"><?= e('SilverPoints amount') ?></label>
                <input class="cn-input" type="number" name="amount" min="50" required>
            </div>
            <div class="col-md-8">
                <label class="cn-label"><?= e('Wallet / payout details') ?></label>
                <input class="cn-input" name="method" placeholder="<?= e('e.g. Vodafone Cash number, IBAN nickname') ?>" maxlength="200" required>
            </div>
            <div class="col-12">
                <button class="cn-btn cn-btn-primary"><?= e('Submit payout request') ?></button>
            </div>
        </form>
    </div>

    <div class="cn-card cn-card-body">
        <h2 class="h6 mb-4"><?= e('Ledger excerpt') ?></h2>
        <div class="cn-table-wrap">
            <table class="cn-table mb-0">
                <thead><tr><th>#</th><th>Type</th><th>Points</th><th>Bal after</th></tr></thead>
                <tbody>
                <?php foreach ($ledger as $row): ?>
                    <tr>
                        <td><?= (int) ($row['id'] ?? 0) ?></td>
                        <td><?= e((string) ($row['entry_type'] ?? '')) ?></td>
                        <td><?= (int) ($row['points_amount'] ?? 0) ?></td>
                        <td><?= (int) ($row['balance_after'] ?? 0) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$ledger): ?>
                    <tr><td colspan="4" style="color:var(--text-secondary);"><?= e('No ledger rows yet.') ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="small mt-4" style="color:var(--text-secondary);">
            <?= e('Completed visits credit your balance after escrow release (minus the 5% platform insurance earmark).') ?>
        </div>
    </div>
</main>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
