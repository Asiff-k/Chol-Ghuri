<?php
/**
 * my-trips.php - all groups the student is in, grouped by stage, with the
 * next action for each one (new screen, same design system).
 */
require_once __DIR__ . '/includes/bootstrap.php';
$me = require_login();
if ($me['role'] !== 'student') {
    redirect('admin/index.php');
}

$sections = ['active' => [], 'forming' => [], 'past' => []];
foreach (user_groups((int) $me['id']) as $g) {
    if ($g['status'] === 'confirmed')                     $sections['active'][]  = $g;
    elseif (in_array($g['status'], ['forming', 'full']))  $sections['forming'][] = $g;
    else                                                  $sections['past'][]    = $g;
}
$titles = ['active' => 'Confirmed trips', 'forming' => 'Groups forming', 'past' => 'Past trips'];

/** The most useful next step for this trip. */
function next_step(array $g, array $me): array
{
    $id = (int) $g['id'];
    if ($g['status'] === 'confirmed') {
        $b = find_booking($id, (int) $me['id']);
        if (!$b || $b['status'] !== 'paid') return ['Pay booking', 'booking.php?group=' . $id, 'btn-accent'];
        return ['Expenses & settle', 'expenses.php?group=' . $id, 'btn-primary'];
    }
    if ($g['status'] === 'completed') return ['Rate members', 'rate.php?group=' . $id, 'btn-outline'];
    return ['Open group room', 'group.php?id=' . $id, 'btn-outline'];
}

$pageTitle = 'My Trips';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="page-head page-head-row">
        <div>
            <h1>My Trips</h1>
            <p>Every group you organise or joined, and what to do next.</p>
        </div>
        <a class="btn btn-accent" href="<?= url('create-group.php') ?>"><?= icon('plus', 'icon-sm') ?> Start a Trip</a>
    </div>

    <?= render_flash() ?>

    <?php if (!array_filter($sections)): ?>
        <div class="empty-state">
            <p><strong>You haven't joined any trips yet.</strong></p>
            <a class="btn btn-primary btn-sm" href="<?= url('groups.php') ?>">Find a group</a>
        </div>
    <?php endif; ?>

    <?php foreach ($sections as $key => $groups): if (!$groups) continue; ?>
        <h2 class="list-title"><?= $titles[$key] ?> <span class="badge badge-grey"><?= count($groups) ?></span></h2>
        <div class="trip-list">
            <?php foreach ($groups as $g): [$label, $link, $class] = next_step($g, $me); ?>
                <article class="trip-row">
                    <div class="trip-row-img" style="background-image:url('<?= image_url($g['package_image']) ?>')"></div>
                    <div class="trip-row-body">
                        <div class="trip-row-top">
                            <h3><a href="<?= url('group.php?id=' . $g['id']) ?>"><?= e($g['title']) ?></a></h3>
                            <?= status_badge($g['status']) ?>
                            <?php if ((int) $g['creator_id'] === (int) $me['id']): ?><span class="badge badge-grey"><?= icon('crown', 'icon-sm') ?> Organiser</span><?php endif; ?>
                        </div>
                        <p class="meta">
                            <span><?= icon('map-pin', 'icon-sm') ?> <?= e($g['destination_name']) ?></span>
                            <span><?= icon('calendar', 'icon-sm') ?> <?= e(date_range($g['start_date'], $g['end_date'])) ?></span>
                            <span><?= icon('users', 'icon-sm') ?> <?= (int) $g['member_count'] ?>/<?= (int) $g['max_members'] ?> (min <?= (int) $g['min_members'] ?>)</span>
                            <span><?= icon('banknote', 'icon-sm') ?> <?= money(group_price_now($g)) ?>/person</span>
                        </p>
                    </div>
                    <a class="btn btn-sm <?= $class ?>" href="<?= url($link) ?>"><?= e($label) ?></a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
