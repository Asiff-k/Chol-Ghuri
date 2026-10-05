<?php
/**
 * destinations.php - all destinations with their package and group counts.
 */
require_once __DIR__ . '/includes/bootstrap.php';

$destinations = db()->query(
    "SELECT d.*,
            (SELECT COUNT(*) FROM packages p WHERE p.destination_id = d.id AND p.is_active = 1) AS package_count,
            (SELECT COUNT(*) FROM travel_groups g JOIN packages p ON p.id = g.package_id
              WHERE p.destination_id = d.id AND " . open_groups_where() . ") AS group_count
     FROM destinations d ORDER BY d.is_featured DESC, d.name"
)->fetchAll();

$pageTitle = 'Destinations';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="page-head">
        <h1>Destinations</h1>
        <p>From hill tracks to coral islands. Pick a place, see its packages and the student groups going there.</p>
    </div>

    <div class="card-grid-3">
        <?php foreach ($destinations as $d): ?>
            <a class="dest-card" href="<?= url('destination.php?id=' . $d['id']) ?>">
                <div class="dest-card-img" style="background-image:url('<?= image_url($d['image']) ?>')">
                    <?php if ($d['label']): ?><span class="badge badge-glass"><?= e($d['label']) ?></span><?php endif; ?>
                </div>
                <div class="dest-card-body">
                    <h3><?= e($d['name']) ?></h3>
                    <p class="meta"><?= icon('map-pin', 'icon-sm') ?> <?= e($d['region']) ?></p>
                    <p class="text-small"><?= e($d['description']) ?></p>
                    <div class="dest-card-stats">
                        <span><?= icon('hotel', 'icon-sm') ?> <?= (int) $d['package_count'] ?> <?= (int) $d['package_count'] === 1 ? 'package' : 'packages' ?></span>
                        <span class="<?= $d['group_count'] ? 'text-accent' : '' ?>"><?= icon('users', 'icon-sm') ?> <?= (int) $d['group_count'] ?> forming</span>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
