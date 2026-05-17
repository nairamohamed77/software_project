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
require_once dirname(__DIR__, 2) . '/models/Points.php';

$banks = [
    'visa' => 'Visa',
    'mastercard' => 'Mastercard',
    'amex' => 'American Express',
    'meeza' => 'Meeza',
    'cib' => 'CIB (Egypt)',
    'fab' => 'FAB',
    'qnb' => 'QNB',
    'other' => 'Other (demo)',
];

$packageId = trim((string) ($_GET['package_id'] ?? $_POST['package_id'] ?? ''));
$pkg = carenest_points_package_by_id($packageId);
if ($pkg === null) {
    header('Location: ' . carenest_url('views/shared/buy_points.php'));
    exit;
}

$seniorId = 0;
$ledgerUserId = 0;
$seniorDisplay = '';

if ($role === 'Senior') {
    $sp = Senior::profileByUserId(currentUserId());
    if ($sp === null) {
        header('Location: ' . carenest_url('views/shared/buy_points.php'));
        exit;
    }
    $seniorId = (int) ($sp['senior_ID'] ?? 0);
    $ledgerUserId = currentUserId();
    $u = User::findById(currentUserId());
    $seniorDisplay = $u !== null ? trim((string) ($u['Fname'] ?? '') . ' ' . (string) ($u['Lname'] ?? '')) : '';
} else {
    $pick = (int) ($_GET['senior_id'] ?? $_POST['senior_id'] ?? 0);
    if ($pick <= 0 || !FamilyProxy::proxyCanManageSenior(currentUserId(), $pick)) {
        header('Location: ' . carenest_url('views/shared/buy_points.php'));
        exit;
    }
    $seniorId = $pick;
    $ledgerUserId = (int) (Senior::seniorUserIdFromSeniorRow($pick) ?? 0);
    foreach (FamilyProxy::linkedSeniorsWithProfiles(currentUserId()) as $row) {
        if ((int) ($row['senior_ID'] ?? 0) === $pick) {
            $seniorDisplay = trim((string) ($row['Fname'] ?? '') . ' ' . (string) ($row['Lname'] ?? ''));
            break;
        }
    }
    if ($ledgerUserId <= 0) {
        header('Location: ' . carenest_url('views/shared/buy_points.php'));
        exit;
    }
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'simulate_pay') {
    $bankKey = trim((string) ($_POST['bank_key'] ?? ''));
    $holder = trim((string) ($_POST['cardholder'] ?? ''));
    $number = preg_replace('/\D+/', '', (string) ($_POST['card_number'] ?? ''));
    $exp = trim((string) ($_POST['expiry'] ?? ''));
    $cvv = preg_replace('/\D+/', '', (string) ($_POST['cvv'] ?? ''));

    if (!isset($banks[$bankKey])) {
        $errors[] = 'Select a card network or bank.';
    }
    if ($holder === '' || strlen($holder) < 3) {
        $errors[] = 'Enter the name on the card.';
    }
    if (strlen($number) < 12 || strlen($number) > 19) {
        $errors[] = 'Enter a card number (12–19 digits, demo only).';
    }
    if (!preg_match('/^\d{2}\/\d{2}$/', $exp)) {
        $errors[] = 'Expiry must look like MM/YY.';
    }
    if (strlen($cvv) < 3 || strlen($cvv) > 4) {
        $errors[] = 'Enter a 3 or 4 digit security code (demo).';
    }

    if (!$errors) {
        $points = (int) $pkg['points'];
        $bankLabel = $banks[$bankKey];
        Senior::adjustPoints($seniorId, $points);
        $newBal = Senior::pointsBalance($seniorId);
        $desc = 'Simulated card — ' . $bankLabel . ' — pack ' . $packageId;
        Points::recordLedger($ledgerUserId, null, 'TopUp', $points, $newBal, $desc);

        $redir = $role === 'Senior'
            ? carenest_url('views/senior/wallet.php?demo_pay=1')
            : carenest_url('views/shared/buy_points.php?done=1&senior_id=' . $seniorId);
        header('Location: ' . $redir);
        exit;
    }
}

