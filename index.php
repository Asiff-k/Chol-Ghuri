<?php
/**
 * index.php - Home page (design: "Chol Ghuri - Home")
 *
 * Every number here (prices, members, groups) comes from the database.
 */
require_once __DIR__ . '/includes/bootstrap.php';

$destinations = db()->query('SELECT * FROM destinations ORDER BY name')->fetchAll();
$featured     = db()->query('SELECT * FROM destinations WHERE is_featured = 1 ORDER BY id LIMIT 3')->fetchAll();

// Trending = open groups that are filling up fastest
$trending = fetch_groups(open_groups_where(), [], '(member_count / g.max_members) DESC, g.start_date', 3);

// "Your group gets cheaper" example: the most popular package (most groups)
$examplePackage = db()->query(
    'SELECT p.* FROM packages p LEFT JOIN travel_groups g ON g.package_id = p.id
     WHERE p.is_active = 1 GROUP BY p.id ORDER BY COUNT(g.id) DESC, p.id LIMIT 1'
)->fetch();
$savings = [];
if ($examplePackage) {
    $max = min(6, (int) $examplePackage['max_group_size']);
    foreach ([2, 4, $max] as $n) {
        $savings[$n] = per_person_price((int) $examplePackage['id'], $n);
    }
}

$studentCount = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND email_verified_at IS NOT NULL")->fetchColumn();

$pageTitle = 'Discover Bangladesh with fellow students';
$bodyClass = 'bg-white';
require_once __DIR__ . '/includes/header.php';
?>

