<?php
/**
 * package.php?id=1 - Package details (design: "Hotel Opera Ocean - Details")
 *
 * Shows the package, its cost items, the dynamic price per group size
 * and the groups currently forming for it.
 */
require_once __DIR__ . '/includes/bootstrap.php';

$id = int_param($_GET, 'id');
$stmt = db()->prepare(
    'SELECT p.*, d.name AS destination_name, d.id AS dest_id FROM packages p JOIN destinations d ON d.id = p.destination_id
     WHERE p.id = ? AND (p.is_active = 1 OR ? = 1)'
);
$stmt->execute([$id, is_admin() ? 1 : 0]);
$package = $stmt->fetch();
if (!$package) {
    show_error_page('Package not found', 'This package does not exist or is no longer available.', 404, 'packages.php', 'Browse packages');
}

$costs    = package_cost_items($id);
$totals   = package_cost_totals($id);
$timeline = price_timeline($id, (int) $package['min_group_size'], (int) $package['max_group_size']);
$highest  = max($timeline);
$lowest   = min($timeline);
$saving   = $highest > 0 ? round(100 * ($highest - $lowest) / $highest) : 0;
$groups   = fetch_groups('g.package_id = ? AND ' . open_groups_where(), [$id], 'g.start_date', 6);
$amenities = array_filter(explode(',', (string) $package['amenities']));

$pageTitle = $package['title'];
$activeNav = 'destinations.php';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <nav class="breadcrumb" aria-label="Breadcrumb">
        <a href="<?= url('destinations.php') ?>">Destinations</a> <?= icon('chevron-right', 'icon-sm') ?>
        <a href="<?= url('destination.php?id=' . $package['dest_id']) ?>"><?= e($package['destination_name']) ?></a> <?= icon('chevron-right', 'icon-sm') ?>
        <span><?= e($package['title']) ?></span>
    </nav>

    <?php if (!$package['is_active']): ?><?= alert('warning', 'This package is inactive (only admins can see it).') ?><?php endif; ?>

    <div class="detail-hero" style="background-image:url('<?= image_url($package['image']) ?>')" role="img" aria-label="<?= e($package['title']) ?>"></div>

    <div class="layout-sidebar">
        <div>
            <section class="detail-head">
                <div class="chips">
                    <span><?= e(TRIP_TYPE_LABELS[$package['trip_type']]) ?></span>
                </div>
                <h1 class="detail-title"><?= e($package['title']) ?></h1>
                <p class="meta"><?= icon('map-pin', 'icon-sm text-primary') ?> <?= e($package['location'] ?: $package['destination_name']) ?>, Bangladesh</p>
            </section>

            <section class="detail-section">
                <h2>About the Trip</h2>
                <p><?= nl2br(e($package['description'])) ?></p>
                <div class="pref-grid">
                    <div class="pref-item"><?= icon('calendar') ?><div><strong>Duration</strong><span><?= (int) $package['duration_days'] ?> <?= (int) $package['duration_days'] === 1 ? 'day' : 'days' ?></span></div></div>
                    <div class="pref-item"><?= icon('users') ?><div><strong>Group size</strong><span><?= (int) $package['min_group_size'] ?>–<?= (int) $package['max_group_size'] ?> students</span></div></div>
                    <div class="pref-item"><?= icon('bed-double') ?><div><strong>Accommodation</strong><span><?= e($package['accommodation_name'] ?: 'No overnight stay (day trip)') ?></span></div></div>
                    <div class="pref-item"><?= icon('bus') ?><div><strong>Transport</strong><span><?= e($package['transport_info'] ?: 'Arrange your own') ?></span></div></div>
                </div>
            </section>

            <?php if ($amenities): ?>
                <section class="detail-section">
                    <h2>Amenities</h2>
                    <div class="amenities">
                        <?php foreach ($amenities as $a): $info = AMENITY_LABELS[$a] ?? ['label' => ucfirst($a), 'icon' => 'check']; ?>
                            <span><?= icon($info['icon']) ?> <?= e($info['label']) ?></span>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="detail-section">
                <h2>What the Price Includes</h2>
                <p>Shared costs are split between all members, so they get cheaper as the group grows. Per-person costs are paid by everyone in full.</p>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Item</th><th>Type</th><th class="num">Amount</th></tr></thead>
                        <tbody>
                        <?php foreach ($costs as $c): ?>
                            <tr>
                                <td><?= icon(CATEGORY_LABELS[$c['category']]['icon'], 'icon-sm text-primary') ?> <?= e($c['label']) ?></td>
                                <td><?= $c['cost_type'] === 'shared' ? '<span class="tag tag-green">Shared</span>' : '<span class="tag tag-grey">Per person</span>' ?></td>
                                <td class="num"><?= money($c['amount']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr><td colspan="2">Shared total (split by members)</td><td class="num"><?= money($totals['shared']) ?></td></tr>
                            <tr><td colspan="2">Per-person total</td><td class="num"><?= money($totals['per_person']) ?></td></tr>
                        </tfoot>
                    </table>
                </div>
                <p class="formula"><?= icon('info', 'icon-sm') ?> Price per person = <?= money($totals['shared']) ?> &divide; members + <?= money($totals['per_person']) ?></p>
            </section>
        </div>

        <aside>
            <section class="card">
                <h2 class="card-title">Dynamic Pricing per Person</h2>
                <div class="price-bars" role="img" aria-label="Price per person by group size">
                    <?php foreach ($timeline as $n => $price): ?>
                        <div class="price-bar">
                            <small><?= money($price) ?></small>
                            <span style="height:<?= max(12, round(100 * $price / $highest)) ?>%"></span>
                            <em><?= $n ?> pax</em>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($saving > 0): ?>
                    <div class="saving-note">Save up to <?= $saving ?>% by grouping up!</div>
                <?php endif; ?>
            </section>

            <section class="card">
                <h2 class="card-title"><?= icon('user-plus', 'text-accent') ?> Join an Active Group</h2>
                <?php if ($groups): ?>
                    <?php foreach ($groups as $g): ?>
                        <div class="mini-group">
                            <div class="mini-group-top">
                                <div>
                                    <strong><?= e($g['title']) ?></strong>
                                    <small><?= e(date_range($g['start_date'], $g['end_date'])) ?> &middot; <?= (int) $g['member_count'] ?>/<?= (int) $g['max_members'] ?> students</small>
                                </div>
                                <div class="text-right"><strong class="text-primary"><?= money(group_price_now($g)) ?></strong><small>/person now</small></div>
                            </div>
                            <div class="progress"><span style="width:<?= round(100 * $g['member_count'] / $g['max_members']) ?>%"></span></div>
                            <a class="btn btn-outline btn-sm btn-block" href="<?= url('group.php?id=' . $g['id']) ?>">View &amp; Join</a>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-muted">No groups are forming for this package yet.</p>
                <?php endif; ?>
                <div class="or-divider"><span>or</span></div>
                <a class="btn btn-primary btn-block" href="<?= url('create-group.php?package=' . $package['id']) ?>"><?= icon('circle-plus', 'icon-sm') ?> Start New Group</a>
                <p class="text-small text-center" style="margin-top:10px">From <?= money($timeline[(int) $package['min_group_size']]) ?>/person with <?= (int) $package['min_group_size'] ?> people, dropping to <?= money($lowest) ?> as others join.</p>
            </section>
        </aside>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
