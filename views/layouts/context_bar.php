<?php
declare(strict_types=1);
/** @var array<string,mixed>|null $cnContextBar */
if (empty($cnContextBar) || !is_array($cnContextBar)) {
    return;
}
$name = trim((string) ($cnContextBar['user_name'] ?? ''));
$seniorLine = trim((string) ($cnContextBar['senior_line'] ?? ''));
$points = array_key_exists('points', $cnContextBar) ? (int) $cnContextBar['points'] : null;
$pointsLabel = (string) ($cnContextBar['points_label'] ?? 'SilverPoints balance');
$roleLabel = (string) ($cnContextBar['role_label'] ?? 'Signed in');
?>
<div class="cn-context-bar cn-card cn-card-body mb-4 d-flex flex-wrap justify-content-between align-items-start gap-3">
    <div class="flex-grow-1" style="min-width:min(280px, 100%);">
        <?php if ($name !== ''): ?>
            <div class="cn-context-kicker"><?= e($roleLabel) ?></div>
            <div class="cn-context-title"><?= e($name) ?></div>
        <?php endif; ?>
        <?php if ($seniorLine !== ''): ?>
            <div class="cn-context-sub"><?= e($seniorLine) ?></div>
        <?php endif; ?>
    </div>
    <?php if ($points !== null): ?>
        <div class="cn-points-pill">
            <div class="cn-points-pill-value"><?= e(number_format($points)) ?></div>
            <div class="cn-points-pill-label"><?= e($pointsLabel) ?></div>
        </div>
    <?php endif; ?>
</div>
