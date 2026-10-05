<?php
/**
 * admin/packages.php - list packages, create / edit a package and its cost items.
 *   packages.php            -> list
 *   packages.php?edit=3     -> edit package 3
 *   packages.php?edit=new   -> new package
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin.php';
require_admin();

$destinations = db()->query('SELECT id, name FROM destinations ORDER BY name')->fetchAll();
$editId = $_GET['edit'] ?? null;
$errors = [];
$package = null;
$costs = [];

if ($editId !== null && $editId !== 'new') {
    $stmt = db()->prepare('SELECT * FROM packages WHERE id = ?');
    $stmt->execute([(int) $editId]);
    $package = $stmt->fetch();
    if (!$package) {
        show_error_page('Package not found', 'This package does not exist.', 404, 'admin/packages.php', 'All packages');
    }
    $costs = package_cost_items((int) $package['id']);
}

if (is_post()) {
    verify_csrf();
    $f = [
        'destination_id'     => int_param($_POST, 'destination_id'),
        'title'              => trim($_POST['title'] ?? ''),
        'trip_type'          => $_POST['trip_type'] ?? '',
        'accommodation_type' => $_POST['accommodation_type'] ?? '',
        'accommodation_name' => trim($_POST['accommodation_name'] ?? ''),
        'transport_info'     => trim($_POST['transport_info'] ?? ''),
        'location'           => trim($_POST['location'] ?? ''),
        'description'        => trim($_POST['description'] ?? ''),
        'duration_days'      => int_param($_POST, 'duration_days'),
        'min_group_size'     => int_param($_POST, 'min_group_size'),
        'max_group_size'     => int_param($_POST, 'max_group_size'),
        'amenities'          => implode(',', array_intersect((array) ($_POST['amenities'] ?? []), array_keys(AMENITY_LABELS))),
        'image'              => basename($_POST['image'] ?? ''),
        'is_active'          => empty($_POST['is_active']) ? 0 : 1,
    ];

    if (!in_array($f['destination_id'], array_map('intval', array_column($destinations, 'id')), true)) $errors[] = 'Choose a destination.';
    if (mb_strlen($f['title']) < 3) $errors[] = 'Enter a title.';
    if (!isset(TRIP_TYPE_LABELS[$f['trip_type']]) || $f['trip_type'] === 'any') $errors[] = 'Choose a trip type.';
    if (!in_array($f['accommodation_type'], ['shared', 'private', 'none'], true)) $errors[] = 'Choose an accommodation type.';
    if ($f['duration_days'] < 1 || $f['duration_days'] > 30) $errors[] = 'Duration must be 1–30 days.';
    if ($f['min_group_size'] < 1 || $f['max_group_size'] > 30 || $f['min_group_size'] > $f['max_group_size']) $errors[] = 'Group size: minimum must be at least 1 and not more than the maximum (max 30).';

    // Cost items: rows of label / category / cost_type / amount (empty rows ignored)
    $items = [];
    foreach ((array) ($_POST['cost'] ?? []) as $row) {
        $label = trim($row['label'] ?? '');
        $amount = trim($row['amount'] ?? '');
        if ($label === '' && $amount === '') continue;
        if (!empty($row['delete'])) continue;
        if ($label === '' || !preg_match('/^\d{1,7}$/', $amount) || !isset(CATEGORY_LABELS[$row['category'] ?? ''])
            || !in_array($row['cost_type'] ?? '', ['shared', 'per_person'], true)) {
            $errors[] = 'Every cost item needs a label, a category, a type and a whole-taka amount.';
            break;
        }
        $items[] = [$label, $row['category'], $row['cost_type'], (int) $amount];
    }
    if (!$items) $errors[] = 'Add at least one cost item (otherwise the price would be ৳0).';

    [$uploadError, $uploaded] = save_uploaded_image($_FILES['image_upload'] ?? null);
    if ($uploadError) $errors[] = $uploadError;
    if ($uploaded) $f['image'] = $uploaded;

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        $cols = array_keys($f);
        if ($package) {
            $set = implode(', ', array_map(fn($c) => "$c = ?", $cols));
            $pdo->prepare("UPDATE packages SET $set WHERE id = ?")->execute([...array_values($f), $package['id']]);
            $packageId = (int) $package['id'];
        } else {
            $pdo->prepare('INSERT INTO packages (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
                ->execute(array_values($f));
            $packageId = (int) $pdo->lastInsertId();
        }
        $pdo->prepare('DELETE FROM package_costs WHERE package_id = ?')->execute([$packageId]);
        $insert = $pdo->prepare('INSERT INTO package_costs (package_id, label, category, cost_type, amount) VALUES (?, ?, ?, ?, ?)');
        foreach ($items as $item) {
            $insert->execute([$packageId, ...$item]);
        }
        $pdo->commit();
        set_flash('success', 'Package saved. Prices everywhere now use the new cost items.');
        redirect('admin/packages.php');
    }
    $package = array_merge($package ?? [], $f);
    $costs = array_map(fn($i) => ['label' => $i[0], 'category' => $i[1], 'cost_type' => $i[2], 'amount' => $i[3]], $items);
}

$list = db()->query(
    "SELECT p.*, d.name AS destination_name,
            (SELECT COUNT(*) FROM travel_groups g WHERE g.package_id = p.id AND g.status IN ('forming','full','confirmed')) AS active_groups
     FROM packages p JOIN destinations d ON d.id = p.destination_id ORDER BY d.name, p.title"
)->fetchAll();

$pageTitle = 'Admin: Packages';
$activeNav = 'admin/index.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <div class="page-head page-head-row">
        <div><h1>Packages &amp; Costs</h1><p>Prices are calculated from these cost items: shared &divide; members + per person.</p></div>
        <a class="btn btn-primary" href="<?= url('admin/packages.php?edit=new') ?>"><?= icon('plus', 'icon-sm') ?> New Package</a>
    </div>
    <?= admin_nav('packages.php') ?>
    <?= render_flash() ?>

<?php if ($editId !== null): $p = $package ?? []; ?>
    <?php if ($errors): ?><div class="alert alert-error"><?= icon('circle-alert') ?><span><?= implode('<br>', array_map('e', $errors)) ?></span></div><?php endif; ?>
    <form class="card" method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <h2 class="card-section-title"><?= $package && isset($package['id']) ? 'Edit: ' . e($package['title']) : 'New package' ?></h2>
        <?php if (!empty($p['id'])): ?><?= alert('warning', 'Changing cost items changes the live price of every group using this package (already-paid bookings keep their recorded amount).') ?><?php endif; ?>
        <div class="form-row">
            <div class="form-group"><label for="title">Title</label><input class="input" id="title" name="title" value="<?= e($p['title'] ?? '') ?>" required></div>
            <div class="form-group"><label for="destination_id">Destination</label>
                <select class="input" id="destination_id" name="destination_id"><?php foreach ($destinations as $d): ?><option value="<?= $d['id'] ?>" <?= (int) ($p['destination_id'] ?? 0) === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-row form-row-3">
            <div class="form-group"><label for="trip_type">Trip type</label>
                <select class="input" id="trip_type" name="trip_type"><?php foreach (['overnight', 'day_trip', 'eco_tour'] as $t): ?><option value="<?= $t ?>" <?= ($p['trip_type'] ?? '') === $t ? 'selected' : '' ?>><?= TRIP_TYPE_LABELS[$t] ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label for="accommodation_type">Accommodation</label>
                <select class="input" id="accommodation_type" name="accommodation_type"><?php foreach (['shared' => 'Shared rooms', 'private' => 'Private rooms', 'none' => 'None (day trip)'] as $k => $l): ?><option value="<?= $k ?>" <?= ($p['accommodation_type'] ?? 'shared') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label for="duration_days">Duration (days)</label><input class="input" type="number" id="duration_days" name="duration_days" min="1" max="30" value="<?= e((string) ($p['duration_days'] ?? 2)) ?>"></div>
            <div class="form-group"><label for="min_group_size">Min group size</label><input class="input" type="number" id="min_group_size" name="min_group_size" min="1" max="30" value="<?= e((string) ($p['min_group_size'] ?? 2)) ?>"></div>
            <div class="form-group"><label for="max_group_size">Max group size (capacity)</label><input class="input" type="number" id="max_group_size" name="max_group_size" min="1" max="30" value="<?= e((string) ($p['max_group_size'] ?? 8)) ?>"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label for="accommodation_name">Accommodation name</label><input class="input" id="accommodation_name" name="accommodation_name" value="<?= e($p['accommodation_name'] ?? '') ?>"></div>
            <div class="form-group"><label for="transport_info">Transport</label><input class="input" id="transport_info" name="transport_info" value="<?= e($p['transport_info'] ?? '') ?>"></div>
            <div class="form-group"><label for="location">Location</label><input class="input" id="location" name="location" value="<?= e($p['location'] ?? '') ?>"></div>
            <div class="form-group"><label for="image">Photo</label>
                <select class="input" id="image" name="image"><?php foreach (builtin_images() as $img): ?><option value="<?= e($img) ?>" <?= ($p['image'] ?? '') === $img ? 'selected' : '' ?>><?= e($img) ?></option><?php endforeach; ?></select>
                <input class="input" style="margin-top:8px" type="file" name="image_upload" accept="image/jpeg,image/png" aria-label="Upload a new photo"></div>
        </div>
        <div class="form-group"><label for="description">Description</label><textarea class="input" id="description" name="description" rows="3"><?= e($p['description'] ?? '') ?></textarea></div>
        <fieldset class="form-group"><legend class="label">Amenities</legend>
            <div class="tag-picker tag-picker-sm"><?php $am = explode(',', (string) ($p['amenities'] ?? '')); foreach (AMENITY_LABELS as $k => $a): ?>
                <label class="tag-option"><input type="checkbox" name="amenities[]" value="<?= $k ?>" <?= in_array($k, $am, true) ? 'checked' : '' ?>><span><?= e($a['label']) ?></span></label><?php endforeach; ?></div>
        </fieldset>
        <label class="check form-group"><input type="checkbox" name="is_active" value="1" <?= ($p['is_active'] ?? 1) ? 'checked' : '' ?>> <span>Active (visible to students)</span></label>

        <h3>Cost items</h3>
        <div class="table-wrap">
            <table class="table cost-edit">
                <thead><tr><th>Label</th><th>Category</th><th>Type</th><th>Amount (৳)</th><th>Remove</th></tr></thead>
                <tbody>
                <?php $rows = array_merge($costs, array_fill(0, 3, ['label' => '', 'category' => 'transport', 'cost_type' => 'shared', 'amount' => ''])); ?>
                <?php foreach ($rows as $i => $c): ?>
                    <tr>
                        <td><input class="input" name="cost[<?= $i ?>][label]" value="<?= e($c['label']) ?>" placeholder="e.g. Jeep hire"></td>
                        <td><select class="input" name="cost[<?= $i ?>][category]"><?php foreach (CATEGORY_LABELS as $k => $cat): ?><option value="<?= $k ?>" <?= $c['category'] === $k ? 'selected' : '' ?>><?= $cat['label'] ?></option><?php endforeach; ?></select></td>
                        <td><select class="input" name="cost[<?= $i ?>][cost_type]"><option value="shared" <?= $c['cost_type'] === 'shared' ? 'selected' : '' ?>>Shared</option><option value="per_person" <?= $c['cost_type'] === 'per_person' ? 'selected' : '' ?>>Per person</option></select></td>
                        <td><input class="input" type="number" min="0" step="1" name="cost[<?= $i ?>][amount]" value="<?= e((string) ($c['amount'] === '' ? '' : (int) $c['amount'])) ?>"></td>
                        <td><?php if ($c['label'] !== ''): ?><input type="checkbox" name="cost[<?= $i ?>][delete]" value="1" aria-label="Remove"><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="form-actions"><a class="btn btn-light" href="<?= url('admin/packages.php') ?>">Cancel</a><button class="btn btn-primary" type="submit">Save Package</button></div>
    </form>
<?php else: ?>
    <section class="card">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Package</th><th>Destination</th><th>Type</th><th>Price range / person</th><th>Active groups</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($list as $p): ?>
                    <tr>
                        <td><a href="<?= url('package.php?id=' . $p['id']) ?>"><?= e($p['title']) ?></a></td>
                        <td><?= e($p['destination_name']) ?></td>
                        <td><?= e(TRIP_TYPE_LABELS[$p['trip_type']]) ?></td>
                        <td><?= money(starting_price($p)) ?> – <?= money(per_person_price((int) $p['id'], (int) $p['min_group_size'])) ?></td>
                        <td><?= (int) $p['active_groups'] ?></td>
                        <td><?= $p['is_active'] ? '<span class="badge">Active</span>' : '<span class="badge badge-grey">Inactive</span>' ?></td>
                        <td><a href="<?= url('admin/packages.php?edit=' . $p['id']) ?>">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
