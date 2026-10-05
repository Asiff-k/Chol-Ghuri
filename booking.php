<?php
/**
 * booking.php?group=1 - Booking payment (DEMO / SANDBOX)
 *
 * Flow:  choose method  ->  payment-gateway.php (fake SSLCommerz sandbox)
 *        ->  back here with ?ref=...  ->  success or failure screen.
 * The amount is ALWAYS calculated on the server (per-person price for the
 * group's current member count). No real money moves.
 */
require_once __DIR__ . '/includes/bootstrap.php';
$me = require_login();

$groupId = int_param($_GET, 'group');
$group   = get_group($groupId);
if (!$group || !is_group_member($groupId, (int) $me['id'])) {
    show_error_page('Not available', 'You can only pay for groups you are a member of.', 403, 'my-trips.php', 'My Trips');
}

$error = '';
if (is_post()) {
    verify_csrf();
    [$error, $ref] = start_booking($group, $me, $_POST['method'] ?? '');
    if (!$error) {
        redirect('payment-gateway.php?ref=' . urlencode($ref));
    }
}

$amount  = booking_amount($group);
$booking = find_booking($groupId, (int) $me['id']);
$problem = booking_problem($group, $me);
$showResult = isset($_GET['ref']) && $booking && hash_equals($booking['transaction_ref'], (string) $_GET['ref']);

$pageTitle = 'Booking Payment';
$activeNav = 'my-trips.php';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <a class="back-link" href="<?= url('group.php?id=' . $groupId) ?>"><?= icon('arrow-left', 'icon-sm') ?> Back to Group</a>
    <div class="page-head page-head-row">
        <div>
            <h1>Booking Payment</h1>
            <p class="meta"><?= icon('mountain', 'icon-sm') ?> <?= e($group['title']) ?> &middot; <?= e($group['destination_name']) ?> &middot; <?= e(date_range($group['start_date'], $group['end_date'])) ?></p>
        </div>
        <span class="badge badge-accent"><?= icon('shield', 'icon-sm') ?> Sandbox / Demo</span>
    </div>

    <?php if ($error): ?><?= alert('error', $error) ?><?php endif; ?>

    <?php if ($showResult && $booking['status'] === 'paid'): ?>
        <section class="result-card">
            <span class="icon-circle icon-circle-lg" style="background:var(--primary-tint)"><?= icon('check') ?></span>
            <h2>Payment Successful</h2>
            <p class="text-primary"><?= icon('check-check', 'icon-sm') ?> Booking confirmed</p>
            <div class="result-amount"><?= money($booking['amount']) ?></div>
            <dl class="receipt">
                <div><dt>Paid by</dt><dd><?= e($me['full_name']) ?></dd></div>
                <div><dt>Trip</dt><dd><?= e($group['title']) ?></dd></div>
                <div><dt>Method</dt><dd><?= e(PAYMENT_METHOD_LABELS[$booking['payment_method']]) ?> (demo)</dd></div>
                <div><dt>Transaction ID</dt><dd><?= e($booking['transaction_ref']) ?></dd></div>
                <div><dt>Status</dt><dd><span class="badge">PAID</span></dd></div>
            </dl>
            <a class="btn btn-primary btn-block" href="<?= url('group.php?id=' . $groupId) ?>">Back to Trip</a>
        </section>

    <?php elseif ($showResult && $booking['status'] === 'failed'): ?>
        <section class="result-card is-failed">
            <span class="icon-circle icon-circle-lg accent"><?= icon('circle-x') ?></span>
            <h2>Payment Failed</h2>
            <p>The sandbox reported a failed payment. No money was taken. You can try again.</p>
            <dl class="receipt">
                <div><dt>Transaction ID</dt><dd><?= e($booking['transaction_ref']) ?></dd></div>
                <div><dt>Status</dt><dd><span class="badge badge-accent">FAILED</span></dd></div>
            </dl>
            <a class="btn btn-primary btn-block" href="<?= url('booking.php?group=' . $groupId) ?>">Try Again</a>
        </section>

    <?php elseif ($problem): ?>
        <section class="center-card narrow">
            <span class="icon-circle icon-circle-lg <?= $booking && $booking['status'] === 'paid' ? '' : 'accent' ?>"><?= icon($booking && $booking['status'] === 'paid' ? 'circle-check' : 'clock') ?></span>
            <h1><?= $booking && $booking['status'] === 'paid' ? 'Already paid' : 'Booking not open' ?></h1>
            <p class="lead"><?= e($problem) ?></p>
            <?php if ($booking && $booking['status'] === 'paid'): ?><p>Transaction ID: <code><?= e($booking['transaction_ref']) ?></code></p><?php endif; ?>
            <a class="btn btn-primary" href="<?= url('group.php?id=' . $groupId) ?>">Back to Group</a>
        </section>

    <?php else: ?>
        <div class="layout-sidebar">
            <form class="card" method="post">
                <?= csrf_field() ?>
                <div class="section-head">
                    <h2 class="card-section-title mb-0">Choose Payment Method</h2>
                    <span class="badge badge-grey"><?= icon('shield', 'icon-sm') ?> Sandbox Environment</span>
                </div>
                <p>You are paying your share of the package. This is a <strong>demo</strong> of an SSLCommerz-style sandbox: no real bKash, Nagad or card transaction happens.</p>
                <div class="method-list">
                    <?php foreach (['bkash' => ['bKash', 'Mobile Financial Service', 'smartphone'], 'nagad' => ['Nagad', 'Mobile Financial Service', 'smartphone'], 'card' => ['Card', 'Visa / Mastercard', 'credit-card']] as $key => [$label, $sub, $ic]): ?>
                        <label class="method">
                            <input type="radio" name="method" value="<?= $key ?>" <?= $key === 'bkash' ? 'checked' : '' ?>>
                            <span class="method-box"><span class="icon-circle accent"><?= icon($ic) ?></span><span><strong><?= $label ?></strong><small><?= $sub ?></small></span></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="form-actions">
                    <a class="btn btn-light" href="<?= url('group.php?id=' . $groupId) ?>">Cancel</a>
                    <button class="btn btn-primary" type="submit">Continue to Payment <?= icon('arrow-right', 'icon-sm') ?></button>
                </div>
            </form>

            <aside>
                <section class="summary-green">
                    <span class="section-label">Payment summary</span>
                    <strong class="summary-amount"><?= money($amount) ?></strong>
                    <dl>
                        <div><dt>Type</dt><dd>Package booking</dd></div>
                        <div><dt>Package</dt><dd><?= e($group['package_title']) ?></dd></div>
                        <div><dt>Members</dt><dd><?= (int) $group['member_count'] ?> (price per person)</dd></div>
                    </dl>
                </section>
                <section class="card secure-list">
                    <h3><?= icon('shield-check', 'text-primary') ?> Secure &amp; Transparent</h3>
                    <ul>
                        <li>Sandbox gateway for safe testing, no real money.</li>
                        <li>Amount calculated on the server from the package costs.</li>
                        <li>Every member pays the same per-person price.</li>
                    </ul>
                </section>
            </aside>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
