<?php
/**
 * group.php?id=1 - Group Room (design: "Sajek Valley Weekend Escape - Group Room")
 *
 * POST actions (all validated in includes/groups.php):
 *   join      - become a member (budget from the trip intent or profile)
 *   leave     - leave before the group is confirmed
 *   confirm   - organiser confirms once the minimum headcount is reached (opens booking)
 *   complete  - organiser completes the trip once everyone paid and settled
 */
require_once __DIR__ . '/includes/bootstrap.php';

$id    = int_param($_GET, 'id');
$group = get_group($id);
if (!$group) {
    show_error_page('Group not found', 'This group does not exist or was removed.', 404, 'groups.php', 'Find groups');
}
$me = current_user();

// ---------------- Actions ----------------
if (is_post()) {
    $me = require_login();
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $error  = '';

    if ($action === 'join') {
        $intent = null;
        if ($intentId = int_param($_POST, 'intent')) {
            $intent = find_trip_intent($intentId, (int) $me['id']);
        }
        $budget = $intent ? (float) $intent['budget_max'] : ($me['budget_max'] ? (float) $me['budget_max'] : null);

        // Save the match score if the student came from a search
        $score = null;
        if ($intent) {
            foreach (match_groups($intent, $me)['matches'] as $m) {
                if ((int) $m['group']['id'] === $id) $score = $m['score'];
            }
        }
        $error = join_group($id, $me, $budget, $score);
        if ($error && !$intent && str_contains($error, 'budget')) {
            $error .= ' (This is the budget in your profile. Search with a higher budget on Find Groups, or update your profile.)';
        }
        if (!$error) set_flash('success', 'You joined the group! The price per person has been recalculated for everyone.');

    } elseif ($action === 'leave') {
        $error = leave_group($id, $me);
        if (!$error) {
            set_flash('success', 'You left the group. The price was recalculated for the remaining members.');
            redirect('my-trips.php');
        }

    } elseif ($action === 'confirm') {
        $error = confirm_group($group, (int) $me['id']);
        if (!$error) set_flash('success', 'Group confirmed! Members can now pay their booking (demo payment).');

    } elseif ($action === 'complete') {
        $error = complete_group($group, (int) $me['id']);
        if (!$error) set_flash('success', 'Trip completed. Members can now rate each other.');

    } else {
        $error = 'Unknown action.';
    }

    if ($error) set_flash('error', $error);
    redirect('group.php?id=' . $id);
}

// ---------------- Data for the page ----------------
$members   = group_members_list($id);
$tags      = group_tags($id);
$count     = (int) $group['member_count'];
$max       = (int) $group['max_members'];
$min       = (int) $group['min_members'];
$packageId = (int) $group['package_id'];
$priceNow  = per_person_price($packageId, max(1, $count));
$priceFull = per_person_price($packageId, $max);
$timeline  = price_timeline($packageId, 2, $max);
$isMember  = $me && is_group_member($id, (int) $me['id']);
$isOrganizer = $me && is_organizer($group, (int) $me['id']);
$open      = in_array($group['status'], ['forming', 'full'], true);

// Cost breakdown at the current size: shared items are divided by members
$breakdown = [];
foreach (package_cost_items($packageId) as $item) {
    $cat = $item['category'];
    $perHead = $item['cost_type'] === 'shared' ? $item['amount'] / max(1, $count) : $item['amount'];
    $breakdown[$cat] = ($breakdown[$cat] ?? 0) + $perHead;
}
$totals = package_cost_totals($packageId);
$groupTotal = $totals['shared'] + $totals['per_person'] * max(1, $count);

// Join button state
$joinProblem = '';
$intentId = int_param($_GET, 'intent');
if ($me && !$isMember) {
    $intent = $intentId ? find_trip_intent($intentId, (int) $me['id']) : null;
    $budget = $intent ? (float) $intent['budget_max'] : ($me['budget_max'] ? (float) $me['budget_max'] : null);
    $joinProblem = join_problem($group, $me, $budget);
}

