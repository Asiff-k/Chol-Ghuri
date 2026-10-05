<?php
/**
 * groups.php - Find Your Travel Group (design: "Group Matching Results")
 *
 * 1. The student fills in a TRIP INTENT (destination, dates, budget, size...).
 * 2. POST saves it in trip_intents and redirects to groups.php?intent=ID.
 * 3. The matching engine (includes/matching.php) scores every group and
 *    explains each score. If nothing matches, a diagnosis explains why.
 */
require_once __DIR__ . '/includes/bootstrap.php';
$me = require_login();
if ($me['role'] !== 'student') {
    show_error_page('Students only', 'Group matching is for student accounts.', 403, 'admin/index.php', 'Admin panel');
}

$destinations = db()->query('SELECT id, name FROM destinations ORDER BY name')->fetchAll();
$allTags      = all_tags();
$errors       = [];
$intent       = null;

// ---------- Saving a new search (trip intent) ----------
if (is_post()) {
    verify_csrf();
    $v = [
        'destination_id'     => int_param($_POST, 'destination_id'),
        'start_date'         => $_POST['start_date'] ?? '',
        'end_date'           => $_POST['end_date'] ?? '',
        'budget_max'         => trim((string) ($_POST['budget_max'] ?? '')),
        'group_size'         => $_POST['group_size'] ?? '',
        'trip_type'          => $_POST['trip_type'] ?? 'any',
        'accommodation_pref' => $_POST['accommodation_pref'] ?? 'any',
        'tag_ids'            => array_values(array_intersect(array_map('intval', (array) ($_POST['tags'] ?? [])), array_map('intval', array_column($allTags, 'id')))),
        'university_only'    => empty($_POST['university_only']) ? 0 : 1,
    ];

    if (!in_array($v['destination_id'], array_map('intval', array_column($destinations, 'id')), true)) {
        $errors['destination_id'] = 'Choose a destination.';
    }
    if (!valid_date($v['start_date']) || !valid_date($v['end_date'])) {
        $errors['dates'] = 'Choose a start and end date.';
    } elseif ($v['start_date'] < date('Y-m-d')) {
        $errors['dates'] = 'The start date cannot be in the past.';
    } elseif ($v['end_date'] < $v['start_date']) {
        $errors['dates'] = 'The end date must be on or after the start date.';
    } elseif (days_between($v['start_date'], $v['end_date']) > 30) {
        $errors['dates'] = 'Please search a range of 30 days or less.';
    }
    if (!preg_match('/^\d{3,6}$/', $v['budget_max']) || (int) $v['budget_max'] < 500) {
        $errors['budget_max'] = 'Enter your maximum budget per person in taka (at least ৳500).';
    }
    if (!isset(GROUP_SIZE_OPTIONS[$v['group_size']])) {
        $errors['group_size'] = 'Choose a group size.';
    }
    if (!isset(TRIP_TYPE_OPTIONS[$v['trip_type']])) {
        $errors['trip_type'] = 'Choose a trip type.';
    }
    if (!isset(ACCOMMODATION_OPTIONS[$v['accommodation_pref']])) {
        $errors['accommodation_pref'] = 'Choose a stay preference.';
    }

    if (!$errors) {
        $v['budget_max']     = (int) $v['budget_max'];
        $v['group_size_min'] = GROUP_SIZE_OPTIONS[$v['group_size']]['min'];
        $v['group_size_max'] = GROUP_SIZE_OPTIONS[$v['group_size']]['max'];
        $intentId = save_trip_intent((int) $me['id'], $v);
        redirect('groups.php?intent=' . $intentId);
    }
    $form = $v;
} else {
    // ---------- Showing results of a saved search ----------
    $intentId = int_param($_GET, 'intent');
    if ($intentId) {
        $intent = find_trip_intent($intentId, (int) $me['id']);
        if (!$intent) {
            show_error_page('Search not found', 'This search does not exist or belongs to someone else.', 404, 'groups.php', 'New search');
        }
    } elseif (!isset($_GET['destination']) && !isset($_GET['new'])) {
        // No new search requested: show the student's latest search, if any
        $intent = latest_trip_intent((int) $me['id']);
    }

    // Pre-fill the form: the saved search, values from the URL, then the profile
    $source = $intent ?? [];
    $form = [
        'destination_id'     => (int) ($source['destination_id'] ?? int_param($_GET, 'destination')),
        'start_date'         => $source['start_date'] ?? (valid_date($_GET['start'] ?? '') ? $_GET['start'] : ''),
        'end_date'           => $source['end_date'] ?? (valid_date($_GET['end'] ?? '') ? $_GET['end'] : ''),
        'budget_max'         => isset($source['budget_max']) ? (int) $source['budget_max'] : ($me['budget_max'] ? (int) $me['budget_max'] : ''),
        'group_size'         => $intent ? option_key_for(GROUP_SIZE_OPTIONS, $intent['group_size_min'], $intent['group_size_max'])
                                        : (option_key_for(GROUP_SIZE_OPTIONS, $me['group_size_min'], $me['group_size_max']) ?: '4-6'),
        'trip_type'          => $source['trip_type'] ?? ($me['trip_type_pref'] ?? 'any'),
        'accommodation_pref' => $source['accommodation_pref'] ?? ($me['accommodation_pref'] ?? 'any'),
        'tag_ids'            => $intent ? intent_tag_ids($intent) : array_map('intval', array_column(user_tags((int) $me['id']), 'id')),
        'university_only'    => (int) ($source['university_only'] ?? 0),
    ];
    if (!$intent && $form['start_date'] && !$form['end_date']) {
        $form['end_date'] = date('Y-m-d', strtotime($form['start_date'] . ' +2 days'));
    }
}

