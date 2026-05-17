<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/models/Notification.php';

$navUnread = Notification::countUnread(currentUserId());
$active ??= '';

function cn_navProxy(string $name, ?string $active): string {
    return $active === $name ? 'nav-link active' : 'nav-link';
}
?>

<div class="topbar-mobile">
    <span class="fw-bold">Family Proxy</span>
    <button id="sidebar-toggle" type="button" class="btn btn-link text-white text-decoration-none" aria-label="Open menu"><i class="fas fa-bars fa-lg"></i></button>
</div>

<aside class="sidebar" aria-label="Family Proxy navigation">
    <div class="sidebar-brand"><a href="<?= carenest_url('views/proxy/dashboard.php') ?>">CareNest Proxy</a></div>
    <nav class="sidebar-nav d-flex flex-column gap-1">
        <a class="<?= e(cn_navProxy('dashboard', $active)) ?>" href="<?= carenest_url('views/proxy/dashboard.php') ?>"><i class="fas fa-home"></i> Dashboard</a>
        <a class="<?= e(cn_navProxy('shop', $active)) ?>" href="<?= carenest_url('views/shared/buy_points.php') ?>"><i class="fas fa-bag-shopping"></i> Buy SilverPoints</a>
        <a class="<?= e(cn_navProxy('book', $active)) ?>" href="<?= carenest_url('views/proxy/book_visit.php') ?>"><i class="fas fa-calendar-plus"></i> Book a visit</a>
        <a class="<?= e(cn_navProxy('history', $active)) ?>" href="<?= carenest_url('views/proxy/visit_history.php') ?>"><i class="fas fa-history"></i> Visit history</a>
        <a class="<?= e(cn_navProxy('extensions', $active)) ?>" href="<?= carenest_url('views/shared/visit_extensions.php') ?>"><i class="fas fa-clock"></i> Visit extensions</a>
        <a class="<?= e(cn_navProxy('manage', $active)) ?>" href="<?= carenest_url('views/proxy/manage_senior.php') ?>"><i class="fas fa-user-friends"></i> Manage Senior</a>
        <a class="<?= e(cn_navProxy('profile', $active)) ?>" href="<?= carenest_url('views/proxy/profile.php') ?>"><i class="fas fa-user"></i> My Profile</a>
        <a class="<?= e(cn_navProxy('notifications', $active)) ?>" href="<?= carenest_url('views/shared/notifications.php') ?>">
            <i class="fas fa-bell"></i> Notifications <?php if ($navUnread > 0): ?><span class="badge-msg"><?= (int) $navUnread ?></span><?php endif; ?>
        </a>
        <a href="<?= carenest_url('controllers/AuthController.php?action=logout') ?>" class="nav-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </nav>
</aside>
