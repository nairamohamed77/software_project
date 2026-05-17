<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
requireLogin();

$pageTitle = 'In-app messaging hub — Beta';
?>

<?php include dirname(__DIR__) . '/layouts/header.php'; ?>

<main class="container py-5">
    <div class="cn-card cn-card-body">
        <h1><?= e('Neighborly chatter') ?></h1>
        <p style="color:var(--text-secondary);"><?= e('This beta keeps threads calm by routing through coordinators. Visit notifications for official updates.') ?></p>
        <textarea class="cn-input mb-4" placeholder="<?= e('Thoughtful memo (not stored persistently yet)') ?>" rows="4"></textarea>
        <button type="button" class="cn-btn cn-btn-outline" onclick="alert('Messaging queue coming soon.')"><?= e('Draft send') ?></button>
    </div>
</main>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
