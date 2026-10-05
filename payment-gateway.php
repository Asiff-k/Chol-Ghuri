<?php
/**
 * payment-gateway.php?ref=CG-DEMO-XXXX
 * ------------------------------------
 * A FAKE payment gateway page that imitates an SSLCommerz sandbox checkout.
 * It never talks to a real payment provider. The student chooses
 * "Simulate success" or "Simulate failure"; the result is then validated
 * on the server (finish_booking) using only the reference and the database.
 */
require_once __DIR__ . '/includes/bootstrap.php';
$me = require_login();

$ref     = (string) ($_POST['ref'] ?? $_GET['ref'] ?? '');
$booking = find_booking_by_ref($ref);
if (!$booking || (int) $booking['user_id'] !== (int) $me['id']) {
    show_error_page('Payment not found', 'This payment reference is not valid.', 404, 'my-trips.php', 'My Trips');
}
$group = get_group((int) $booking['group_id']);

if (is_post()) {
    verify_csrf();
    $result = $_POST['result'] ?? '';
    if ($result === 'cancel') {
        set_flash('info', 'Payment cancelled. You can pay later.');
        redirect('group.php?id=' . $booking['group_id']);
    }
    [$error] = finish_booking($ref, $me, $result === 'success');
    if ($error) {
        set_flash('error', $error);
    }
    redirect('booking.php?group=' . $booking['group_id'] . '&ref=' . urlencode($ref));
}

if ($booking['status'] !== 'pending') {
    redirect('booking.php?group=' . $booking['group_id'] . '&ref=' . urlencode($ref));
}

$pageTitle = 'Demo Payment Gateway';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <section class="gateway">
        <div class="gateway-head">
            <strong>SSLCommerz <span>Sandbox</span></strong>
            <span class="demo-box-label"><?= icon('info', 'icon-sm') ?> Demo Mode</span>
        </div>
        <div class="gateway-body">
            <p class="text-small">This page <strong>imitates</strong> a payment gateway for the final-year project demo. No real <?= e(PAYMENT_METHOD_LABELS[$booking['payment_method']]) ?> account is charged.</p>
            <dl class="receipt">
                <div><dt>Merchant</dt><dd>Chol Ghuri</dd></div>
                <div><dt>For</dt><dd><?= e($group['title'] ?? '') ?></dd></div>
                <div><dt>Method</dt><dd><?= e(PAYMENT_METHOD_LABELS[$booking['payment_method']]) ?></dd></div>
                <div><dt>Transaction ID</dt><dd><?= e($booking['transaction_ref']) ?></dd></div>
            </dl>
            <div class="result-amount"><?= money($booking['amount']) ?></div>
            <form method="post" class="gateway-actions">
                <?= csrf_field() ?>
                <input type="hidden" name="ref" value="<?= e($ref) ?>">
                <button class="btn btn-primary btn-lg btn-block" name="result" value="success" type="submit"><?= icon('check', 'icon-sm') ?> Pay <?= money($booking['amount']) ?> (simulate success)</button>
                <button class="btn btn-outline-accent btn-block" name="result" value="fail" type="submit"><?= icon('circle-x', 'icon-sm') ?> Simulate failure</button>
                <button class="btn btn-light btn-block" name="result" value="cancel" type="submit">Cancel</button>
            </form>
        </div>
    </section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
