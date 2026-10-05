<?php
/**
 * packages.php - Package catalogue / search results (design: "Search Results - Bandarban")
 *
 * GET filters: destination, start (date), size, type[] (trip types),
 *              max_price, sort
 * Prices are calculated with per_person_price(), never typed by hand.
 */
require_once __DIR__ . '/includes/bootstrap.php';

$destinationId = int_param($_GET, 'destination');
$start   = valid_date($_GET['start'] ?? '') ? $_GET['start'] : '';
$size    = min(12, max(0, int_param($_GET, 'size')));
$types   = array_values(array_intersect((array) ($_GET['type'] ?? []), ['day_trip', 'overnight', 'eco_tour']));
$maxPrice  = int_param($_GET, 'max_price');
$sort    = in_array($_GET['sort'] ?? '', ['price', 'groups'], true) ? $_GET['sort'] : 'groups';

$destination = null;
if ($destinationId) {
    $stmt = db()->prepare('SELECT * FROM destinations WHERE id = ?');
    $stmt->execute([$destinationId]);
    $destination = $stmt->fetch() ?: null;
}

// ---- Load packages with the number of groups currently forming ----
$where  = ['p.is_active = 1'];
$params = [];
if ($destination) {
    $where[] = 'p.destination_id = ?';
    $params[] = $destination['id'];
}
if ($types) {
    $where[] = 'p.trip_type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
    array_push($params, ...$types);
}
$stmt = db()->prepare(
    "SELECT p.*, d.name AS destination_name,
            (SELECT COUNT(*) FROM travel_groups g WHERE g.package_id = p.id AND " . open_groups_where() . ") AS forming
     FROM packages p JOIN destinations d ON d.id = p.destination_id
     WHERE " . implode(' AND ', $where)
);
$stmt->execute($params);
$packages = $stmt->fetchAll();

// Price for the chosen group size (or the lowest possible price)
foreach ($packages as $k => &$p) {
    $p['from_price'] = starting_price($p);
    $p['size_price'] = $size >= 1 ? per_person_price((int) $p['id'], max($size, 1)) : null;
    if ($maxPrice > 0 && ($p['size_price'] ?? $p['from_price']) > $maxPrice) {
        unset($packages[$k]);
    }
}
unset($p);

usort($packages, match ($sort) {
    'price'  => fn($a, $b) => ($a['size_price'] ?? $a['from_price']) <=> ($b['size_price'] ?? $b['from_price']),
    default  => fn($a, $b) => [$b['forming'], $a['from_price']] <=> [$a['forming'], $b['from_price']],
});

$destinations = db()->query('SELECT id, name FROM destinations ORDER BY name')->fetchAll();

/** Link to the matching page with the search carried over. */
function match_link(array $package, string $start, int $size): string
{
    $q = ['destination' => $package['destination_id']];
    if ($start) $q['start'] = $start;
    if ($size)  $q['size'] = $size;
    return url('groups.php?' . http_build_query($q));
}

