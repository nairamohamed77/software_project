<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
requireRole('Senior');
require_once dirname(__DIR__, 2) . '/models/Senior.php';
require_once dirname(__DIR__, 2) . '/models/Points.php';

$pageTitle = 'SilverPoints Wallet — CareNest';
$active = 'wallet';

$profile = Senior::profileByUserId(currentUserId());
$balance = $profile !== null ? (int) ($profile['points_balance'] ?? 0) : 0;
$tier = $profile !== null ? (string) ($profile['subscription_tier'] ?? 'Standard') : 'Standard';
$renewRaw = ($profile !== null && !empty($profile['subscription_renewal_date'])) ? (string) $profile['subscription_renewal_date'] : '';
$renewFromDb = '';
if ($renewRaw !== '') {
    try {
        $renewFromDb = (new \DateTimeImmutable($renewRaw))->format('F j, Y');
    } catch (Throwable $e) {
        $renewFromDb = $renewRaw;
    }
}

$ledger = Points::ledgerForUser(currentUserId(), 60);

?>

<?php include dirname(__DIR__) . '/layouts/header.php'; ?>
<?php include dirname(__DIR__) . '/layouts/sidebar_senior.php'; ?>

<main class="main-content">
    <?php include dirname(__DIR__) . '/layouts/panic_button.php'; ?>

    <?php if (isset($_GET['demo_pay'])): ?>
        <div class="alert-cn alert-cn-success mb-4"><?= e('Demo payment recorded. Your SilverPoints balance was updated in the app.') ?></div>
    <?php endif; ?>

    <div class="cn-card cn-card-body mb-4">
        <h1 class="h4"><?= e('Your SilverPoints') ?></h1>
        <div class="display-6 fw-semibold"><?= $balance ?> <span style="font-size:1rem; vertical-align:middle;">&star;</span></div>
        <div class="mt-3">
            <a class="cn-btn cn-btn-primary cn-btn-sm" href="<?= carenest_url('views/shared/buy_points.php') ?>"><?= e('Buy packs (demo pay)') ?></a>
        </div>
        <div style="color:var(--text-secondary);" class="mt-3"><?= e('Subscription tier:') ?> <strong><?= e($tier) ?></strong></div>
        <?php if ($renewFromDb !== ''): ?>
            <div style="color:var(--text-secondary);"><?= e('Next renewal:') ?> <strong><?= e($renewFromDb) ?></strong></div>
        <?php else: ?>
            <div style="color:var(--text-secondary);"><?= e('Renewal dates appear here when assigned to your tier.') ?></div>
        <?php endif; ?>
    </div>

    <div class="cn-card cn-card-body">
        <h2 class="h6 mb-3"><?= e('Transaction history') ?></h2>
        <div class="cn-table-wrap">
            <table class="cn-table mb-0">
                <thead>
                <tr>
                    <th><?= e('Ref') ?></th>
                    <th><?= e('Type') ?></th>
                    <th><?= e('Description') ?></th>
                    <th><?= e('Points') ?></th>
                    <th><?= e('Balance After') ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($ledger as $row): ?>
                    <?php
                    $type = strtolower((string) ($row['entry_type'] ?? ''));
                    $color = '';
                    $arrow = '';
                    if ($type === 'topup') { $color='var(--success)'; $arrow='&uarr;';}
                    elseif ($type === 'booking_reserve') {$color='var(--warning)'; $arrow='&darr;';}
                    elseif ($type === 'cancellation_refund') {$color='var(--success)'; $arrow='&uarr;';}
                    elseif ($type === 'visit_payment') {$color='var(--text-secondary)'; $arrow='';}
                    elseif ($type === 'karma_bonus') {$color='var(--caramel)'; $arrow='&uarr;';}
                    elseif (str_contains($type, 'gift')) {$color='var(--sage)'; $arrow='&uarr;';}
                    ?>
                    <tr>
                        <td><?= (int) ($row['id'] ?? 0) ?></td>
                        <td><?= e((string) ($row['entry_type'] ?? '')) ?></td>
                        <td><?php
                            $vid = (int) ($row['visit_id'] ?? 0);
                            $desc = (string) ($row['description'] ?? '');
                            if ($desc !== '') {
                                echo e($desc);
                            } elseif ($vid > 0) {
                                echo e('Visit #' . $vid);
                            } else {
                                echo e('—');
                            }
                            ?></td>
                        <td style="color:<?= e($color) ?>;"><?php if ($arrow !== ''): echo $arrow.' '; endif; ?>
                            <?= (int) ($row['points_amount'] ?? 0) ?>
                        </td>
                        <td><?= (int) ($row['balance_after'] ?? 0) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$ledger): ?>
                    <tr><td colspan="5" style="color:var(--text-secondary);"><?= e('No transactions yet.') ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="small mt-3" style="color:var(--text-secondary);"><?= e('Shows your latest 60 ledger entries.') ?></div>
    </div>
</main>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
