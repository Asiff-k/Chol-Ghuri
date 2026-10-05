<?php
/**
 * dashboard.php - My Dashboard (design: "My Dashboard")
 *
 * Everything shown is read from the database for the logged-in student:
 * profile summary, upcoming trips, best matches from the last search,
 * pending settlements, recent activity and recommended packages.
 */
require_once __DIR__ . '/includes/bootstrap.php';
$me = require_login();
if ($me['role'] !== 'student') {
    redirect('admin/index.php');
}
$uid = (int) $me['id'];

$stats   = user_stats($uid);
$tags    = user_tags($uid);
$missing = missing_profile_fields($me, $tags);

// Upcoming trips (not finished, not cancelled)
$upcoming = fetch_groups(
    "g.id IN (SELECT group_id FROM group_members WHERE user_id = ? AND status = 'joined')
     AND g.status IN ('forming','full','confirmed') AND g.end_date >= CURDATE()",
    [$uid], 'g.start_date', 4
);

// Best matches from the student's latest search
$intent  = latest_trip_intent($uid);
$matches = $intent ? array_slice(match_groups($intent, $me)['matches'], 0, 2) : [];

// Settlements I still need to pay or receive (any group)
$stmt = db()->prepare(
    "SELECT s.*, g.title AS group_title, f.full_name AS from_name, t.full_name AS to_name
     FROM settlements s
     JOIN travel_groups g ON g.id = s.group_id
     JOIN users f ON f.id = s.from_user_id
     JOIN users t ON t.id = s.to_user_id
     WHERE s.status <> 'confirmed' AND (s.from_user_id = ? OR s.to_user_id = ?)
     ORDER BY s.created_at DESC"
);
$stmt->execute([$uid, $uid]);
$pendingSettlements = $stmt->fetchAll();
$pendingTotal = 0;
foreach ($pendingSettlements as $s) {
    $pendingTotal += (int) $s['to_user_id'] === $uid ? $s['amount'] : -$s['amount'];
}

// Recent activity in my groups (other people's actions)
$stmt = db()->prepare(
    "(SELECT 'join' AS kind, gm.joined_at AS at, u.full_name AS who, g.title AS what, g.id AS gid
        FROM group_members gm JOIN users u ON u.id = gm.user_id JOIN travel_groups g ON g.id = gm.group_id
       WHERE gm.status = 'joined' AND gm.user_id <> :u1 AND gm.group_id IN (SELECT group_id FROM group_members WHERE user_id = :u2 AND status = 'joined'))
     UNION ALL
     (SELECT 'expense', e.created_at, u.full_name, CONCAT(e.title, ' (', g.title, ')'), g.id
        FROM expenses e JOIN users u ON u.id = e.created_by JOIN travel_groups g ON g.id = e.group_id
       WHERE e.group_id IN (SELECT group_id FROM group_members WHERE user_id = :u3 AND status = 'joined'))
     UNION ALL
     (SELECT 'payment', b.paid_at, u.full_name, g.title, g.id
        FROM bookings b JOIN users u ON u.id = b.user_id JOIN travel_groups g ON g.id = b.group_id
       WHERE b.status = 'paid' AND b.group_id IN (SELECT group_id FROM group_members WHERE user_id = :u4 AND status = 'joined'))
     UNION ALL
     (SELECT IF(s.status = 'confirmed', 'settled', 'settle_paid'), COALESCE(s.confirmed_at, s.paid_at), u.full_name, g.title, g.id
        FROM settlements s JOIN users u ON u.id = s.from_user_id JOIN travel_groups g ON g.id = s.group_id
       WHERE s.status <> 'pending' AND (s.from_user_id = :u5 OR s.to_user_id = :u6))
     ORDER BY at DESC LIMIT 5"
);
$stmt->execute(['u1' => $uid, 'u2' => $uid, 'u3' => $uid, 'u4' => $uid, 'u5' => $uid, 'u6' => $uid]);
$activity = $stmt->fetchAll();
$activityText = [
    'join'        => ['user-plus',  '%s joined %s'],
    'expense'     => ['receipt',    '%s added an expense: %s'],
    'payment'     => ['credit-card','%s paid the booking for %s'],
    'settle_paid' => ['hand-coins', '%s marked a settlement as paid in %s'],
    'settled'     => ['check-check','A settlement from %s was confirmed in %s'],
];

// Recommended packages: same trip type first, then within budget, then cheapest
$packages = db()->query('SELECT p.*, d.name AS destination_name FROM packages p JOIN destinations d ON d.id = p.destination_id WHERE p.is_active = 1')->fetchAll();
foreach ($packages as &$p) {
    $p['from_price'] = starting_price($p);
    $p['fit'] = ($me['trip_type_pref'] === 'any' || $me['trip_type_pref'] === $p['trip_type'] ? 2 : 0)
              + (!$me['budget_max'] || $p['from_price'] <= $me['budget_max'] ? 1 : 0);
}
unset($p);
usort($packages, fn($a, $b) => [$b['fit'], $a['from_price']] <=> [$a['fit'], $b['from_price']]);
$recommended = array_slice($packages, 0, 2);

