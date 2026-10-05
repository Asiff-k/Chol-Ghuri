<?php
/**
 * create-group.php - Start a Group (design: "Start a New Group")
 *
 * The organiser chooses a package, dates, group size, minimum headcount,
 * budget, cost-sharing method, rules and style tags. The group is saved
 * and the organiser automatically becomes its first member.
 */
require_once __DIR__ . '/includes/bootstrap.php';
$me = require_login();
if ($me['role'] !== 'student') {
    show_error_page('Students only', 'Only student accounts can start groups.', 403, 'admin/index.php', 'Admin panel');
}

$destinations = db()->query('SELECT id, name FROM destinations ORDER BY name')->fetchAll();
$packages     = db()->query(
    'SELECT p.*, d.name AS destination_name FROM packages p JOIN destinations d ON d.id = p.destination_id
     WHERE p.is_active = 1 ORDER BY d.name, p.title'
)->fetchAll();
$packagesById = array_column($packages, null, 'id');
$allTags      = all_tags();
$errors       = [];

// Pre-fill from the URL (e.g. coming from a package page or a zero-match search)
$prePackage = $packagesById[int_param($_GET, 'package')] ?? null;
$form = [
    'destination_id'  => $prePackage['destination_id'] ?? int_param($_GET, 'destination'),
    'package_id'      => $prePackage['id'] ?? 0,
    'title'           => '',
    'start_date'      => valid_date($_GET['start'] ?? '') ? $_GET['start'] : '',
    'end_date'        => valid_date($_GET['end'] ?? '') ? $_GET['end'] : '',
    'max_members'     => 6,
    'min_members'     => 3,
    'budget_max'      => int_param($_GET, 'budget') ?: ($me['budget_max'] ? (int) $me['budget_max'] : ''),
    'cost_sharing'    => 'equal',
    'university_only' => 0,
    'gender_rule'     => 'any',
    'tags'            => array_map('intval', array_column(user_tags((int) $me['id']), 'id')),
    'description'     => '',
];

if (is_post()) {
    verify_csrf();
    $form = [
        'destination_id'  => int_param($_POST, 'destination_id'),
        'package_id'      => int_param($_POST, 'package_id'),
        'title'           => trim($_POST['title'] ?? ''),
        'start_date'      => $_POST['start_date'] ?? '',
        'end_date'        => $_POST['end_date'] ?? '',
        'max_members'     => int_param($_POST, 'max_members'),
        'min_members'     => int_param($_POST, 'min_members'),
        'budget_max'      => trim((string) ($_POST['budget_max'] ?? '')),
        'cost_sharing'    => ($_POST['cost_sharing'] ?? '') === 'custom' ? 'custom' : 'equal',
        'university_only' => empty($_POST['university_only']) ? 0 : 1,
        'gender_rule'     => $_POST['gender_rule'] ?? 'any',
        'tags'            => array_values(array_intersect(array_map('intval', (array) ($_POST['tags'] ?? [])), array_map('intval', array_column($allTags, 'id')))),
        'description'     => trim($_POST['description'] ?? ''),
    ];

    $package = $packagesById[$form['package_id']] ?? null;
    if (!$package) {
        $errors['package_id'] = 'Choose a package.';
    } elseif ((int) $package['destination_id'] !== $form['destination_id']) {
        $errors['package_id'] = 'That package is not at the chosen destination.';
    }
    if (mb_strlen($form['title']) < 3 || mb_strlen($form['title']) > 150) {
        $errors['title'] = 'Give your group a name (3–150 characters).';
    }
    if (!valid_date($form['start_date']) || !valid_date($form['end_date'])) {
        $errors['dates'] = 'Choose a start and end date.';
    } elseif ($form['start_date'] <= date('Y-m-d')) {
        $errors['dates'] = 'The trip must start in the future (tomorrow or later).';
    } elseif ($form['end_date'] < $form['start_date']) {
        $errors['dates'] = 'The end date must be on or after the start date.';
    } elseif (days_between($form['start_date'], $form['end_date']) > 30) {
        $errors['dates'] = 'A trip can be at most 30 days.';
    } elseif ($conflict = date_conflict((int) $me['id'], $form['start_date'], $form['end_date'])) {
        $errors['dates'] = 'You already have a trip on these dates: ' . $conflict['title'] . '.';
    }
    if ($package) {
        if ($form['max_members'] < 2 || $form['max_members'] > (int) $package['max_group_size']) {
            $errors['max_members'] = 'Group size must be between 2 and ' . (int) $package['max_group_size'] . ' for this package.';
        } elseif ($form['min_members'] < max(2, (int) $package['min_group_size']) || $form['min_members'] > $form['max_members']) {
            $errors['min_members'] = 'Minimum headcount must be between ' . max(2, (int) $package['min_group_size']) . ' and the group size.';
        }
    }
    if (!preg_match('/^\d{3,6}$/', $form['budget_max'])) {
        $errors['budget_max'] = 'Enter the budget per person in whole taka.';
    } elseif ($package && !isset($errors['max_members'])) {
        $fullPrice = per_person_price((int) $package['id'], $form['max_members']);
        if ((int) $form['budget_max'] < $fullPrice) {
            $errors['budget_max'] = 'Even with a full group of ' . $form['max_members'] . ', this package costs ' . money($fullPrice) . ' per person. Raise the budget or choose a bigger group.';
        }
    }
    if (!in_array($form['gender_rule'], ['any', 'female_only', 'male_only'], true)) {
        $errors['gender_rule'] = 'Choose who can join.';
    } elseif ($form['gender_rule'] === 'female_only' && ($me['gender'] ?? '') !== 'female') {
        $errors['gender_rule'] = 'Only women can start a women-only group. Set your gender in Edit Profile first.';
    } elseif ($form['gender_rule'] === 'male_only' && ($me['gender'] ?? '') !== 'male') {
        $errors['gender_rule'] = 'Only men can start a men-only group. Set your gender in Edit Profile first.';
    }
    if (mb_strlen($form['description']) > 2000) {
        $errors['description'] = 'Please keep the trip details under 2000 characters.';
    }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare(
            'INSERT INTO travel_groups (package_id, creator_id, title, description, start_date, end_date, min_members, max_members,
                                        budget_max, cost_sharing, university_only, gender_rule)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $package['id'], $me['id'], $form['title'], $form['description'] ?: null, $form['start_date'], $form['end_date'],
            $form['min_members'], $form['max_members'], (int) $form['budget_max'], $form['cost_sharing'],
            $form['university_only'], $form['gender_rule'],
        ]);
        $groupId = (int) $pdo->lastInsertId();

        // The organiser is automatically the first member
        $pdo->prepare("INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, 'organizer')")->execute([$groupId, $me['id']]);

        $insertTag = $pdo->prepare('INSERT INTO group_tags (group_id, tag_id) VALUES (?, ?)');
        foreach ($form['tags'] as $tagId) {
            $insertTag->execute([$groupId, $tagId]);
        }
        $pdo->commit();

        set_flash('success', 'Your group is live! Share it with friends; the price drops as people join.');
        redirect('group.php?id=' . $groupId);
    }
}