$pageTitle = $destination ? 'Explore ' . $destination['name'] : 'Travel Packages';
$activeNav = 'index.php';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="page-head">
        <h1><?= e($destination ? 'Explore ' . $destination['name'] : 'Explore Packages') ?></h1>
        <div class="chips">
            <span><?= icon('map-pin', 'icon-sm') ?> <?= e($destination['name'] ?? 'All destinations') ?></span>
            <?php if ($start): ?><span><?= icon('calendar', 'icon-sm') ?> From <?= date('j M Y', strtotime($start)) ?></span><?php endif; ?>
            <?php if ($size): ?><span><?= icon('users', 'icon-sm') ?> <?= $size ?> Students</span><?php endif; ?>
            <span><?= count($packages) ?> <?= count($packages) === 1 ? 'package' : 'packages' ?></span>
        </div>
    </div>

    <div class="layout-filters">
        <form class="card filters" method="get">
            <h2 class="filters-title">Filters</h2>
            <p class="text-small">Narrow your search</p>
            <?php if ($start): ?><input type="hidden" name="start" value="<?= e($start) ?>"><?php endif; ?>

            <div class="form-group">
                <label for="f-destination"><?= icon('map-pin', 'icon-sm') ?> Destination</label>
                <select class="input" id="f-destination" name="destination">
                    <option value="">All destinations</option>
                    <?php foreach ($destinations as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= $destinationId === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <fieldset class="form-group">
                <legend class="label"><?= icon('tent', 'icon-sm') ?> Trip Types</legend>
                <?php foreach (['overnight', 'day_trip', 'eco_tour'] as $t): ?>
                    <label class="check"><input type="checkbox" name="type[]" value="<?= $t ?>" <?= in_array($t, $types, true) ? 'checked' : '' ?>> <span><?= TRIP_TYPE_LABELS[$t] ?></span></label>
                <?php endforeach; ?>
            </fieldset>

            <div class="form-group">
                <label for="f-size"><?= icon('users', 'icon-sm') ?> Group size</label>
                <select class="input" id="f-size" name="size">
                    <option value="">Any (show lowest price)</option>
                    <?php foreach ([2, 3, 4, 5, 6, 8, 10, 12] as $n): ?>
                        <option value="<?= $n ?>" <?= $size === $n ? 'selected' : '' ?>><?= $n ?> students</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="f-price"><?= icon('banknote', 'icon-sm') ?> Max price per person</label>
                <select class="input" id="f-price" name="max_price">
                    <option value="">Any price</option>
                    <?php foreach ([2000, 3000, 5000, 8000, 12000] as $amount): ?>
                        <option value="<?= $amount ?>" <?= $maxPrice === $amount ? 'selected' : '' ?>>Up to <?= money($amount) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="f-sort">Sort by</label>
                <select class="input" id="f-sort" name="sort">
                    <option value="groups" <?= $sort === 'groups' ? 'selected' : '' ?>>Most groups forming</option>
                    <option value="price" <?= $sort === 'price' ? 'selected' : '' ?>>Lowest price</option>
                </select>
            </div>

            <button class="btn btn-primary btn-block" type="submit">Apply filters</button>
            <a class="btn btn-grey btn-block" style="margin-top:10px" href="<?= url('packages.php') ?>">Clear All</a>
        </form>

        <div>
            <?php if ($packages): ?>
                <div class="card-grid-3">
                    <?php foreach ($packages as $p): ?>
                        <article class="package-card">
                            <a class="package-card-img" href="<?= url('package.php?id=' . $p['id']) ?>" style="background-image:url('<?= image_url($p['image']) ?>')">
                                <span class="pill-float"><?= icon($p['forming'] ? 'users' : 'plus', 'icon-sm') ?>
                                    <?= $p['forming'] ? (int) $p['forming'] . ' ' . ((int) $p['forming'] === 1 ? 'Group' : 'Groups') . ' forming' : 'Be the first group' ?></span>
                            </a>
                            <div class="package-card-body">
                                <div class="package-card-tags">
                                    <span class="tag tag-grey"><?= e(TRIP_TYPE_LABELS[$p['trip_type']]) ?></span>
                                </div>
                                <h3><a href="<?= url('package.php?id=' . $p['id']) ?>"><?= e($p['title']) ?></a></h3>
                                <p class="meta"><?= icon('map-pin', 'icon-sm') ?> <?= e($p['destination_name']) ?> &middot; <?= (int) $p['duration_days'] ?> <?= (int) $p['duration_days'] === 1 ? 'day' : 'days' ?></p>
                                <div class="package-card-foot">
                                    <div>
                                        <?php if ($p['size_price'] !== null): ?>
                                            <small>For <?= $size ?> students</small>
                                            <strong class="price-lg"><?= money($p['size_price']) ?><small>/person</small></strong>
                                        <?php else: ?>
                                            <small>Starting from</small>
                                            <strong class="price-lg"><?= money($p['from_price']) ?><small>/person</small></strong>
                                        <?php endif; ?>
                                    </div>
                                    <a class="btn btn-sm <?= $p['forming'] ? 'btn-accent' : 'btn-primary' ?>" href="<?= match_link($p, $start, $size) ?>">Match</a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <p><strong>No packages match these filters.</strong></p>
                    <a class="btn btn-primary btn-sm" href="<?= url('packages.php') ?>">Clear filters</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