function days_until(string $date): string
{
    $days = (int) ((strtotime($date) - strtotime(date('Y-m-d'))) / 86400);
    return $days <= 0 ? 'Today' : ($days === 1 ? 'Tomorrow' : "In $days days");
}

$pageTitle = 'Dashboard';
$activeNav = 'dashboard.php';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="page-head page-head-row">
        <div>
            <h1 class="text-primary">Welcome back, <?= e(explode(' ', $me['full_name'])[0]) ?></h1>
            <p>Here is what is happening with your travels today.</p>
        </div>
        <a class="btn btn-light btn-sm" href="<?= url('destinations.php') ?>"><?= icon('compass', 'icon-sm text-primary') ?> Discover New Places</a>
    </div>

    <?= render_flash() ?>

    <div class="dash-grid">
        <!-- Left column -->
        <div class="dash-left">
            <section class="card text-center">
                <div class="dash-avatar"><?= avatar($me['profile_photo'], $me['full_name'], 'lg') ?></div>
                <h2 class="profile-name"><?= e($me['full_name']) ?></h2>
                <p class="text-small"><?= e($me['university_name'] ?? '') ?></p>
                <div class="dash-stats">
                    <div><strong><?= $stats['trips'] ?></strong><small>Trips</small></div>
                    <div><strong><?= $stats['rating'] ?? '–' ?></strong><small>Rating</small></div>
                    <div><strong><?= $stats['groups'] ?></strong><small>Groups</small></div>
                </div>
                <a class="btn btn-outline btn-block" href="<?= url('profile.php') ?>">View Profile</a>
            </section>

            <section class="card">
                <h3 class="card-title"><?= icon('shield-check', 'icon-sm text-primary') ?> Trust &amp; Verification</h3>
                <ul class="check-list">
                    <li class="ok"><?= icon('check', 'icon-sm') ?> Email verified</li>
                    <?php if (has_university_email($me)): ?>
                        <li class="ok"><?= icon('check', 'icon-sm') ?> <?= e($me['university_short']) ?> email verified</li>
                    <?php else: ?>
                        <li><?= icon('graduation-cap', 'icon-sm') ?> <?= e($me['university_short'] ?? 'University') ?> (self-declared)</li>
                    <?php endif; ?>
                    <li class="<?= $missing ? '' : 'ok' ?>"><?= icon($missing ? 'x' : 'check', 'icon-sm') ?> Profile <?= $missing ? 'incomplete' : 'complete' ?></li>
                </ul>
                <?php if ($missing): ?><a href="<?= url('edit-profile.php') ?>">Complete your profile</a><?php endif; ?>
            </section>

            <section class="settle-card">
                <span>Pending Settlements</span>
                <strong class="settle-total"><?= $pendingTotal < 0 ? '-' : '' ?><?= money(abs($pendingTotal)) ?></strong>
                <?php if ($pendingSettlements): ?>
                    <?php foreach (array_slice($pendingSettlements, 0, 3) as $s): $incoming = (int) $s['to_user_id'] === $uid; ?>
                        <a class="settle-line" href="<?= url('settle.php?group=' . $s['group_id']) ?>">
                            <span class="avatar avatar-sm <?= $incoming ? 'avatar-white' : 'avatar-orange' ?>"><?= e(initials($incoming ? $s['from_name'] : $s['to_name'])) ?></span>
                            <span><strong><?= $incoming ? e($s['from_name']) . ' owes you' : 'You owe ' . e($s['to_name']) ?></strong><small><?= e($s['group_title']) ?><?= $s['status'] === 'paid' ? ' &middot; marked paid' : '' ?></small></span>
                            <em><?= $incoming ? '+' : '-' ?><?= money($s['amount']) ?></em>
                        </a>
                    <?php endforeach; ?>
                    <a class="btn btn-mint btn-block" href="<?= url('settle.php?group=' . $pendingSettlements[0]['group_id']) ?>">Settle Up</a>
                <?php else: ?>
                    <p class="mb-0">You're all settled. Nothing to pay or receive.</p>
                <?php endif; ?>
            </section>

            <section class="card">
                <h2 class="card-section-title">Activity</h2>
                <?php if ($activity): ?>
                    <ul class="activity">
                        <?php foreach ($activity as $a): [$ic, $fmt] = $activityText[$a['kind']]; ?>
                            <li>
                                <span class="icon-circle"><?= icon($ic, 'icon-sm') ?></span>
                                <div><a href="<?= url('group.php?id=' . $a['gid']) ?>"><?= e(sprintf($fmt, $a['who'], $a['what'])) ?></a><small><?= date('j M, g:i a', strtotime($a['at'])) ?></small></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="text-small mb-0">No recent activity in your groups.</p>
                <?php endif; ?>
            </section>
        </div>

        <!-- Right column -->
        <div class="dash-right">
            <section class="card">
                <div class="section-head">
                    <h2 class="card-section-title mb-0">Your Upcoming Trips</h2>
                    <a href="<?= url('my-trips.php') ?>">View All</a>
                </div>
                <?php if ($upcoming): ?>
                    <?php foreach ($upcoming as $g):
                        $b = find_booking((int) $g['id'], $uid);
                        $needsPay = $g['status'] === 'confirmed' && (!$b || $b['status'] !== 'paid'); ?>
                        <a class="upcoming" href="<?= url('group.php?id=' . $g['id']) ?>">
                            <span class="upcoming-img" style="background-image:url('<?= image_url($g['package_image']) ?>')"></span>
                            <span class="upcoming-body">
                                <strong><?= e($g['title']) ?></strong>
                                <small><?= icon('calendar', 'icon-sm') ?> <?= e(date_range($g['start_date'], $g['end_date'])) ?></small>
                                <?php if ($needsPay): ?>
                                    <small class="text-accent"><?= icon('triangle-alert', 'icon-sm') ?> Action required: payment</small>
                                <?php else: ?>
                                    <small class="text-muted"><?= (int) $g['member_count'] ?>/<?= (int) $g['max_members'] ?> members &middot; <?= money(group_price_now($g)) ?>/person</small>
                                <?php endif; ?>
                            </span>
                            <span class="badge <?= $g['status'] === 'confirmed' ? '' : 'badge-grey' ?>"><?= $g['status'] === 'confirmed' ? e(days_until($g['start_date'])) : 'Planning' ?></span>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">No upcoming trips. <a href="<?= url('groups.php') ?>">Find a group</a> or <a href="<?= url('create-group.php') ?>">start one</a>.</div>
                <?php endif; ?>
            </section>

            <section class="card">
                <div class="section-head">
                    <h2 class="card-section-title mb-0">Active Group Matches</h2>
                    <?php if ($intent): ?><a href="<?= url('groups.php?intent=' . $intent['id']) ?>">All matches</a><?php endif; ?>
                </div>
                <?php if ($intent): ?>
                    <p class="text-small">For your search: <?= e($intent['destination_name']) ?>, <?= e(date_range($intent['start_date'], $intent['end_date'])) ?>, up to <?= money($intent['budget_max']) ?>.</p>
                <?php endif; ?>
                <?php if ($matches): ?>
                    <div class="match-mini-grid">
                        <?php foreach ($matches as $m): $g = $m['group']; $need = max(0, (int) $g['min_members'] - (int) $g['member_count']); ?>
                            <div class="match-mini">
                                <span class="tag tag-grey"><?= e(strtoupper($g['destination_name'])) ?></span>
                                <strong><?= e($g['title']) ?></strong>
                                <div class="progress-label"><span class="text-primary"><?= (int) $g['member_count'] ?> Members</span><span class="text-accent"><?= $need ? "Need $need more" : $m['score'] . '% match' ?></span></div>
                                <div class="progress"><span style="width:<?= round(100 * $g['member_count'] / $g['max_members']) ?>%"></span></div>
                                <a class="btn btn-grey btn-sm btn-block" href="<?= url('group.php?id=' . $g['id'] . '&intent=' . $intent['id']) ?>">View Details</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($intent): ?>
                    <div class="empty-state">No groups match your last search right now. <a href="<?= url('groups.php?intent=' . $intent['id']) ?>">See why</a>.</div>
                <?php else: ?>
                    <div class="empty-state">Tell us where you want to go and we'll find compatible groups. <a href="<?= url('groups.php') ?>">Start a search</a>.</div>
                <?php endif; ?>
            </section>

            <section>
                <h2 class="section-title-sm"><?= icon('tag', 'text-accent') ?> Recommended Trips</h2>
                <div class="rec-grid">
                    <?php foreach ($recommended as $p): ?>
                        <a class="package-card" href="<?= url('package.php?id=' . $p['id']) ?>">
                            <span class="package-card-img" style="background-image:url('<?= image_url($p['image']) ?>')"><span class="pill-float"><?= e(TRIP_TYPE_LABELS[$p['trip_type']]) ?></span></span>
                            <span class="package-card-body">
                                <small class="text-muted"><?= e($p['destination_name']) ?></small>
                                <strong class="rec-title"><?= e($p['title']) ?></strong>
                                <span class="package-card-foot"><strong class="price-lg"><?= money($p['from_price']) ?><small>/person</small></strong><?= icon('arrow-right') ?></span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                    <a class="rec-cta" href="<?= url('groups.php') ?>">
                        <?= icon('search', 'icon-lg text-primary') ?>
                        <strong>Find your travel group</strong>
                        <span>Tell us your dates and budget. We'll explain every match.</span>
                        <span class="btn btn-light btn-sm">Search now</span>
                    </a>
                </div>
            </section>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
