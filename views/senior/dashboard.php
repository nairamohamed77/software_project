<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
requireRole('Senior');
require_once dirname(__DIR__, 2) . '/models/Senior.php';
require_once dirname(__DIR__, 2) . '/models/Notification.php';
require_once dirname(__DIR__, 2) . '/models/User.php';

$pageTitle = 'Senior Dashboard — CareNest';
$user = User::findById(currentUserId());
$sProfile = Senior::profileByUserId(currentUserId());
$sId = $sProfile !== null ? (int) ($sProfile['senior_ID'] ?? 0) : 0;

$fname = isset($_SESSION['fname']) ? (string) $_SESSION['fname'] : ((string) ($user['Fname'] ?? 'Friend'));
$h = (int) date('G');
$tod = ($h < 12) ? 'Morning' : (($h < 17) ? 'Afternoon' : 'Evening');

$upcoming = $sId ? Senior::upcomingVisits($sId, 5) : [];
$totalVisits = $sId ? Senior::totalVisits($sId) : 0;
$completedVisits = $sId ? Senior::completedVisits($sId) : 0;
$balance = $sProfile !== null ? (int) ($sProfile['points_balance'] ?? 0) : 0;
$notes = '';
$emer = '';
$phone = '';

if ($sId) {
    $hr = Senior::healthBySeniorId($sId);
    if ($hr) {
        $notes = trim((string) ($hr['medical_notes'] ?? ''));
        $emer = trim((string) ($sProfile['emergency_contact_name'] ?? ''));
    }
}

$phoneDb = isset($user['Phone']) ? (string) $user['Phone'] : (isset($user['phone']) ? (string) $user['phone'] : '');
$phone = trim($phoneDb) !== '' ? trim($phoneDb) : '';

$subtitle = $sId ? Senior::nextVisitSnippet($sId) : 'Setup your CareNest profile.';

function cn_visit_badge(?string $status): array {
    $key = strtolower(str_replace([' ', '_', '-'], '', (string) $status));
    return match ($key) {
        'pending' => ['Pending', 'badge-pending'],
        'accepted' => ['Accepted', 'badge-accepted'],
        'enroute' => ['En route', 'badge-en-route'],
        'live' => ['Live', 'badge-live'],
        'completed' => ['Completed', 'badge-completed'],
        'cancelled' => ['Cancelled', 'badge-cancelled'],
        'rejected' => ['Rejected', 'badge-cancelled'],
        'noshow' => ['No show', 'badge-cancelled'],
        default => [trim((string) $status) !== '' ? (string) $status : 'Pending', 'badge-pending'],
    };
}

$unreadNotifications = Notification::countUnread(currentUserId());

?>
<?php $active = 'dashboard'; ?>
<?php include dirname(__DIR__) . '/layouts/header.php'; ?>
<?php include dirname(__DIR__) . '/layouts/sidebar_senior.php'; ?>

