<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
requireRole('Admin');
require_once dirname(__DIR__, 2) . '/models/Notification.php';

$db = Database::getInstance()->getConnection();
$resultMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $title = substr(trim((string) ($_POST['title'] ?? '')), 0, 150);
        $body = substr(trim((string) ($_POST['body'] ?? '')), 0, 6000);
        if ($title === '' || $body === '') {
            throw new InvalidArgumentException('Headline plus body required.');
        }
        $scopes = trim((string) ($_POST['audience'] ?? 'all'));

        $targetRole = match ($scopes) {
            'seniors' => 'Senior',
            'pals' => 'Pal',
            'proxies' => 'FamilyProxy',
            default => 'All',
        };

        $stmtIns = $db->prepare(
            'INSERT INTO admin_broadcasts (admin_ID, title, message_body, target_role, severity_level, is_active)
             VALUES (?,?,?,?, ?, 1)'
        );
        $stmtIns->execute([currentUserId(), $title, $body, $targetRole, 'Info']);

        $usersStmt = match ($scopes) {
            'seniors' => "SELECT User_ID FROM users WHERE role_type='Senior' AND COALESCE(is_active,0)=1",
            'pals' => "SELECT User_ID FROM users WHERE role_type='Pal' AND COALESCE(is_active,0)=1",
            'proxies' => "SELECT User_ID FROM users WHERE role_type='FamilyProxy' AND COALESCE(is_active,0)=1",
            default => 'SELECT User_ID FROM users WHERE COALESCE(is_active,0)=1',
        };
        $audience = $db->query($usersStmt)->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($audience as $row) {
            Notification::enqueue((int) ($row['User_ID'] ?? 0), 'Admin_Broadcast', $title, $body);
        }

        $resultMessage = 'Broadcast echoed to every chosen mailbox.';
    } catch (Throwable $e) {
        $resultMessage = $e->getMessage();
    }
}

$pageTitle = 'Broadcasts — CareNest Admin';
$active = 'broadcasts';
?>

<?php include dirname(__DIR__) . '/layouts/header.php'; ?>
<?php include dirname(__DIR__) . '/layouts/sidebar_admin.php'; ?>

<main class="main-content">
    <?php if ($resultMessage !== ''): ?>
        <div class="alert-cn <?= strtolower($resultMessage) === strtolower('Broadcast echoed to every chosen mailbox.') ? 'alert-cn-success' : 'alert-cn-danger' ?>"><?= e($resultMessage) ?></div>
    <?php endif; ?>

    <div class="cn-card cn-card-body mx-auto mb-5" style="max-width:900px;">
        <h1 class="h5 mb-3"><?= e('Thoughtful broadcasts') ?></h1>
        <form method="post" class="d-flex flex-column gap-4">
            <div>
                <label class="cn-label"><?= e('Headline') ?></label>
                <input class="cn-input" name="title" required maxlength="150" placeholder="<?= e('Holiday schedule shift') ?>">
            </div>
            <div>
                <label class="cn-label"><?= e('Body') ?></label>
                <textarea class="cn-input" name="body" rows="6" required placeholder="<?= e('Tone: warm reassurance + timelines') ?>"></textarea>
            </div>
            <div>
                <label class="cn-label"><?= e('Audience') ?></label>
                <select class="cn-input" name="audience">
                    <option value="all"><?= e('Everyone') ?></option>
                    <option value="seniors"><?= e('Seniors') ?></option>
                    <option value="pals"><?= e('Pals') ?></option>
                    <option value="proxies"><?= e('Family proxies') ?></option>
                </select>
            </div>
            <button class="cn-btn cn-btn-primary cn-btn-lg" type="submit"><?= e('Send broadcast') ?></button>
        </form>
    </div>
</main>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