$result = $intent ? match_groups($intent, $me) : null;
$mainReason = $result ? main_blocking_reason($result['rejected']) : null;

$pageTitle = 'Find Your Travel Group';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="page-head">
        <h1>Find Your Travel Group</h1>
        <p><?php if ($result): ?>
            <?= count($result['matches']) ?> <?= count($result['matches']) === 1 ? 'group matches' : 'groups match' ?> your trip preferences.
        <?php else: ?>
            Tell us about your trip. We'll compare it with every forming group and explain each match.
        <?php endif; ?></p>
    </div>

    <?php if ($intent): ?>
        <div class="summary-bar">
            <span><?= icon('map-pin', 'icon-sm') ?> <?= e($intent['destination_name']) ?></span>
            <span><?= icon('calendar', 'icon-sm') ?> <?= e(date_range($intent['start_date'], $intent['end_date'])) ?></span>
            <span><?= icon('users', 'icon-sm') ?> <?= (int) $intent['group_size_min'] ?>–<?= (int) $intent['group_size_max'] ?> Students</span>
            <span><?= icon('banknote', 'icon-sm') ?> Up to <?= money($intent['budget_max']) ?>/person</span>
            <a class="btn btn-outline btn-sm" href="<?= url('groups.php?new=1') ?>">New Search</a>
        </div>
    <?php endif; ?>

    <?= render_flash() ?>

    <div class="layout-filters">
        <!-- Trip intent form -->
        <form class="card filters" method="post" action="<?= url('groups.php') ?>" novalidate>
            <?= csrf_field() ?>
            <h2 class="filters-title"><?= $intent ? 'Edit Your Trip' : 'Your Trip Intent' ?></h2>
            <?php if ($errors): ?><?= alert('error', 'Please fix the fields below.') ?><?php endif; ?>

            <div class="form-group">
                <label for="destination_id"><?= icon('map-pin', 'icon-sm') ?> Destination</label>
                <select class="input" id="destination_id" name="destination_id" required>
                    <option value="">Where to?</option>
                    <?php foreach ($destinations as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= (int) $form['destination_id'] === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error($errors, 'destination_id') ?>
            </div>

            <div class="form-group">
                <span class="label"><?= icon('calendar', 'icon-sm') ?> Dates</span>
                <div class="date-pair">
                    <input class="input" type="date" name="start_date" aria-label="Start date" min="<?= date('Y-m-d') ?>" value="<?= e($form['start_date']) ?>" required>
                    <input class="input" type="date" name="end_date" aria-label="End date" min="<?= date('Y-m-d') ?>" value="<?= e($form['end_date']) ?>" required>
                </div>
                <?= field_error($errors, 'dates') ?>
            </div>

            <div class="form-group">
                <label for="budget_max"><?= icon('banknote', 'icon-sm') ?> Max budget per person (৳)</label>
                <input class="input" type="number" id="budget_max" name="budget_max" min="500" step="100" value="<?= e((string) $form['budget_max']) ?>" placeholder="e.g. 5000" required>
                <?= field_error($errors, 'budget_max') ?>
            </div>

            <fieldset class="form-group">
                <legend class="label"><?= icon('users', 'icon-sm') ?> Group size</legend>
                <div class="chip-options">
                    <?php foreach (GROUP_SIZE_OPTIONS as $key => $opt): ?>
                        <label class="tag-option"><input type="radio" name="group_size" value="<?= e($key) ?>" <?= $form['group_size'] === $key ? 'checked' : '' ?>><span><?= e(str_replace(' people', '', $opt['label'])) ?></span></label>
                    <?php endforeach; ?>
                </div>
                <?= field_error($errors, 'group_size') ?>
            </fieldset>

            <div class="form-group">
                <label for="trip_type"><?= icon('tent', 'icon-sm') ?> Trip type</label>
                <select class="input" id="trip_type" name="trip_type">
                    <?php foreach (TRIP_TYPE_OPTIONS as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $form['trip_type'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="accommodation_pref"><?= icon('bed-double', 'icon-sm') ?> Stay</label>
                <select class="input" id="accommodation_pref" name="accommodation_pref">
                    <?php foreach (ACCOMMODATION_OPTIONS as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $form['accommodation_pref'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <fieldset class="form-group">
                <legend class="label"><?= icon('tag', 'icon-sm') ?> Interests</legend>
                <div class="tag-picker tag-picker-sm">
                    <?php foreach ($allTags as $tag): ?>
                        <label class="tag-option"><input type="checkbox" name="tags[]" value="<?= $tag['id'] ?>" <?= in_array((int) $tag['id'], $form['tag_ids'], true) ? 'checked' : '' ?>><span><?= e($tag['name']) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <label class="check form-group"><input type="checkbox" name="university_only" value="1" <?= $form['university_only'] ? 'checked' : '' ?>>
                <span>Only groups from my university (<?= e($me['university_short'] ?? '') ?>)</span></label>

            <button class="btn btn-primary btn-block" type="submit"><?= icon('search', 'icon-sm') ?> Find Matches</button>
        </form>

        <!-- Results -->
        <div>
            <div class="info-box">
                <?= icon('info') ?>
                <div>
                    <strong>How matching works</strong>
                    Groups must go to the same destination, overlap your dates, have space, fit your budget after you join and allow you to join (university / women-only rules).
                    The remaining groups are scored: <b>Dates 30</b> + <b>Budget 30</b> + <b>Group size 15</b> + <b>Interests 15</b> + <b>Trip &amp; stay 10</b> = 100.
                </div>
            </div>

            <?php if (!$result): ?>
                <div class="empty-state">Fill in your trip on the left and press <strong>Find Matches</strong>.</div>

            <?php elseif ($result['matches']): ?>
                <h2 class="results-title"><?= count($result['matches']) ?> <?= count($result['matches']) === 1 ? 'Group' : 'Groups' ?> Found</h2>

                <?php foreach ($result['matches'] as $i => $m): $g = $m['group']; $spots = (int) $g['max_members'] - (int) $g['member_count']; $orgRating = user_rating((int) $g['creator_id']); ?>
                    <article class="match-card">
                        <div class="match-card-img" style="background-image:url('<?= image_url($g['package_image']) ?>')">
                            <?php if ($i === 0): ?><span class="pill-float"><?= icon('star', 'icon-sm text-accent') ?> Best Match</span><?php endif; ?>
                            <div class="match-card-title">
                                <h3><?= e($g['title']) ?></h3>
                                <span><?= icon('calendar', 'icon-sm') ?> <?= e(date_range($g['start_date'], $g['end_date'])) ?></span>
                            </div>
                        </div>
                        <div class="match-card-body">
                            <div class="match-card-main">
                                <div class="match-score-row">
                                    <span class="score-badge <?= $m['score'] >= 80 ? 'is-high' : '' ?>"><?= $m['score'] ?>%<small>Match</small></span>
                                    <span class="text-small"><?= e($m['parts']['dates']['text']) ?>. <?= e($m['parts']['interests']['text']) ?>.</span>
                                </div>
                                <div class="organizer">
                                    <?= avatar($g['organizer_photo'], $g['organizer_name'], 'sm') ?>
                                    <div>
                                        <strong>Organized by <?= e($g['organizer_name']) ?></strong>
                                        <small><?= e($g['organizer_uni'] ?? '') ?><?php if ($orgRating): ?> &bull; <?= icon('star', 'icon-sm text-accent filled') ?> <?= $orgRating ?><?php endif; ?></small>
                                    </div>
                                </div>
                                <div class="tag-list">
                                    <?php foreach (group_tags((int) $g['id']) as $t): ?><span class="tag tag-grey"><?= e($t['name']) ?></span><?php endforeach; ?>
                                </div>
                            </div>
                            <div class="match-card-side">
                                <div class="progress-label"><span class="text-primary"><?= $spots ?> <?= $spots === 1 ? 'spot' : 'spots' ?> left</span><span><?= (int) $g['member_count'] ?>/<?= (int) $g['max_members'] ?> Members</span></div>
                                <div class="progress"><span style="width:<?= round(100 * $g['member_count'] / $g['max_members']) ?>%"></span></div>
                                <div class="price-block">
                                    <strong class="price-lg text-accent"><?= money($m['price_join']) ?><small>/person if you join</small></strong>
                                    <small>Full group: <?= money($m['price_full']) ?>/p</small>
                                    <?php if ($m['price_join'] > $m['price_full']): ?><span class="saving-pill">Save <?= money($m['price_join'] - $m['price_full']) ?> if full</span><?php endif; ?>
                                </div>
                            </div>

                            <details class="why">
                                <summary><?= icon('list-checks', 'icon-sm') ?> Why <?= $m['score'] ?>%? See the breakdown</summary>
                                <div class="score-parts">
                                    <?php foreach ($m['parts'] as $part): ?>
                                        <div class="score-part">
                                            <div class="score-part-head"><strong><?= e($part['label']) ?></strong><span><?= $part['points'] ?>/<?= $part['max'] ?></span></div>
                                            <div class="progress progress-thin"><span style="width:<?= round(100 * $part['points'] / $part['max']) ?>%"></span></div>
                                            <small><?= e($part['text']) ?></small>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </details>

                            <div class="match-card-actions">
                                <a class="btn btn-outline-accent" href="<?= url('group.php?id=' . $g['id'] . '&intent=' . $intent['id']) ?>">View Group</a>
                                <form method="post" action="<?= url('group.php?id=' . $g['id']) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="join">
                                    <input type="hidden" name="intent" value="<?= (int) $intent['id'] ?>">
                                    <button class="btn btn-primary" type="submit">Join Group</button>
                                </form>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($result): ?>
                <?php $rejectedTotal = array_sum($result['rejected']); ?>
                <section class="diagnosis <?= $result['matches'] ? 'is-secondary' : '' ?>">
                    <?php if (!$result['matches']): ?>
                        <h2><?= icon('search', 'text-accent') ?> No compatible groups found</h2>
                        <?php if ($result['same_destination'] === 0): ?>
                            <p class="diagnosis-main">No groups are going to <strong><?= e($intent['destination_name']) ?></strong> right now.</p>
                        <?php elseif ($mainReason): ?>
                            <p class="diagnosis-main">Main reason: <strong><?= e(REJECT_REASONS[$mainReason]) ?></strong> (<?= $result['rejected'][$mainReason] ?> of the <?= $result['same_destination'] ?> <?= e($intent['destination_name']) ?> <?= $result['same_destination'] === 1 ? 'group' : 'groups' ?>).</p>
                            <?php if (isset(REJECT_ADVICE[$mainReason])): ?><p class="text-small"><?= icon('info', 'icon-sm') ?> <?= e(REJECT_ADVICE[$mainReason]) ?></p><?php endif; ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <h3>Why other groups were not shown</h3>
                    <?php endif; ?>

                    <?php if ($rejectedTotal): ?>
                        <ul class="reason-list">
                            <?php arsort($result['rejected']); foreach ($result['rejected'] as $reason => $count): ?>
                                <li class="<?= $reason === $mainReason ? 'is-main' : '' ?>"><strong><?= $count ?> <?= $count === 1 ? 'group' : 'groups' ?></strong> &middot; <?= e(REJECT_REASONS[$reason]) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ($result['cheapest_over_budget'] !== null && !$result['matches']): ?>
                        <p class="text-small">The cheapest over-budget group would cost <strong><?= money($result['cheapest_over_budget']) ?></strong>/person if you joined.</p>
                    <?php endif; ?>

                    <a class="btn btn-primary" href="<?= url('create-group.php?' . http_build_query(['destination' => $intent['destination_id'], 'start' => $intent['start_date'], 'end' => $intent['end_date'], 'budget' => (int) $intent['budget_max']])) ?>">
                        <?= icon('circle-plus', 'icon-sm') ?> Start a new group for <?= e($intent['destination_name']) ?>
                    </a>
                </section>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
