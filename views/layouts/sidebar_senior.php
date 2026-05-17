<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/models/Notification.php';

$navUnread = Notification::countUnread(currentUserId());
$active ??= '';

function navActive(string $name, ?string $active): string {
    return $active === $name ? 'nav-link active' : 'nav-link';
}
?>

<div class="topbar-mobile">
    <span class="fw-bold">CareNest</span>
    <button id="sidebar-toggle" type="button" class="btn btn-link text-white text-decoration-none" aria-label="Open menu"><i class="fas fa-bars fa-lg"></i></button>
</div>

<aside class="sidebar" aria-label="Senior navigation">
    <div class="sidebar-brand"><a href="<?= carenest_url('views/senior/dashboard.php') ?>">CareNest</a></div>
    <nav class="sidebar-nav d-flex flex-column gap-1">
        <a class="<?= e(navActive('dashboard', $active)) ?>" href="<?= carenest_url('views/senior/dashboard.php') ?>"><i class="fas fa-home"></i> Dashboard</a>
        <a class="<?= e(navActive('book', $active)) ?>" href="<?= carenest_url('views/senior/book_visit.php') ?>"><i class="fas fa-calendar-plus"></i> Book a Visit</a>
        <a class="<?= e(navActive('history', $active)) ?>" href="<?= carenest_url('views/senior/visit_history.php') ?>"><i class="fas fa-list"></i> Visit History</a>
        <a class="<?= e(navActive('extensions', $active)) ?>" href="<?= carenest_url('views/shared/visit_extensions.php') ?>"><i class="fas fa-clock"></i> Visit extensions</a>
        <a class="<?= e(navActive('wallet', $active)) ?>" href="<?= carenest_url('views/senior/wallet.php') ?>"><i class="fas fa-coins"></i> My SilverPoints</a>
        <a class="<?= e(navActive('shop', $active)) ?>" href="<?= carenest_url('views/shared/buy_points.php') ?>"><i class="fas fa-bag-shopping"></i> Buy packs</a>
        <a class="<?= e(navActive('notifications', $active)) ?>" href="<?= carenest_url('views/shared/notifications.php') ?>">
            <i class="fas fa-bell"></i> Notifications <?php if ($navUnread > 0): ?><span class="badge-msg"><?= (int) $navUnread ?></span><?php endif; ?>
        </a>
        <a class="<?= e(navActive('profile', $active)) ?>" href="<?= carenest_url('views/senior/profile.php') ?>"><i class="fas fa-user"></i> My Profile</a>
        <a class="nav-link panic-sidebar" href="<?= carenest_url('views/senior/panic.php') ?>"><span class="me-2">&#128680;</span> PANIC</a>
        <a class="<?= e(navActive('logout', $active)) ?>" href="<?= carenest_url('controllers/AuthController.php?action=logout') ?>"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </nav>
</aside>
