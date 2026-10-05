<?php
/**
 * profile.php - Student Profile (design: "Student Profile")
 *
 *   profile.php        -> your own profile
 *   profile.php?id=5   -> another student's profile (if they made it visible)
 *
 * Every number here comes from the database:
 *   rating  = average of ratings received       trips  = completed trips joined
 *   groups  = all groups joined or organised    prices = per_person_price()
 */
require_once __DIR__ . '/includes/bootstrap.php';
$me = require_login();

$id   = (int) ($_GET['id'] ?? $me['id']);
$user = find_user_by_id($id);
$isOwn = $user && (int) $user['id'] === (int) $me['id'];

// Admin accounts have no student profile
if ($isOwn && $me['role'] === 'admin') {
    redirect('admin/index.php');
}

// Only verified, active students have a public profile
$exists  = $user && $user['role'] === 'student' && $user['email_verified_at'] !== null && (!$user['is_blocked'] || is_admin());
$private = $exists && !$isOwn && !$user['show_profile'] && !is_admin();

if ($exists && !$private) {
    $tags    = user_tags($id);
    $stats   = user_stats($id);
    $missing = missing_profile_fields($user, $tags);
    $firstName = explode(' ', $user['full_name'])[0];

    // Last 3 completed trips
    $stmt = db()->prepare(
        "SELECT g.id, g.title, g.start_date, g.package_id, COALESCE(p.image, d.image) AS image,
                (SELECT COUNT(*) FROM group_members m WHERE m.group_id = g.id AND m.status = 'joined') AS members
         FROM group_members gm
         JOIN travel_groups g ON g.id = gm.group_id
         JOIN packages p      ON p.id = g.package_id
         JOIN destinations d  ON d.id = p.destination_id
         WHERE gm.user_id = ? AND gm.status = 'joined' AND g.status = 'completed'
         ORDER BY g.start_date DESC
         LIMIT 3"
    );
    $stmt->execute([$id]);
    $trips = $stmt->fetchAll();

    // Reviews with a written comment (newest first)
    $stmt = db()->prepare(
        "SELECT r.score, r.comment, r.created_at, u.id AS rater_id, u.full_name, un.short_name, sp.profile_photo
         FROM ratings r
         JOIN users u                 ON u.id = r.rater_id
         LEFT JOIN universities un    ON un.id = u.university_id
         LEFT JOIN student_profiles sp ON sp.user_id = u.id
         WHERE r.rated_user_id = ? AND r.comment IS NOT NULL
         ORDER BY r.created_at DESC
         LIMIT 5"
    );
    $stmt->execute([$id]);
    $reviews = $stmt->fetchAll();

    // The next upcoming group they are in
    $stmt = db()->prepare(
        "SELECT g.id, g.title, g.package_id, g.max_members,
                (SELECT COUNT(*) FROM group_members m WHERE m.group_id = g.id AND m.status = 'joined') AS members
         FROM group_members gm
         JOIN travel_groups g ON g.id = gm.group_id
         WHERE gm.user_id = ? AND gm.status = 'joined'
           AND g.status IN ('forming', 'full', 'confirmed') AND g.end_date >= CURDATE()
         ORDER BY g.start_date
         LIMIT 1"
    );
    $stmt->execute([$id]);
    $activeGroup = $stmt->fetch();

}

$pageTitle = $exists && !$private ? $user['full_name'] : 'Profile';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <nav class="breadcrumb" aria-label="Breadcrumb">
        <a href="<?= url('index.php') ?>">Home</a> <?= icon('chevron-right', 'icon-sm') ?> <span>Profile</span>
    </nav>

    <?= render_flash() ?>

<?php if (!$exists || $private): ?>

    <section class="center-card narrow">
        <span class="icon-circle icon-circle-lg"><?= icon($private ? 'lock' : 'user') ?></span>
        <h1><?= $private ? 'This profile is private' : 'Profile not found' ?></h1>
        <p class="lead"><?= $private
            ? 'This student has chosen to hide their profile from other students.'
            : 'This student profile does not exist or is not available.' ?></p>
        <a href="<?= url('profile.php') ?>" class="btn btn-primary">Go to my profile</a>
    </section>

