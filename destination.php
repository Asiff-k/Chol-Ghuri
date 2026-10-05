<?php
/**
 * destination.php?id=1 - one destination: description, packages and forming groups.
 */
require_once __DIR__ . '/includes/bootstrap.php';

$id = int_param($_GET, 'id');
$stmt = db()->prepare('SELECT * FROM destinations WHERE id = ?');
$stmt->execute([$id]);
$destination = $stmt->fetch();
if (!$destination) {
    show_error_page('Destination not found', 'This destination does not exist.', 404, 'destinations.php', 'All destinations');
}

$stmt = db()->prepare('SELECT * FROM packages WHERE destination_id = ? AND is_active = 1 ORDER BY title');
$stmt->execute([$id]);
$packages = $stmt->fetchAll();

$groups = fetch_groups('p.destination_id = ? AND ' . open_groups_where(), [$id], 'g.start_date', 12);

$pageTitle = $destination['name'];
$activeNav = 'destinations.php';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <nav class="breadcrumb" aria-label="Breadcrumb">
        <a href="<?= url('destinations.php') ?>">Destinations</a> <?= icon('chevron-right', 'icon-sm') ?> <span><?= e($destination['name']) ?></span>
    </nav>

    <section class="dest-banner" style="background-image:url('<?= image_url($destination['image']) ?>')">
        <div>
            <?php if ($destination['label']): ?><span class="badge badge-glass"><?= e($destination['label']) ?></span><?php endif; ?>
            <h1><?= e($destination['name']) ?></h1>
            <p><?= icon('map-pin', 'icon-sm') ?> <?= e($destination['region']) ?>, Bangladesh</p>
        </div>
    </section>

    <p class="section-text" style="margin:24px 0 32px"><?= e($destination['description']) ?></p>

    <div class="section-head">
        <h2 class="mb-0">Packages</h2>
        <a class="link-accent" href="<?= url('groups.php?destination=' . $id) ?>">Find a group for <?= e($destination['name']) ?> <?= icon('arrow-right', 'icon-sm') ?></a>
    </div>
    <?php if ($packages): ?>
        <div class="card-grid-3">
            <?php foreach ($packages as $p): ?>
                <article class="package-card">
                    <a class="package-card-img" href="<?= url('package.php?id=' . $p['id']) ?>" style="background-image:url('<?= image_url($p['image']) ?>')"></a>
                    <div class="package-card-body">
                        <div class="package-card-tags">
                            <span class="tag tag-grey"><?= e(TRIP_TYPE_LABELS[$p['trip_type']]) ?></span>
                        </div>
                        <h3><a href="<?= url('package.php?id=' . $p['id']) ?>"><?= e($p['title']) ?></a></h3>
                        <div class="package-card-foot">
                            <div><small>Starting from</small><strong class="price-lg"><?= money(starting_price($p)) ?><small>/person</small></strong></div>
                            <a class="btn btn-sm btn-primary" href="<?= url('package.php?id=' . $p['id']) ?>">View</a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state">No packages for this destination yet.</div>
    <?php endif; ?>

    <h2 style="margin:40px 0 16px">Groups Forming</h2>
    <?php if ($groups): ?>
        <div class="card-grid-3">
            <?php foreach ($groups as $g): $pct = round(100 * $g['member_count'] / $g['max_members']); ?>
                <article class="group-card">
                    <div class="group-card-top">
                        <h3><a href="<?= url('group.php?id=' . $g['id']) ?>"><?= e($g['title']) ?></a></h3>
                        <span class="badge badge-grey"><?= e($g['organizer_uni'] ?? '') ?></span>
                    </div>
                    <p class="meta"><?= icon('calendar', 'icon-sm') ?> <?= e(date_range($g['start_date'], $g['end_date'])) ?></p>
                    <div class="progress-label"><span>Members</span><span class="text-accent"><?= (int) $g['member_count'] ?>/<?= (int) $g['max_members'] ?> joined</span></div>
                    <div class="progress"><span style="width:<?= $pct ?>%"></span></div>
                    <div class="group-card-foot">
                        <div><small>Current cost</small><strong><?= money(group_price_now($g)) ?></strong></div>
                        <a class="btn btn-sm btn-grey" href="<?= url('group.php?id=' . $g['id']) ?>">View Group</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state">No groups are forming here yet. <a href="<?= url('create-group.php') ?>">Start one!</a></div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
