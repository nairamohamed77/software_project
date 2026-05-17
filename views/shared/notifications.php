<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
requireLogin();

require_once dirname(__DIR__, 2) . '/models/Notification.php';

$list = Notification::forUser(currentUserId(), 100);

$pageTitle = 'Notifications — CareNest';
$active = 'notifications';

$sidebarFile = match (currentRole()) {
    'Senior' => 'sidebar_senior.php',
    'Pal' => 'sidebar_pal.php',
    'FamilyProxy' => 'sidebar_proxy.php',
    'Admin' => 'sidebar_admin.php',
    default => 'sidebar_admin.php',
};

?>

<?php include dirname(__DIR__) . '/layouts/header.php'; ?>
<?php include dirname(__DIR__) . '/layouts/' . $sidebarFile; ?>

<main class="main-content">

    <?php if (currentRole() === 'Senior'): ?>
        <?php include dirname(__DIR__) . '/layouts/panic_button.php'; ?>
    <?php endif; ?>

    <div class="cn-card cn-card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap mb-4">
            <h1 class="h4"><?= e('Stories from HQ') ?></h1>
            <div class="small" style="color:var(--text-secondary);"><?= e('Mark items read anytime.') ?></div>
        </div>

        <?php foreach ($list as $n): ?>
            <div class="border-bottom pb-4 mb-4" style="border-color:var(--border)!important;">
                <div class="d-flex flex-wrap gap-3 justify-content-between">
                    <div>
                        <div class="fw-bold"><?= e((string) ($n['title'] ?? 'Notice')) ?></div>
                        <div style="color:var(--text-secondary);" class="mt-2"><?= e((string) ($n['message_body'] ?? '')) ?></div>
                    </div>
                    <div style="color:var(--text-light);" class="small">
                        <?= (isset($n['is_read']) && (int)$n['is_read'] === 1) ? e('Read') : e('Unread') ?></div>
                </div>
                <?php if (isset($n['id']) && isset($n['is_read']) && (int)$n['is_read'] === 0): ?>
                    <button type="button" class="cn-btn cn-btn-outline cn-btn-sm mt-3 cn-mark-read-btn" data-id="<?= (int) $n['id'] ?>"><?= e('Mark read') ?></button>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if (!$list): ?>
            <div style="color:var(--text-secondary);"><?= e('All quiet for now.') ?></div>
        <?php endif; ?>
    </div>
</main>

<script>
document.querySelectorAll('.cn-mark-read-btn').forEach((btn)=>{
  btn.addEventListener('click', ()=>{
    const fd = new FormData();
    fd.append('notification_id', btn.dataset.id||'');
    fetch('<?= carenest_url('ajax/mark_notification_read.php') ?>',{method:'POST', credentials:'same-origin', body:fd}).then(()=>location.reload());
  });
});
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