<section class="hero" style="background-image:url('<?= image_url('sundarbans.jpg') ?>')">
    <div class="container hero-inner">
        <h1>Discover Bangladesh with fellow students.</h1>
        <form class="hero-search" method="get" action="<?= url('packages.php') ?>">
            <label class="hero-field">
                <?= icon('search') ?>
                <select name="destination" aria-label="Where to?">
                    <option value="">Where to?</option>
                    <?php foreach ($destinations as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="hero-field">
                <?= icon('calendar') ?>
                <input type="date" name="start" min="<?= date('Y-m-d') ?>" aria-label="From date">
            </label>
            <label class="hero-field">
                <?= icon('users') ?>
                <select name="size" aria-label="Group size">
                    <option value="">Group size</option>
                    <?php foreach ([2, 3, 4, 5, 6, 8, 10] as $n): ?>
                        <option value="<?= $n ?>"><?= $n ?> students</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn btn-primary" type="submit">Search</button>
        </form>
    </div>
</section>

<section class="section">
    <div class="container split-section">
        <div>
            <h2 class="section-title">Find Your Travel Group</h2>
            <p class="section-text">Connect with compatible university students looking to explore Bangladesh. Whether you want a chill beach weekend or a tough trek, Chol Ghuri helps you find the right people to share the journey and the costs.</p>
            <a class="btn btn-primary" href="<?= url('groups.php') ?>">Find a group <?= icon('arrow-right', 'icon-sm') ?></a>
        </div>
        <img class="rounded-img" src="<?= url('assets/images/students-hiking.jpg') ?>" alt="Students hiking together">
    </div>
</section>

<section class="section section-alt" id="how-it-works">
    <div class="container text-center">
        <h2 class="section-title">How Chol Ghuri Works</h2>
        <div class="steps">
            <div class="step"><span class="icon-circle"><?= icon('map') ?></span><h3>Tell us where</h3><p>Pick your destination, dates and budget.</p></div>
            <div class="step"><span class="icon-circle"><?= icon('search') ?></span><h3>Find students</h3><p>Our matching shows compatible groups and explains why.</p></div>
            <div class="step"><span class="icon-circle"><?= icon('user-plus') ?></span><h3>Form a group</h3><p>Join one, or start your own and let others join.</p></div>
            <div class="step"><span class="icon-circle"><?= icon('hand-coins') ?></span><h3>Travel &amp; settle</h3><p>Track expenses and settle up in a few transfers.</p></div>
        </div>
    </div>
</section>

<?php if ($examplePackage): ?>
<section class="section">
    <div class="container text-center">
        <h2 class="section-title">Your Group Gets Cheaper</h2>
        <p class="section-text narrow-text">The more students join your trip, the less everyone pays. Shared costs like transport and rooms are split between members.
            Example: <a href="<?= url('package.php?id=' . $examplePackage['id']) ?>"><?= e($examplePackage['title']) ?></a>.</p>
        <div class="savings">
            <?php $i = 0; foreach ($savings as $n => $price): $i++; ?>
                <?php if ($i > 1): ?><span class="savings-arrow"><?= icon('arrow-right') ?></span><?php endif; ?>
                <div class="savings-card <?= $i === count($savings) ? 'is-best' : '' ?>">
                    <div class="savings-people"><?= str_repeat(icon('user', 'icon-sm'), min($n, 6)) ?></div>
                    <span><?= $n ?> Students</span>
                    <strong><?= money($price) ?> <small>/person</small></strong>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="section section-alt">
    <div class="container">
        <div class="section-head">
            <div>
                <h2 class="section-title">Trending Groups</h2>
                <p class="mb-0">Join groups that are forming right now.</p>
            </div>
            <a href="<?= url('groups.php') ?>" class="link-accent">See all groups <?= icon('arrow-right', 'icon-sm') ?></a>
        </div>
        <?php if ($trending): ?>
            <div class="card-grid-3">
                <?php foreach ($trending as $g): $pct = round(100 * $g['member_count'] / $g['max_members']); ?>
                    <article class="group-card">
                        <div class="group-card-top">
                            <h3><a href="<?= url('group.php?id=' . $g['id']) ?>"><?= e($g['title']) ?></a></h3>
                            <span class="badge badge-grey"><?= e($g['organizer_uni'] ?? '') ?></span>
                        </div>
                        <p class="meta"><?= icon('calendar', 'icon-sm') ?> <?= e(date_range($g['start_date'], $g['end_date'])) ?> &middot; <?= e($g['destination_name']) ?></p>
                        <div class="progress-label"><span>Members</span><span class="text-accent"><?= (int) $g['member_count'] ?>/<?= (int) $g['max_members'] ?> joined</span></div>
                        <div class="progress"><span style="width:<?= $pct ?>%"></span></div>
                        <div class="group-card-foot">
                            <div><small>Current cost</small><strong><?= money(group_price_now($g)) ?></strong></div>
                            <a class="btn btn-sm <?= $pct >= 80 ? 'btn-accent' : 'btn-grey' ?>" href="<?= url('group.php?id=' . $g['id']) ?>">View Group</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">No groups are forming right now. <a href="<?= url('create-group.php') ?>">Start the first one!</a></div>
        <?php endif; ?>
    </div>
</section>

<section class="section" id="trust">
    <div class="container split-section">
        <div class="trust-visual">
            <div class="trust-circle">
                <span class="badge"><?= icon('circle-check', 'icon-sm') ?> Email verified</span>
                <?= icon('shield-check', 'icon-xl') ?>
            </div>
        </div>
        <div>
            <h2 class="section-title">Travel with Trust</h2>
            <p class="section-text">Every Chol Ghuri account is email-verified and linked to a university. After each trip, members rate each other, so you can see who is reliable before you join. Costs, bookings and settlements are visible to the whole group.</p>
            <ul class="benefits">
                <li><span class="tick"><?= icon('check') ?></span> Verified student accounts</li>
                <li><span class="tick"><?= icon('check') ?></span> Ratings from real trips</li>
                <li><span class="tick"><?= icon('check') ?></span> Transparent shared costs</li>
            </ul>
        </div>
    </div>
</section>

<?php if ($featured): ?>
<section class="section section-alt">
    <div class="container">
        <div class="section-head">
            <div>
                <h2 class="section-title">Featured Destinations</h2>
                <p class="mb-0">Explore the diverse landscapes of Bengal.</p>
            </div>
            <a href="<?= url('destinations.php') ?>" class="link-accent">View all <?= icon('arrow-right', 'icon-sm') ?></a>
        </div>
        <div class="dest-mosaic">
            <?php foreach ($featured as $i => $d): ?>
                <a class="dest-tile <?= $i === 0 ? 'is-big' : '' ?>" href="<?= url('destination.php?id=' . $d['id']) ?>"
                   style="background-image:url('<?= image_url($d['image']) ?>')">
                    <span class="badge badge-glass"><?= e($d['label']) ?></span>
                    <strong><?= e($d['name']) ?></strong>
                    <?php if ($i === 0): ?><small><?= e($d['description']) ?></small><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="cta-band">
    <div class="container text-center">
        <h2>Travel Together. Spend Less.</h2>
        <p>Join <?= $studentCount ?> email-verified members exploring Bangladesh on a budget.</p>
        <a class="btn btn-accent btn-lg" href="<?= url(is_logged_in() ? 'create-group.php' : 'register.php') ?>">Start a Trip Now</a>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