<?php else: ?>

    <div class="layout-sidebar">
        <div>
            <!-- Header card -->
            <section class="card">
                <div class="profile-header">
                    <div class="profile-photo">
                        <?= avatar($user['profile_photo'], $user['full_name'], 'xl') ?>
                        <span class="verified-dot" title="Verified student"><?= icon('check') ?></span>
                    </div>
                    <div class="profile-info">
                        <div class="profile-top">
                            <div>
                                <h1 class="profile-name"><?= e($user['full_name']) ?></h1>
                                <div class="profile-uni">
                                    <?= icon('graduation-cap', 'icon-sm') ?> <?= e($user['university_name']) ?>
                                    <span class="badge"><?= icon('badge-check', 'icon-sm') ?> <?= has_university_email($user) ? 'University email verified' : 'Email verified' ?></span>
                                </div>
                            </div>
                            <?php if ($isOwn): ?>
                                <a href="<?= url('edit-profile.php') ?>" class="btn btn-primary"><?= icon('pencil', 'icon-sm') ?> Edit Profile</a>
                            <?php endif; ?>
                        </div>
                        <div class="profile-meta">
                            <?php if ($stats['rating'] !== null): ?>
                                <span class="rating"><?= icon('star') ?> <?= e($stats['rating']) ?></span>
                            <?php endif; ?>
                            <span><?= icon('footprints') ?> <?= $stats['trips'] ?> <?= $stats['trips'] === 1 ? 'Trip' : 'Trips' ?></span>
                            <span><?= icon('users') ?> <?= $stats['groups'] ?> <?= $stats['groups'] === 1 ? 'Group' : 'Groups' ?></span>
                            <?php if ($user['city']): ?>
                                <span><?= icon('map-pin') ?> <?= e($user['city']) ?>, Bangladesh</span>
                            <?php endif; ?>
                            <span><?= icon('calendar') ?> Member since <?= date('Y', strtotime($user['created_at'])) ?></span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Trust & Verification -->
            <section class="card">
                <h2 class="card-title">Trust &amp; Verification</h2>
                <div class="trust-grid">
                    <div class="trust-item"><span class="icon-circle"><?= icon('check') ?></span> Email verified</div>
                    <?php if (has_university_email($user)): ?>
                        <div class="trust-item"><span class="icon-circle"><?= icon('check') ?></span> <?= e($user['university_short']) ?> email</div>
                    <?php else: ?>
                        <div class="trust-item is-info"><span class="icon-circle"><?= icon('graduation-cap') ?></span> <?= e($user['university_short']) ?> (self-declared)</div>
                    <?php endif; ?>
                    <?php if (!$missing): ?>
                        <div class="trust-item"><span class="icon-circle"><?= icon('check') ?></span> Profile Complete</div>
                    <?php else: ?>
                        <div class="trust-item is-missing"><span class="icon-circle"><?= icon('clock') ?></span> Profile Incomplete</div>
                    <?php endif; ?>
                </div>
                <?php if ($missing && $isOwn): ?>
                    <p class="trust-hint"><?= icon('circle-alert', 'icon-sm') ?>
                        <span>Complete your profile (<?= e(implode(', ', $missing)) ?>) to increase trust. <a href="<?= url('edit-profile.php') ?>">Complete now</a></span>
                    </p>
                <?php endif; ?>
            </section>

            <!-- About me -->
            <section class="card">
                <h2 class="card-title">About Me</h2>
                <?php if ($user['bio']): ?>
                    <p class="mb-0" style="font-size:1.0625rem"><?= nl2br(e($user['bio'])) ?></p>
                <?php else: ?>
                    <p class="text-muted mb-0"><?= $isOwn ? 'Tell other students a little about yourself.' : e($firstName) . ' has not written anything yet.' ?></p>
                <?php endif; ?>
            </section>

            <!-- Travel style -->
            <section class="card">
                <h2 class="card-title">Travel Style</h2>
                <div class="section-label">Interests</div>
                <?php if ($tags): ?>
                    <div class="tag-list">
                        <?php foreach ($tags as $tag): ?>
                            <span class="tag tag-<?= e($tag['color']) ?>"><?= e($tag['name']) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0">No interests selected yet.</p>
                <?php endif; ?>

                <div class="pref-grid">
                    <div class="pref-item">
                        <?= icon('users') ?>
                        <div><strong>Group Size</strong>
                            <span><?= $user['group_size_max'] ? (int) $user['group_size_min'] . '–' . (int) $user['group_size_max'] . ' people' : 'Not set' ?></span></div>
                    </div>
                    <div class="pref-item accent">
                        <?= icon('banknote') ?>
                        <div><strong>Budget Range</strong>
                            <span><?= $user['budget_max'] ? money($user['budget_min']) . '–' . money($user['budget_max']) . '/person' : 'Not set' ?></span></div>
                    </div>
                    <div class="pref-item">
                        <?= icon('calendar') ?>
                        <div><strong>Trip Type</strong>
                            <span><?= e(TRIP_TYPE_OPTIONS[$user['trip_type_pref'] ?? 'any']) ?></span></div>
                    </div>
                    <div class="pref-item">
                        <?= icon('bed-double') ?>
                        <div><strong>Accommodation</strong>
                            <span><?= e(ACCOMMODATION_OPTIONS[$user['accommodation_pref'] ?? 'any']) ?><?= ($user['accommodation_pref'] ?? 'any') !== 'any' ? ' preferred' : '' ?></span></div>
                    </div>
                </div>
            </section>

            <!-- Trip history -->
            <section style="margin-top:24px">
                <h2 class="card-title">Trip History</h2>
                <?php if ($trips): ?>
                    <div class="trip-grid">
                        <?php foreach ($trips as $trip): ?>
                            <article class="trip-card">
                                <div class="trip-card-img" style="background-image:url('<?= url('assets/images/destinations/' . rawurlencode($trip['image'])) ?>')">
                                    <span class="badge badge-solid">Completed</span>
                                </div>
                                <div class="trip-card-body">
                                    <h3><?= e($trip['title']) ?></h3>
                                    <div class="trip-card-row" style="margin-bottom:10px">
                                        <span><?= icon('calendar', 'icon-sm') ?> <?= date('M Y', strtotime($trip['start_date'])) ?></span>
                                    </div>
                                    <div class="trip-card-row">
                                        <span class="price"><?= money(per_person_price((int) $trip['package_id'], (int) $trip['members'])) ?><small>/pp</small></span>
                                        <span><?= icon('users', 'icon-sm') ?> <?= (int) $trip['members'] ?></span>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">No completed trips yet.</div>
                <?php endif; ?>
            </section>

            <!-- Reviews -->
            <section class="card" style="margin-top:24px">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px">
                    <h2 class="card-title mb-0">Reviews</h2>
                    <?php if ($stats['rating'] !== null): ?>
                        <span class="rating-summary"><?= e($stats['rating']) ?> <?= stars((float) $stats['rating']) ?> (<?= $stats['reviews_count'] ?>)</span>
                    <?php endif; ?>
                </div>
                <?php if ($reviews): ?>
                    <?php foreach ($reviews as $review): ?>
                        <div class="review">
                            <div class="review-head">
                                <?= avatar($review['profile_photo'], $review['full_name'], 'sm') ?>
                                <div>
                                    <strong><?= e($review['full_name']) ?></strong>
                                    <small><?= e($review['short_name']) ?> &bull; <?= date('M Y', strtotime($review['created_at'])) ?></small>
                                </div>
                            </div>
                            <p>&ldquo;<?= e($review['comment']) ?>&rdquo;</p>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">No reviews yet. Reviews appear after completed trips.</div>
                <?php endif; ?>
            </section>

            <!-- Current active group -->
            <?php if ($activeGroup): ?>
                <section class="card active-group">
                    <h2 class="card-title">Current Active Group</h2>
                    <div class="active-group-box">
                        <div>
                            <h3><?= e($activeGroup['title']) ?></h3>
                            <div class="active-group-meta">
                                <span><?= icon('users', 'icon-sm') ?> <?= (int) $activeGroup['members'] ?> / <?= (int) $activeGroup['max_members'] ?> members</span>
                                <span><?= icon('banknote', 'icon-sm text-accent') ?> <?= money(per_person_price((int) $activeGroup['package_id'], (int) $activeGroup['members'])) ?>/person</span>
                            </div>
                        </div>
                        <a href="<?= url('group.php?id=' . (int) $activeGroup['id']) ?>" class="btn btn-outline">View Group</a>
                    </div>
                </section>
            <?php endif; ?>
        </div>

        <!-- Sidebar: profile summary -->
        <aside class="card">
            <div class="section-label">Profile Summary</div>
            <div class="summary-list">
                <div class="summary-item">
                    <span class="icon-circle"><?= icon('badge-check') ?></span>
                    <div><strong><?= has_university_email($user) ? 'University email verified' : 'Email verified' ?></strong><span><?= e($user['university_name']) ?></span></div>
                </div>
                <div class="summary-item">
                    <span class="icon-circle accent"><?= icon('star') ?></span>
                    <div>
                        <?php if ($stats['rating'] !== null): ?>
                            <strong><?= e($stats['rating']) ?> Rating</strong><span>From <?= $stats['reviews_count'] ?> <?= $stats['reviews_count'] === 1 ? 'review' : 'reviews' ?></span>
                        <?php else: ?>
                            <strong>No rating yet</strong><span>Ratings come after trips</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="summary-item">
                    <span class="icon-circle blue"><?= icon('footprints') ?></span>
                    <div><strong><?= $stats['trips'] ?> <?= $stats['trips'] === 1 ? 'Trip' : 'Trips' ?></strong><span>Completed successfully</span></div>
                </div>
                <div class="summary-item">
                    <span class="icon-circle teal"><?= icon('users') ?></span>
                    <div><strong><?= $stats['groups'] ?> <?= $stats['groups'] === 1 ? 'Group' : 'Groups' ?></strong><span>Joined or organized</span></div>
                </div>
            </div>
            <div class="info-panel">
                <h4><?= icon('shield', 'icon-sm') ?> Travel Together</h4>
                Verified profiles like <?= e($firstName) ?>'s help build a trusted community. It makes finding group matches for affordable student travel safer and easier.
            </div>
        </aside>
    </div>

<?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