$pageTitle = 'Start a Group';
$activeNav = 'groups.php';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container container-form">
    <div class="page-head">
        <h1 class="display-title">Start a Group</h1>
        <p>Create a new adventure and find fellow travelers to join you.</p>
    </div>

    <?php if ($errors): ?><?= alert('error', 'Please fix the highlighted fields below.') ?><?php endif; ?>

    <form class="card form-card" method="post" novalidate id="create-group-form">
        <?= csrf_field() ?>

        <h2 class="form-section-title">Where to?</h2>
        <div class="form-row">
            <div class="form-group">
                <label for="destination_id">Destination</label>
                <div class="input-wrap">
                    <?= icon('map-pin') ?>
                    <select class="input" id="destination_id" name="destination_id" required>
                        <option value="">Choose a destination</option>
                        <?php foreach ($destinations as $d): ?>
                            <option value="<?= $d['id'] ?>" <?= (int) $form['destination_id'] === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label for="package_id">Package</label>
                <select class="input" id="package_id" name="package_id" required>
                    <option value="">Choose a package</option>
                    <?php foreach ($packages as $p): $t = package_cost_totals((int) $p['id']); ?>
                        <option value="<?= $p['id'] ?>" data-destination="<?= $p['destination_id'] ?>"
                                data-shared="<?= $t['shared'] ?>" data-per-person="<?= $t['per_person'] ?>"
                                data-max="<?= $p['max_group_size'] ?>" data-min="<?= max(2, $p['min_group_size']) ?>"
                                data-type="<?= e(TRIP_TYPE_LABELS[$p['trip_type']]) ?>"
                                <?= (int) $form['package_id'] === (int) $p['id'] ? 'selected' : '' ?>>
                            <?= e($p['title']) ?> (<?= e(TRIP_TYPE_LABELS[$p['trip_type']]) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <?= field_error($errors, 'package_id') ?>
            </div>
        </div>
        <div class="form-group">
            <label for="title">Group name</label>
            <input class="input" id="title" name="title" value="<?= e($form['title']) ?>" placeholder="e.g. Sajek Valley Weekend Escape" maxlength="150" required>
            <?= field_error($errors, 'title') ?>
        </div>

        <h2 class="form-section-title">Logistics</h2>
        <div class="form-row">
            <div class="form-group">
                <span class="label">Dates</span>
                <div class="date-pair">
                    <input class="input" type="date" name="start_date" aria-label="Start date" min="<?= date('Y-m-d', strtotime('+1 day')) ?>" value="<?= e($form['start_date']) ?>" required>
                    <input class="input" type="date" name="end_date" aria-label="End date" min="<?= date('Y-m-d', strtotime('+1 day')) ?>" value="<?= e($form['end_date']) ?>" required>
                </div>
                <?= field_error($errors, 'dates') ?>
            </div>
            <div class="form-group">
                <div class="form-row form-row-tight">
                    <div>
                        <label for="max_members">Ideal group size</label>
                        <select class="input" id="max_members" name="max_members">
                            <?php for ($n = 2; $n <= 12; $n++): ?>
                                <option value="<?= $n ?>" <?= (int) $form['max_members'] === $n ? 'selected' : '' ?>><?= $n ?> people</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label for="min_members">Minimum to go</label>
                        <select class="input" id="min_members" name="min_members">
                            <?php for ($n = 2; $n <= 12; $n++): ?>
                                <option value="<?= $n ?>" <?= (int) $form['min_members'] === $n ? 'selected' : '' ?>><?= $n ?> people</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <?= field_error($errors, 'max_members') ?><?= field_error($errors, 'min_members') ?>
                <div class="field-help">The trip can be confirmed and booked once the minimum is reached.</div>
            </div>
        </div>

        <div class="price-preview" id="price-preview" hidden>
            <?= icon('banknote') ?>
            <div>
                <strong id="preview-text"></strong>
                <small id="preview-sub"></small>
            </div>
        </div>

        <h2 class="form-section-title">Finances</h2>
        <div class="form-row">
            <div class="form-group">
                <label for="budget_max">Estimated budget (per person)</label>
                <div class="input-wrap">
                    <?= icon('banknote') ?>
                    <input class="input" type="number" id="budget_max" name="budget_max" min="100" step="100" value="<?= e((string) $form['budget_max']) ?>" placeholder="e.g. 5000" required>
                </div>
                <?= field_error($errors, 'budget_max') ?>
            </div>
            <fieldset class="form-group">
                <legend class="label">Cost sharing preference</legend>
                <div class="segmented">
                    <label><input type="radio" name="cost_sharing" value="equal" <?= $form['cost_sharing'] === 'equal' ? 'checked' : '' ?>><span>Split Equally</span></label>
                    <label><input type="radio" name="cost_sharing" value="custom" <?= $form['cost_sharing'] === 'custom' ? 'checked' : '' ?>><span>Custom</span></label>
                </div>
                <div class="field-help">Default for trip expenses. Package price is always split equally.</div>
            </fieldset>
        </div>

        <h2 class="form-section-title">Preferences</h2>
        <div class="toggle-box">
            <label class="switch"><input type="checkbox" name="university_only" value="1" <?= $form['university_only'] ? 'checked' : '' ?>><span class="switch-track"></span></label>
            <div>
                <strong>University-Exclusive Group</strong>
                <p>Only students from <?= e($me['university_name'] ?? 'your university') ?> can join. Great for building local community connections.</p>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="gender_rule">Who can join</label>
                <select class="input" id="gender_rule" name="gender_rule">
                    <option value="any" <?= $form['gender_rule'] === 'any' ? 'selected' : '' ?>>Everyone</option>
                    <option value="female_only" <?= $form['gender_rule'] === 'female_only' ? 'selected' : '' ?>>Women only</option>
                    <option value="male_only" <?= $form['gender_rule'] === 'male_only' ? 'selected' : '' ?>>Men only</option>
                </select>
                <?= field_error($errors, 'gender_rule') ?>
            </div>
        </div>
        <fieldset class="form-group">
            <legend class="label">Trip style</legend>
            <div class="tag-picker">
                <?php foreach ($allTags as $tag): ?>
                    <label class="tag-option"><input type="checkbox" name="tags[]" value="<?= $tag['id'] ?>" <?= in_array((int) $tag['id'], $form['tags'], true) ? 'checked' : '' ?>><span><?= e($tag['name']) ?></span></label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <h2 class="form-section-title">Trip Details</h2>
        <div class="form-group">
            <label for="description">Trip vibe &amp; details</label>
            <textarea class="input" id="description" name="description" rows="5" maxlength="2000"
                      placeholder="Describe the kind of trip you want to have. What's the pace? What are the must-do activities? What kind of travelers are you looking for?"><?= e($form['description']) ?></textarea>
            <?= field_error($errors, 'description') ?>
        </div>

        <div class="form-actions">
            <button class="btn btn-primary btn-lg" type="submit"><?= icon('send', 'icon-sm') ?> Post to Discovery</button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