<main class="main-content container-fluid">

    <?php include dirname(__DIR__) . '/layouts/panic_button.php'; ?>

    <section class="cn-card cn-card-body mb-4 cn-welcome-shell">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
            <div>
                <h1><?= e("Good {$tod}, {$fname}") ?> &#128075;</h1>
                <p class="mb-2" style="color: var(--text-secondary);"><?= e($subtitle) ?></p>
            </div>
            <div>
                <a class="cn-btn cn-btn-caramel cn-btn-lg" href="<?= carenest_url('views/senior/book_visit.php') ?>">+ Request New Service</a>
            </div>
        </div>
    </section>

    <div class="row g-4 mb-4">
        <div class="col-6 col-lg-3">
            <div class="cn-card stat-card cn-card-body">
                <div class="stat-icon" style="background: var(--sage);"><i class="fas fa-calendar"></i></div>
                <div>
                    <div class="h4 mb-0"><?= (int) $totalVisits ?></div>
                    <div style="color: var(--text-secondary);">Total Visits</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="cn-card stat-card cn-card-body">
                <div class="stat-icon" style="background: var(--caramel);"><i class="fas fa-star"></i></div>
                <div>
                    <div class="h4 mb-0"><?= (int) $balance ?></div>
                    <div style="color: var(--text-secondary);">SilverPoints</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="cn-card stat-card cn-card-body">
                <div class="stat-icon" style="background: var(--success);"><i class="fas fa-check"></i></div>
                <div>
                    <div class="h4 mb-0"><?= (int) $completedVisits ?></div>
                    <div style="color: var(--text-secondary);">Completed Visits</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="cn-card stat-card cn-card-body">
                <div class="stat-icon" style="background: var(--warning);"><i class="fas fa-bell"></i></div>
                <div>
                    <div class="h4 mb-0"><?= (int) $unreadNotifications ?></div>
                    <div style="color: var(--text-secondary);">Unread Notifications</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-lg-8">
            <div class="cn-card cn-card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="h4 mb-0">Upcoming Visits</h2>
                    <a href="<?= carenest_url('views/senior/visit_history.php') ?>">View All</a>
                </div>
                <?php if (!$upcoming): ?>
                    <div style="color: var(--text-secondary);">No upcoming visits planned.</div>
                <?php else: ?>
                    <div class="d-flex flex-column gap-3">
                        <?php foreach ($upcoming as $v): ?>
                            <?php $bd = cn_visit_badge(isset($v['status']) ? (string) $v['status'] : null); ?>
                            <div class="cn-card cn-card-body p-3 d-flex justify-content-between flex-wrap gap-2">
                                <div>
                                    <div class="fw-semibold"><?= e(trim(($v['category_name'] ?? 'Service'))) ?></div>
                                    <div style="color: var(--text-secondary);">
                                        <?= e(trim(($v['Pal_Fname'] ?? '') . ' ' . ($v['Pal_Lname'] ?? ''))) ?>
                                        <?= e(' • ' . ($v['scheduled_start'] ?? '')) ?>
                                    </div>
                                </div>
                                <div><span class="badge-status <?= e($bd[1]) ?>"><?= e($bd[0]) ?></span></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="cn-card cn-card-body mb-4" style="background: linear-gradient(140deg, var(--caramel), var(--peach)); color: var(--text-primary);">
                <h3 class="h5"><?= e('SilverPoints') ?></h3>
                <div class="display-6 fw-bold text-white"><?= (int) $balance ?></div>
                <div class="mt-3" style="color: var(--text-white);">&starf; <?= e('Keep using trusted Pals whenever you need a hand.') ?></div>
            </div>
            <div class="cn-card cn-card-body">
                <h4 class="h6 mb-3"><?= e('Quick Profile') ?></h4>
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div style="width:56px;height:56px;border-radius:50%;background:var(--sage-dark);display:flex;align-items:center;justify-content:center;color:var(--text-white);font-weight:700;">
                        <?= strtoupper(mb_substr($fname ?: 'CN', 0, 2)) ?>
                    </div>
                    <div>
                        <div class="fw-bold"><?= e(trim($fname . ' ' . ($_SESSION['lname'] ?? ($user['Lname'] ?? '')))) ?></div>
                        <div style="color:var(--text-secondary);">Senior Member</div>
                    </div>
                </div>
                <?php if ($phone !== ''): ?>
                    <div class="mb-2"><i class="fas fa-phone me-2"></i><?= e($phone) ?></div>
                <?php endif; ?>
                <div style="color:var(--text-secondary);" class="mb-2">
                    <?= e(($notes !== '' ? $notes : 'Medical notes recorded securely.')) ?>
                </div>
                <?php if ($emer !== ''): ?>
                    <div>Emergency: <span class="fw-semibold"><?= e($emer) ?></span></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <section>
        <h3 class="h5 mb-3"><?= e('Quick actions') ?></h3>
        <div class="row g-3">
            <?php foreach (
                [['Grocery Shopping', 'fa-shopping-cart', 'Basket'],
                    ['Medicine Pickup', 'fa-pills', 'Health'],
                    ['Tech Help', 'fa-laptop', 'Screens'],
                    ['Gardening', 'fa-leaf', 'Outdoors'],
                    ['Companionship', 'fa-mug-hot', 'Visit'],
                    ['Cleaning', 'fa-broom', 'Home'],
                    ['Transport', 'fa-car-side', 'Rides'],
                    ['Errands', 'fa-route', 'To-dos']]
                as $qa
            ): ?>
                <div class="col-6 col-md-3">
                    <a href="<?= carenest_url('views/senior/book_visit.php') ?>" class="text-decoration-none">
                        <div class="cn-card cn-card-body text-center py-4 h-100" style="color:var(--text-primary);">
                            <div class="mb-3" style="color:var(--sage-dark);"><i class="fas <?= e($qa[1]) ?> fa-2x"></i></div>
                            <div class="fw-semibold"><?= e($qa[0]) ?></div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</main>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
