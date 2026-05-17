<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/models/Notification.php';

$navUnread = Notification::countUnread(currentUserId());
$active ??= '';

function cn_navPal(string $name, ?string $active): string {
    return $active === $name ? 'nav-link active' : 'nav-link';
}
?>

<div class="topbar-mobile">
    <span class="fw-bold">CareNest Pal</span>
    <button id="sidebar-toggle" type="button" class="btn btn-link text-white text-decoration-none" aria-label="Open menu"><i class="fas fa-bars fa-lg"></i></button>
</div>

<aside class="sidebar" aria-label="Pal navigation">
    <div class="sidebar-brand"><a href="<?= carenest_url('views/pal/dashboard.php') ?>">CareNest Pal</a></div>
    <nav class="sidebar-nav d-flex flex-column gap-1">
        <a class="<?= e(cn_navPal('dashboard', $active)) ?>" href="<?= carenest_url('views/pal/dashboard.php') ?>"><i class="fas fa-home"></i> Dashboard</a>
        <a class="<?= e(cn_navPal('requests', $active)) ?>" href="<?= carenest_url('views/pal/requests.php') ?>"><i class="fas fa-inbox"></i> Pending Requests</a>
        <a class="<?= e(cn_navPal('schedule', $active)) ?>" href="<?= carenest_url('views/pal/schedule.php') ?>"><i class="fas fa-calendar-check"></i> My Schedule</a>
        <a class="<?= e(cn_navPal('earnings', $active)) ?>" href="<?= carenest_url('views/pal/earnings.php') ?>"><i class="fas fa-coins"></i> My Earnings</a>
        <a class="<?= e(cn_navPal('badges', $active)) ?>" href="<?= carenest_url('views/pal/profile.php') ?>#badges"><i class="fas fa-medal"></i> My Badges</a>
        <a class="<?= e(cn_navPal('notifications', $active)) ?>" href="<?= carenest_url('views/shared/notifications.php') ?>">
            <i class="fas fa-bell"></i> Notifications <?php if ($navUnread > 0): ?><span class="badge-msg"><?= (int) $navUnread ?></span><?php endif; ?>
        </a>
        <a class="<?= e(cn_navPal('profile', $active)) ?>" href="<?= carenest_url('views/pal/profile.php') ?>"><i class="fas fa-user"></i> Profile</a>
        <a href="<?= carenest_url('controllers/AuthController.php?action=logout') ?>" class="nav-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </nav>
</aside>