$pageTitle = 'Demo payment — CareNest';
$active = 'shop';
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

    <div class="mx-auto cn-card cn-card-body" style="max-width: 520px;">
        <h1 class="h5 mb-2"><?= e('Demo card payment') ?></h1>
        <p class="small mb-4" style="color:var(--text-secondary);">
            <?= e('This screen is for looks only. Nothing is sent to a bank. Submitting adds ') ?>
            <strong><?= (int) $pkg['points'] ?></strong> <?= e('SilverPoints to ') ?><strong><?= e($seniorDisplay !== '' ? $seniorDisplay : 'senior wallet') ?></strong>.
        </p>

        <?php foreach ($errors as $er): ?>
            <div class="alert-cn alert-cn-danger"><?= e($er) ?></div>
        <?php endforeach; ?>

        <div class="cn-card cn-card-body mb-4 cn-summary-soft">
            <div class="fw-semibold"><?= e((string) $pkg['title']) ?> <?= e('pack') ?></div>
            <div><?= (int) $pkg['points'] ?> <?= e('SilverPoints') ?> · <?= e('$' . number_format($pkg['usd_cents'] / 100, 2)) ?> <?= e('(display only)') ?></div>
        </div>

        <form method="post" autocomplete="off">
            <input type="hidden" name="action" value="simulate_pay">
            <input type="hidden" name="package_id" value="<?= e($packageId) ?>">
            <?php if ($role === 'FamilyProxy'): ?>
                <input type="hidden" name="senior_id" value="<?= (int) $seniorId ?>">
            <?php endif; ?>

            <div class="mb-3">
                <label class="cn-label" for="bank_key"><?= e('Card network / bank (demo)') ?></label>
                <select class="cn-input" id="bank_key" name="bank_key" required>
                    <option value=""><?= e('— Choose —') ?></option>
                    <?php foreach ($banks as $k => $label): ?>
                        <option value="<?= e($k) ?>"<?= (string) ($_POST['bank_key'] ?? '') === $k ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="cn-label" for="cardholder"><?= e('Name on card') ?></label>
                <input class="cn-input" id="cardholder" name="cardholder" required placeholder="e.g. Jane Doe" value="<?= e((string) ($_POST['cardholder'] ?? '')) ?>">
            </div>
            <div class="mb-3">
                <label class="cn-label" for="card_number"><?= e('Card number') ?></label>
                <input class="cn-input" id="card_number" name="card_number" inputmode="numeric" required placeholder="4111 1111 1111 1111" value="<?= e((string) ($_POST['card_number'] ?? '')) ?>">
            </div>
            <div class="row g-3 mb-3">
                <div class="col-6">
                    <label class="cn-label" for="expiry"><?= e('Expires') ?></label>
                    <input class="cn-input" id="expiry" name="expiry" required placeholder="MM/YY" value="<?= e((string) ($_POST['expiry'] ?? '')) ?>">
                </div>
                <div class="col-6">
                    <label class="cn-label" for="cvv"><?= e('CVV') ?></label>
                    <input class="cn-input" id="cvv" name="cvv" inputmode="numeric" required placeholder="123" maxlength="4" value="<?= e((string) ($_POST['cvv'] ?? '')) ?>">
                </div>
            </div>

            <button type="submit" class="cn-btn cn-btn-primary cn-btn-block"><?= e('Complete demo payment') ?></button>
            <div class="text-center mt-3">
                <a href="<?= carenest_url('views/shared/buy_points.php' . ($role === 'FamilyProxy' ? '?senior_id=' . (int) $seniorId : '')) ?>"><?= e('Cancel') ?></a>
            </div>
        </form>
    </div>
</main>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