// Booking info for confirmed / completed groups
$myBooking = $me ? find_booking($id, (int) $me['id']) : null;
$unpaid    = in_array($group['status'], ['confirmed', 'completed'], true) ? unpaid_members($id) : [];
$completeProblem = $isOrganizer && $group['status'] === 'confirmed' ? complete_problem($group, (int) $me['id']) : '';
$confirmProblem  = $isOrganizer && $open ? confirm_problem($group, (int) $me['id']) : '';
$amenities = array_filter(explode(',', (string) $group['amenities']));

$pageTitle = $group['title'];
$activeNav = 'groups.php';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container" data-group-live="<?= $id ?>">
    <div class="room-badges">
        <span class="badge <?= $open ? 'badge-grey' : '' ?>"><span class="dot"></span> <?= e(ucfirst($group['status'])) ?> &middot; <span data-live="count"><?= $count ?></span> of <?= $max ?> students</span>
        <span class="badge"><?= icon('circle-check', 'icon-sm') ?> Email-verified members only</span>
        <?php if ($group['university_only']): ?><span class="badge badge-accent"><?= icon('graduation-cap', 'icon-sm') ?> <?= e($group['organizer_uni']) ?> students only</span><?php endif; ?>
        <?php if ($group['gender_rule'] !== 'any'): ?><span class="badge badge-accent"><?= $group['gender_rule'] === 'female_only' ? 'Women only' : 'Men only' ?></span><?php endif; ?>
    </div>
    <h1 class="room-title"><?= e($group['title']) ?></h1>
    <p class="meta room-meta">
        <span><?= icon('map-pin', 'icon-sm') ?> <a href="<?= url('destination.php?id=' . $group['destination_id']) ?>"><?= e($group['destination_name']) ?></a></span>
        <span><?= icon('calendar', 'icon-sm') ?> <?= e(date_range($group['start_date'], $group['end_date'])) ?></span>
        <span><?= icon('tent', 'icon-sm') ?> <?= e(TRIP_TYPE_LABELS[$group['trip_type']]) ?></span>
    </p>

    <?= render_flash() ?>

    <div class="layout-sidebar">
        <div class="room-main">

            <!-- Price timeline -->
            <section class="card">
                <div class="timeline-head">
                    <div>
                        <h2 class="card-section-title mb-0">Your group gets cheaper as it grows</h2>
                        <p class="text-small mb-0">
                            <?php if ($open && $count < $max): ?>
                                <?= $max - $count ?> more <?= $max - $count === 1 ? 'student' : 'students' ?> needed to unlock the lowest group rate.
                            <?php else: ?>
                                Price is fixed at <?= $count ?> members.
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="text-right">
                        <small>Current price</small>
                        <strong class="price-lg text-accent"><span data-live="price"><?= money($priceNow) ?></span><small>/person</small></strong>
                    </div>
                </div>
                <div class="price-timeline">
                    <?php foreach ($timeline as $n => $price): ?>
                        <div class="tl-step <?= $n < $count ? 'is-done' : '' ?> <?= $n === $count ? 'is-current' : '' ?>">
                            <?php if ($n === $count): ?><span class="tl-flag">Current</span><?php endif; ?>
                            <span class="tl-dot"></span>
                            <span class="tl-label"><?= $n ?> mem</span>
                            <strong><?= money($price) ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- Members -->
            <section class="card">
                <div class="section-head">
                    <div><h2 class="card-section-title mb-0">Who's going?</h2><?php if ($isMember): ?><small class="text-muted">Phone numbers are visible to group members only.</small><?php endif; ?></div>
                    <span class="badge"><span data-live="count"><?= $count ?></span> / <?= $max ?> Joined</span>
                </div>
                <div class="member-grid">
                    <?php foreach ($members as $m): ?>
                        <a class="member" href="<?= url('profile.php?id=' . $m['id']) ?>">
                            <?= avatar($m['profile_photo'], $m['full_name'], 'md') ?>
                            <div>
                                <strong><?= e($m['full_name']) ?> <?php if ($m['role'] === 'organizer'): ?><small class="text-muted">(Organizer)</small><?php endif; ?></strong>
                                <small><?= e($m['uni'] ?? '') ?></small>
                                <small><?= $m['rating'] ? icon('star', 'icon-sm text-accent') . ' ' . e($m['rating']) . ' &bull; ' : '' ?><?= (int) $m['trips'] ?> <?= (int) $m['trips'] === 1 ? 'trip' : 'trips' ?></small>
                                <?php if ($isMember && $m['phone']): // phone numbers are only shown to fellow members, for trip coordination ?>
                                    <small><?= icon('smartphone', 'icon-sm') ?> <?= e($m['phone']) ?></small>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                    <?php for ($i = $count; $i < $max && $open; $i++): ?>
                        <div class="member member-empty"><span class="icon-circle"><?= icon('plus') ?></span> Open spot</div>
                    <?php endfor; ?>
                </div>
            </section>

            <!-- Booking status (confirmed / completed) -->
            <?php if (!$open && $group['status'] !== 'cancelled'): ?>
                <section class="card">
                    <h2 class="card-section-title">Booking Payments <span class="badge badge-accent">Demo</span></h2>
                    <p class="text-small">Each member pays <?= money($priceNow) ?> (price for <?= $count ?> members) through the demo SSLCommerz-style sandbox.</p>
                    <div class="pay-list">
                        <?php foreach ($members as $m): $b = find_booking($id, (int) $m['id']); $paid = $b && $b['status'] === 'paid'; ?>
                            <div class="pay-row">
                                <span><?= avatar($m['profile_photo'], $m['full_name'], 'sm') ?> <?= e($m['full_name']) ?></span>
                                <?= $paid ? '<span class="badge">' . icon('check', 'icon-sm') . ' Paid</span>' : '<span class="badge badge-accent">Not paid</span>' ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <div class="two-col">
                <!-- Logistics -->
                <section class="card">
                    <h2 class="card-section-title">Trip Logistics</h2>
                    <ul class="logistics">
                        <li><?= icon('map') ?><div><strong>Destination</strong><span><?= e($group['destination_name']) ?></span></div></li>
                        <li><?= icon('calendar') ?><div><strong>Dates</strong><span><?= e(date_range($group['start_date'], $group['end_date'])) ?> (<?= days_between($group['start_date'], $group['end_date']) ?> days)</span></div></li>
                        <li><?= icon('users') ?><div><strong>Target</strong><span><?= $max ?> students (minimum <?= $min ?>)</span></div></li>
                        <li><?= icon('wallet') ?><div><strong>Budget</strong><span><?= $group['budget_max'] ? money($group['budget_max']) . '/person max' : 'Not set' ?></span></div></li>
                        <li><?= icon('bus') ?><div><strong>Transport</strong><span><?= e($group['transport_info'] ?: 'Arranged by the group') ?></span></div></li>
                        <?php if ($tags): ?>
                            <li><?= icon('tag') ?><div><strong>Style</strong><span class="tag-list"><?php foreach ($tags as $t): ?><span class="tag tag-grey"><?= e($t['name']) ?></span><?php endforeach; ?></span></div></li>
                        <?php endif; ?>
                    </ul>
                </section>

                <!-- Accommodation / package -->
                <section class="card stay-card">
                    <div class="stay-img" style="background-image:url('<?= image_url($group['package_image']) ?>')">
                    </div>
                    <div class="stay-body">
                        <span class="section-label text-primary">Package</span>
                        <h3><a href="<?= url('package.php?id=' . $packageId) ?>"><?= e($group['package_title']) ?></a></h3>
                        <p class="text-small"><?= e($group['accommodation_name'] ?: 'Day trip, no overnight stay') ?></p>
                        <?php if ($amenities): ?>
                            <div class="tag-list"><?php foreach ($amenities as $a): ?><span class="tag tag-grey"><?= e(AMENITY_LABELS[$a]['label'] ?? $a) ?></span><?php endforeach; ?></div>
                        <?php endif; ?>
                        <div class="private-vs-group">
                            <span>Alone: <s><?= money(per_person_price($packageId, 1)) ?></s></span>
                            <strong>Group: <span data-live="price"><?= money($priceNow) ?></span></strong>
                        </div>
                    </div>
                </section>
            </div>

            <!-- Cost breakdown -->
            <section class="card">
                <h2 class="card-section-title">Estimated Cost Breakdown</h2>
                <p class="text-small">Per person, with the current <?= $count ?> <?= $count === 1 ? 'member' : 'members' ?>.</p>
                <div class="cost-rows">
                    <?php foreach ($breakdown as $cat => $amount): ?>
                        <div class="cost-row"><span><span class="icon-circle"><?= icon(CATEGORY_LABELS[$cat]['icon'], 'icon-sm') ?></span> <?= e(CATEGORY_LABELS[$cat]['label']) ?></span><strong><?= money($amount) ?></strong></div>
                    <?php endforeach; ?>
                </div>
                <div class="cost-total">
                    <div><small>Total group estimate</small><strong><?= money($groupTotal) ?></strong></div>
                    <div class="text-right"><small>Current per person</small><strong class="text-primary" data-live="price"><?= money($priceNow) ?></strong></div>
                </div>
            </section>

            <?php if ($group['description']): ?>
                <section class="card">
                    <h2 class="card-section-title">Trip Details</h2>
                    <p class="mb-0"><?= nl2br(e($group['description'])) ?></p>
                </section>
            <?php endif; ?>

            <!-- Rules -->
            <section class="card">
                <h2 class="card-section-title">Group Rules</h2>
                <div class="rules">
                    <div><?= icon('shield-check', 'text-accent') ?><div><strong>Verified accounts only</strong><small>Only accounts with a verified email can log in and join.</small></div></div>
                    <div><?= icon('users', 'text-accent') ?><div><strong>Minimum <?= $min ?> to go</strong><small>The organiser can confirm the trip once <?= $min ?> students have joined. Booking opens after that.</small></div></div>
                    <div><?= icon('lock', 'text-accent') ?><div><strong>Leaving</strong><small>Members can leave freely while the group is forming. After confirmation the group is locked.</small></div></div>
                    <div><?= icon('scale', 'text-accent') ?><div><strong>Shared visibility</strong><small>All costs, expenses and settlements are visible to every member.</small></div></div>
                </div>
            </section>
        </div>

        <!-- Sidebar -->
        <aside class="room-side">
            <section class="card">
                <?php if ($open && $count < $max): ?>
                    <div class="text-center"><span class="badge badge-accent"><span data-live="spots"><?= $max - $count ?></span> spots remaining</span></div>
                <?php endif; ?>
                <div class="progress-label" style="margin-top:14px"><span>Group fill</span><strong class="text-primary"><span data-live="count"><?= $count ?></span> / <?= $max ?></strong></div>
                <div class="progress"><span data-live="fill" style="width:<?= round(100 * $count / $max) ?>%"></span></div>
                <p class="headcount <?= $count >= $min ? 'ok' : '' ?>">
                    <?= icon($count >= $min ? 'circle-check' : 'clock', 'icon-sm') ?>
                    <?= $count >= $min ? "Minimum headcount reached ($min)" : 'Minimum headcount: ' . $min . ' (' . ($min - $count) . ' more needed)' ?>
                </p>

                <div class="price-box">
                    <span>Current price</span><strong data-live="price"><?= money($priceNow) ?></strong>
                </div>
                <div class="price-goal"><span>Goal price (<?= $max ?> members)</span><strong><?= money($priceFull) ?>/p</strong></div>
                <?php if ($open && $priceNow > $priceFull): ?>
                    <div class="saving-note"><?= icon('piggy-bank', 'icon-sm') ?> You save <?= money($priceNow - $priceFull) ?>/person when the group reaches <?= $max ?>.</div>
                <?php endif; ?>

                <div class="side-actions">
                <?php if (!$me): ?>
                    <a class="btn btn-primary btn-block" href="<?= url('login.php') ?>">Log in to join</a>

                <?php elseif ($group['status'] === 'cancelled'): ?>
                    <?= alert('info', 'This group was cancelled.') ?>

                <?php elseif (!$isMember): ?>
                    <?php if ($joinProblem): ?>
                        <div class="join-blocked"><?= icon('circle-alert', 'icon-sm') ?> <?= e($joinProblem) ?></div>
                    <?php else: ?>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="join">
                            <?php if ($intentId): ?><input type="hidden" name="intent" value="<?= $intentId ?>"><?php endif; ?>
                            <button class="btn btn-primary btn-block" type="submit">Join This Group &middot; <?= money(per_person_price($packageId, $count + 1)) ?>/p</button>
                        </form>
                        <p class="text-small text-center">Price after you join: <?= money(per_person_price($packageId, $count + 1)) ?> per person for everyone.</p>
                    <?php endif; ?>

                <?php elseif ($open): ?>
                    <?php if ($isOrganizer): ?>
                        <?php if ($confirmProblem): ?>
                            <div class="join-blocked"><?= icon('clock', 'icon-sm') ?> <?= e($confirmProblem) ?></div>
                        <?php else: ?>
                            <form method="post" data-confirm="Confirm the group with <?= $count ?> members? The price will be fixed at <?= money($priceNow) ?> per person and nobody can join or leave afterwards.">
                                <?= csrf_field() ?><input type="hidden" name="action" value="confirm">
                                <button class="btn btn-primary btn-block" type="submit"><?= icon('circle-check', 'icon-sm') ?> Confirm Group &amp; Open Booking</button>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="you-are-in"><?= icon('circle-check', 'icon-sm') ?> You're in this group. Waiting for the organiser to confirm.</div>
                    <?php endif; ?>
                    <form method="post" data-confirm="Leave this group?<?= $isOrganizer && $count > 1 ? ' The next member will become organiser.' : '' ?>">
                        <?= csrf_field() ?><input type="hidden" name="action" value="leave">
                        <button class="btn btn-light btn-block" type="submit"><?= icon('user-minus', 'icon-sm') ?> Leave Group</button>
                    </form>

                <?php elseif ($group['status'] === 'confirmed'): ?>
                    <?php if (!$myBooking || $myBooking['status'] !== 'paid'): ?>
                        <a class="btn btn-accent btn-block" href="<?= url('booking.php?group=' . $id) ?>"><?= icon('credit-card', 'icon-sm') ?> Pay Booking &middot; <?= money($priceNow) ?></a>
                    <?php else: ?>
                        <div class="you-are-in"><?= icon('circle-check', 'icon-sm') ?> Booking paid &middot; <?= e($myBooking['transaction_ref']) ?></div>
                    <?php endif; ?>
                    <a class="btn btn-primary btn-block" href="<?= url('expenses.php?group=' . $id) ?>"><?= icon('receipt', 'icon-sm') ?> Trip Expenses</a>
                    <a class="btn btn-outline btn-block" href="<?= url('settle.php?group=' . $id) ?>"><?= icon('hand-coins', 'icon-sm') ?> Settle Balance</a>
                    <?php if ($isOrganizer): ?>
                        <?php if ($completeProblem): ?>
                            <div class="join-blocked"><?= icon('clock', 'icon-sm') ?> To complete: <?= e($completeProblem) ?></div>
                        <?php else: ?>
                            <form method="post" data-confirm="Mark this trip as completed? Expenses and settlements will be locked and members can rate each other.">
                                <?= csrf_field() ?><input type="hidden" name="action" value="complete">
                                <button class="btn btn-light btn-block" type="submit"><?= icon('flag', 'icon-sm') ?> Mark Trip Completed</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>

                <?php elseif ($group['status'] === 'completed'): ?>
                    <a class="btn btn-accent btn-block" href="<?= url('rate.php?group=' . $id) ?>"><?= icon('star', 'icon-sm') ?> Rate Your Group</a>
                    <a class="btn btn-outline btn-block" href="<?= url('expenses.php?group=' . $id) ?>"><?= icon('receipt', 'icon-sm') ?> View Expenses</a>
                <?php endif; ?>
                </div>

                <div class="organized-by">
                    <span class="section-label">Organized by</span>
                    <a class="member" href="<?= url('profile.php?id=' . $group['creator_id']) ?>">
                        <?= avatar($group['organizer_photo'], $group['organizer_name'], 'sm') ?>
                        <div><strong><?= e($group['organizer_name']) ?></strong><small><?= e($group['organizer_university'] ?? '') ?></small></div>
                    </a>
                </div>
            </section>
            <p class="text-small text-center text-muted"><?= icon('refresh-cw', 'icon-sm') ?> Member count and price update automatically.</p>
        </aside>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
