<?php
/**
 * rate.php?group=2 - Rate your trip members (new screen, same design system)
 *
 * Only members of a COMPLETED group can rate the other members of that group,
 * once per person per trip (the database has a UNIQUE key for this too).
 */
require_once __DIR__ . '/includes/bootstrap.php';
$me = require_login();

$groupId = int_param($_GET, 'group');
$group   = get_group($groupId);
if (!$group || !is_group_member($groupId, (int) $me['id'])) {
    show_error_page('Not available', 'You can only rate members of trips you were part of.', 403, 'my-trips.php', 'My Trips');
}
if ($group['status'] !== 'completed') {
    show_error_page('Not yet', 'Ratings open after the organiser marks the trip as completed.', 403, 'group.php?id=' . $groupId, 'Back to group');
}

$members = array_filter(group_members_list($groupId), fn($m) => (int) $m['id'] !== (int) $me['id']);
$memberIds = array_map('intval', array_column($members, 'id'));

if (is_post()) {
    verify_csrf();
    $ratedId = int_param($_POST, 'rated_user_id');
    $score   = int_param($_POST, 'score');
    $comment = trim($_POST['comment'] ?? '');

    if (!in_array($ratedId, $memberIds, true)) {
        $error = 'You can only rate other members of this trip.';
    } elseif ($score < 1 || $score > 5) {
        $error = 'Choose between 1 and 5 stars.';
    } elseif (mb_strlen($comment) > 255) {
        $error = 'Please keep the review under 255 characters.';
    } else {
        try {
            db()->prepare('INSERT INTO ratings (group_id, rater_id, rated_user_id, score, comment) VALUES (?, ?, ?, ?, ?)')
                ->execute([$groupId, $me['id'], $ratedId, $score, $comment ?: null]);
            $error = '';
        } catch (PDOException $ex) {
            if ($ex->errorInfo[1] !== 1062) throw $ex;   // 1062 = duplicate key
            $error = 'You already rated this member for this trip.';
        }
    }
    set_flash($error ? 'error' : 'success', $error ?: 'Thanks! Your rating was saved.');
    redirect('rate.php?group=' . $groupId);
}

// Ratings I already gave in this trip
$stmt = db()->prepare('SELECT rated_user_id, score, comment FROM ratings WHERE group_id = ? AND rater_id = ?');
$stmt->execute([$groupId, $me['id']]);
$given = [];
foreach ($stmt->fetchAll() as $r) {
    $given[(int) $r['rated_user_id']] = $r;
}

$pageTitle = 'Rate Your Group';
$activeNav = 'my-trips.php';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container container-narrow">
    <a class="back-link" href="<?= url('group.php?id=' . $groupId) ?>"><?= icon('arrow-left', 'icon-sm') ?> Back to Group</a>
    <div class="page-head">
        <h1>Rate Your Group</h1>
        <p><?= e($group['title']) ?> &middot; <?= e(date_range($group['start_date'], $group['end_date'])) ?>. Honest ratings help other students choose who to travel with.</p>
    </div>

    <?= render_flash() ?>

    <?php if (!$members): ?>
        <div class="empty-state">There is nobody else to rate in this trip.</div>
    <?php endif; ?>

    <?php foreach ($members as $m): $done = $given[(int) $m['id']] ?? null; ?>
        <section class="card rate-card">
            <div class="rate-head">
                <?= avatar($m['profile_photo'], $m['full_name'], 'md') ?>
                <div>
                    <strong><a href="<?= url('profile.php?id=' . $m['id']) ?>"><?= e($m['full_name']) ?></a></strong>
                    <small><?= e($m['uni'] ?? '') ?><?= $m['role'] === 'organizer' ? ' &middot; Organizer' : '' ?></small>
                </div>
                <?php if ($done): ?><span class="badge"><?= icon('check', 'icon-sm') ?> Rated</span><?php endif; ?>
            </div>

            <?php if ($done): ?>
                <p class="mb-0"><?= stars((float) $done['score']) ?> <?= $done['comment'] ? '&ldquo;' . e($done['comment']) . '&rdquo;' : '<span class="text-muted">No written review</span>' ?></p>
            <?php else: ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="rated_user_id" value="<?= $m['id'] ?>">
                    <div class="star-input" role="radiogroup" aria-label="Rating for <?= e($m['full_name']) ?>">
                        <?php for ($s = 5; $s >= 1; $s--): ?>
                            <input type="radio" id="s<?= $m['id'] ?>-<?= $s ?>" name="score" value="<?= $s ?>" required>
                            <label for="s<?= $m['id'] ?>-<?= $s ?>" title="<?= $s ?> stars"><?= icon('star') ?></label>
                        <?php endfor; ?>
                    </div>
                    <input class="input" name="comment" maxlength="255" placeholder="Optional short review, e.g. Always on time and fun to travel with.">
                    <div class="form-actions"><button class="btn btn-primary btn-sm" type="submit">Submit Rating</button></div>
                </form>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
