<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
requireLogin();

$role = currentRole();
if (!in_array($role, ['Senior', 'FamilyProxy'], true)) {
    header('Location: ' . carenest_url('views/shared/error.php?code=403'));
    exit;
}

require_once dirname(__DIR__, 2) . '/config/payments.php';
require_once dirname(__DIR__, 2) . '/models/Senior.php';
require_once dirname(__DIR__, 2) . '/models/User.php';
require_once dirname(__DIR__, 2) . '/models/FamilyProxy.php';

$packages = carenest_points_packages();
$errors = [];

$seniorId = 0;
$ledgerUserId = 0;
$seniorDisplay = '';

if ($role === 'Senior') {
    $sp = Senior::profileByUserId(currentUserId());
    if ($sp === null) {
        $errors[] = 'Senior profile not found.';
    } else {
        $seniorId = (int) ($sp['senior_ID'] ?? 0);
        $ledgerUserId = currentUserId();
        $u = User::findById(currentUserId());
        $seniorDisplay = $u !== null ? trim((string) ($u['Fname'] ?? '') . ' ' . (string) ($u['Lname'] ?? '')) : 'Your account';
    }
} else {
    $linked = FamilyProxy::linkedSeniorsWithProfiles(currentUserId());
    $pick = (int) ($_GET['senior_id'] ?? ($linked[0]['senior_ID'] ?? 0));
    if ($pick <= 0 || !FamilyProxy::proxyCanManageSenior(currentUserId(), $pick)) {
        $errors[] = 'Choose a linked senior to add SilverPoints for.';
    } else {
        $seniorId = $pick;
        $ledgerUserId = (int) (Senior::seniorUserIdFromSeniorRow($pick) ?? 0);
        foreach ($linked as $row) {
            if ((int) ($row['senior_ID'] ?? 0) === $pick) {
                $seniorDisplay = trim((string) ($row['Fname'] ?? '') . ' ' . (string) ($row['Lname'] ?? ''));
                break;
            }
        }
        if ($ledgerUserId <= 0) {
            $errors[] = 'Could not resolve senior account.';
        }
    }
}

$pageTitle = 'Buy SilverPoints — CareNest';
$active = 'shop';
$done = isset($_GET['done']);

?>

<?php include dirname(__DIR__) . '/layouts/header.php'; ?>
<?php
if ($role === 'Senior') {
    include dirname(__DIR__) . '/layouts/sidebar_senior.php';
} else {
    include dirname(__DIR__) . '/layouts/sidebar_proxy.php';
}
?>

<main class="main-content">
    <?php if ($role === 'Senior'): ?>
        <?php include dirname(__DIR__) . '/layouts/panic_button.php'; ?>
    <?php endif; ?>

    <div class="cn-card cn-card-body mb-4">
        <h1 class="h4 mb-2"><?= e('SilverPoints packs') ?></h1>
        <p class="mb-0" style="color:var(--text-secondary);">
            <?= e('Demo checkout only — no real bank or card processing. SilverPoints are added in the app after you complete the simulated screen.') ?>
        </p>
        <p class="small mt-2 mb-0" style="color:var(--text-secondary);">
            <?= e('Credits apply to: ') ?><strong><?= e($seniorDisplay !== '' ? $seniorDisplay : '—') ?></strong>
        </p>
        <?php if ($role === 'FamilyProxy' && $seniorId > 0): ?>
            <form method="get" class="mt-3">
                <label class="cn-label" for="senior_pick"><?= e('Credit a different linked senior') ?></label>
                <select class="cn-input" id="senior_pick" name="senior_id" onchange="this.form.submit()">
                    <?php foreach (FamilyProxy::linkedSeniorsWithProfiles(currentUserId()) as $row): ?>
                        <?php $sid = (int) ($row['senior_ID'] ?? 0); ?>
                        <option value="<?= $sid ?>"<?= $sid === $seniorId ? ' selected' : '' ?>><?= e(trim((string) ($row['Fname'] ?? '') . ' ' . (string) ($row['Lname'] ?? ''))) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
    </div>

    <?php foreach ($errors as $er): ?>
        <div class="alert-cn alert-cn-danger"><?= e($er) ?></div>
    <?php endforeach; ?>

    <?php if ($done && !$errors): ?>
        <div class="alert-cn alert-cn-success mb-4"><?= e('Simulated payment completed. SilverPoints were added to the selected senior.') ?></div>
    <?php endif; ?>

    <?php if ($errors && $seniorId <= 0): ?>
        <a class="cn-btn cn-btn-outline" href="<?= carenest_url('views/senior/dashboard.php') ?>"><?= e('Back') ?></a>
    <?php elseif (!$errors): ?>
        <div class="row g-4">
            <?php foreach ($packages as $p): ?>
                <div class="col-md-4">
                    <div class="cn-card cn-card-body h-100 d-flex flex-column">
                        <h2 class="h5"><?= e((string) $p['title']) ?></h2>
                        <div class="display-6 fw-bold mb-2" style="color:var(--accent-strong);"><?= (int) $p['points'] ?></div>
                        <div class="mb-2" style="color:var(--text-secondary);"><?= e('SilverPoints') ?></div>
                        <p class="small flex-grow-1" style="color:var(--text-secondary);"><?= e((string) $p['blurb']) ?></p>
                        <div class="fw-semibold mb-3"><?= e('Demo price: $' . number_format(((int) $p['usd_cents']) / 100, 2)) ?> <?= e('USD') ?></div>
                        <?php
                        $qs = 'package_id=' . rawurlencode((string) $p['id']);
                        if ($role === 'FamilyProxy') {
                            $qs .= '&senior_id=' . (int) $seniorId;
                        }
                        ?>
                        <a class="cn-btn cn-btn-primary cn-btn-block" href="<?= carenest_url('views/shared/simulated_checkout.php?' . $qs) ?>"><?= e('Continue to demo pay') ?></a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
