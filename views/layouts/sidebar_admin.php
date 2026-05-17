<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/models/Notification.php';

$navUnread = Notification::countUnread(currentUserId());
$active ??= '';

function cn_navAdmin(string $name, ?string $active): string {
    return $active === $name ? 'nav-link active' : 'nav-link';
}
?>

<div class="topbar-mobile">
    <span class="fw-bold">CareNest Admin</span>
    <button id="sidebar-toggle" type="button" class="btn btn-link text-white text-decoration-none" aria-label="Open menu"><i class="fas fa-bars fa-lg"></i></button>
</div>

<aside class="sidebar" aria-label="Admin navigation">
    <div class="sidebar-brand"><a href="<?= carenest_url('views/admin/dashboard.php') ?>">Admin</a></div>
    <nav class="sidebar-nav d-flex flex-column gap-1">
        <a class="<?= e(cn_navAdmin('dashboard', $active)) ?>" href="<?= carenest_url('views/admin/dashboard.php') ?>"><i class="fas fa-chart-pie"></i> Dashboard</a>
        <a class="<?= e(cn_navAdmin('users', $active)) ?>" href="<?= carenest_url('views/admin/users.php') ?>"><i class="fas fa-users"></i> Manage Users</a>
        <a class="<?= e(cn_navAdmin('categories', $active)) ?>" href="<?= carenest_url('views/admin/service_categories.php') ?>"><i class="fas fa-tags"></i> Service categories</a>
        <a class="<?= e(cn_navAdmin('visits', $active)) ?>" href="<?= carenest_url('views/admin/visits.php') ?>"><i class="fas fa-clipboard-list"></i> All Visits</a>
        <a class="<?= e(cn_navAdmin('disputes', $active)) ?>" href="<?= carenest_url('views/admin/disputes.php') ?>"><i class="fas fa-balance-scale"></i> Disputes</a>
        <a class="<?= e(cn_navAdmin('welfare', $active)) ?>" href="<?= carenest_url('views/admin/welfare_checks.php') ?>"><i class="fas fa-heart"></i> Welfare checks</a>
        <a class="<?= e(cn_navAdmin('audit_logs', $active)) ?>" href="<?= carenest_url('views/admin/audit_logs.php') ?>"><i class="fas fa-clipboard-list"></i> Audit logs</a>
        <a class="<?= e(cn_navAdmin('checks', $active)) ?>" href="<?= carenest_url('views/admin/background_checks.php') ?>"><i class="fas fa-check-circle"></i> Background Checks</a>
        <a class="<?= e(cn_navAdmin('badges', $active)) ?>" href="<?= carenest_url('views/admin/skill_badges.php') ?>"><i class="fas fa-award"></i> Skill Badges</a>
        <a class="<?= e(cn_navAdmin('broadcasts', $active)) ?>" href="<?= carenest_url('views/admin/broadcasts.php') ?>"><i class="fas fa-bullhorn"></i> Broadcasts</a>
        <a class="<?= e(cn_navAdmin('reports', $active)) ?>" href="<?= carenest_url('views/admin/reports.php') ?>"><i class="fas fa-chart-line"></i> Reports</a>
        <a class="<?= e(cn_navAdmin('profile', $active)) ?>" href="<?= carenest_url('views/admin/profile.php') ?>"><i class="fas fa-user"></i> My Profile</a>
        <a class="<?= e(cn_navAdmin('notifications', $active)) ?>" href="<?= carenest_url('views/shared/notifications.php') ?>">
            <i class="fas fa-bell"></i> Notifications <?php if ($navUnread > 0): ?><span class="badge-msg"><?= (int) $navUnread ?></span><?php endif; ?>
        </a>
        <a href="<?= carenest_url('controllers/AuthController.php?action=logout') ?>" class="nav-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </nav>
</aside>
