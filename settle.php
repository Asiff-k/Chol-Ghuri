<?php
/**
 * settle.php?group=7 - Settle Your Balance (design: "Settle Your Balance - ...")
 *
 * Student-to-student settlement. This is NOT a gateway payment:
 *   payer pays outside the app (bKash / Nagad / card / cash) and clicks "Mark as Paid"
 *   receiver checks and clicks "Confirm Received" (or "Not received")
 * Only after the receiver confirms is a transfer completed.
 */
require_once __DIR__ . '/includes/bootstrap.php';
$me = require_login();

$groupId = int_param($_GET, 'group');
$group   = get_group($groupId);
if (!$group || (!is_group_member($groupId, (int) $me['id']) && !is_admin())) {
    show_error_page('Not available', 'Only members of this group can see its settlements.', 403, 'my-trips.php', 'My Trips');
}

if (is_post()) {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $sid    = int_param($_POST, 'settlement_id');
    $error  = match ($action) {
        'mark_paid' => mark_settlement_paid($group, $me, $sid, $_POST['method'] ?? ''),
        'confirm'   => answer_settlement($group, $me, $sid, true),
        'reject'    => answer_settlement($group, $me, $sid, false),
        default     => 'Unknown action.',
    };
    $done = ['mark_paid' => 'Marked as paid. The receiver now needs to confirm.', 'confirm' => 'Confirmed. This transfer is complete.', 'reject' => 'Marked as not received. The transfer is pending again.'];
    set_flash($error ? 'error' : 'success', $error ?: ($done[$action] ?? 'Done.'));
    redirect('settle.php?group=' . $groupId);
}

$balances  = group_balances($groupId);
$mine      = $balances[(int) $me['id']] ?? ['paid' => 0, 'owed' => 0, 'net' => 0, 'remaining' => 0];
$transfers = group_settlements($groupId);
$myOut = array_filter($transfers, fn($t) => (int) $t['from_user_id'] === (int) $me['id']);
$myIn  = array_filter($transfers, fn($t) => (int) $t['to_user_id'] === (int) $me['id']);
$totalExpenses = array_sum(array_column($balances, 'paid'));
$confirmed = count(array_filter($transfers, fn($t) => $t['status'] === 'confirmed'));
$pendingAmount = array_sum(array_map(fn($t) => $t['status'] === 'confirmed' ? 0 : $t['amount'], $transfers));
$canAct = $group['status'] === 'confirmed';

// What is still open for me (until the RECEIVER confirms, a transfer is not finished)
$openIn  = array_sum(array_map(fn($t) => $t['status'] === 'confirmed' ? 0 : $t['amount'], $myIn));
$openOut = array_sum(array_map(fn($t) => $t['status'] === 'confirmed' ? 0 : $t['amount'], $myOut));
$myOpen  = $openIn - $openOut;

$pageTitle = 'Settle Your Balance';
$activeNav = 'my-trips.php';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <a class="back-link" href="<?= url('expenses.php?group=' . $groupId) ?>"><?= icon('arrow-left', 'icon-sm') ?> Back to Trip Expenses</a>
    <div class="page-head page-head-row">
        <div>
            <h1>Settle Your Balance</h1>
            <p class="meta"><?= icon('mountain', 'icon-sm') ?> <?= e($group['title']) ?> &middot; <?= e($group['destination_name']) ?> &middot; <?= e(date_range($group['start_date'], $group['end_date'])) ?></p>
        </div>
        <?php if ($pendingAmount > 0): ?>
            <span class="badge badge-accent"><?= icon('clock', 'icon-sm') ?> Payment pending</span>
        <?php else: ?>
            <span class="badge"><?= icon('circle-check', 'icon-sm') ?> All settled</span>
        <?php endif; ?>
    </div>

    <?= render_flash() ?>
    <?php if (!$canAct && $group['status'] !== 'completed'): ?><?= alert('info', 'Settlements open once the group is confirmed and expenses are added.') ?><?php endif; ?>

    <div class="layout-sidebar">
        <div>
            <section class="card">
                <h2 class="card-section-title">Your Settlement</h2>
                <div class="stat-grid stat-grid-3">
                    <div class="stat"><span>You paid</span><strong><?= money($mine['paid']) ?></strong></div>
                    <div class="stat"><span>Your fair share</span><strong><?= money($mine['owed']) ?></strong></div>
                    <div class="stat stat-highlight <?= $mine['net'] < 0 ? 'is-negative' : '' ?>"><span>Balance</span><strong><?= $mine['net'] > 0 ? '+' : '' ?><?= money($mine['net']) ?></strong></div>
                </div>

                <?php if (!$myOut && !$myIn): ?>
                    <div class="empty-state" style="margin-top:20px"><?= icon('circle-check', 'text-primary') ?> You have nothing to pay or receive in this trip.</div>
                <?php endif; ?>

                <?php foreach ($myOut as $t): ?>
                    <div class="settle-item">
                        <p class="settle-headline">You owe <strong class="text-accent"><?= money($t['amount']) ?></strong> to <strong><?= e($t['to_name']) ?></strong>.</p>
                        <div class="settle-flow">
                            <div><?= avatar($t['from_photo'], $t['from_name'], 'lg') ?><strong>You</strong><span class="badge badge-grey">Pays <?= money($t['amount']) ?></span></div>
                            <div class="settle-arrow"><?= icon('banknote') ?><span></span></div>
                            <div><?= avatar($t['to_photo'], $t['to_name'], 'lg') ?><strong class="text-primary"><?= e($t['to_name']) ?></strong><span class="badge">Receives</span></div>
                        </div>
                        <?php if ($t['status'] === 'pending' && $canAct): ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="mark_paid">
                                <input type="hidden" name="settlement_id" value="<?= $t['id'] ?>">
                                <p class="text-small">Pay <?= e($t['to_name']) ?> directly (bKash, Nagad, card or cash), then tell us how you paid. Chol Ghuri records it; no money moves through the app.</p>
                                <div class="method-list method-list-row">
                                    <?php foreach (PAYMENT_METHOD_LABELS as $key => $label): ?>
                                        <label class="method"><input type="radio" name="method" value="<?= $key ?>" <?= $key === 'bkash' ? 'checked' : '' ?>><span class="method-box"><span class="icon-circle accent"><?= icon($key === 'card' ? 'credit-card' : ($key === 'cash' ? 'banknote' : 'smartphone')) ?></span><strong><?= $label ?></strong></span></label>
                                    <?php endforeach; ?>
                                </div>
                                <button class="btn btn-primary" type="submit"><?= icon('check', 'icon-sm') ?> Mark as Paid</button>
                            </form>
                        <?php elseif ($t['status'] === 'paid'): ?>
                            <p class="settle-state"><?= icon('clock', 'icon-sm') ?> You marked this as paid via <?= e(PAYMENT_METHOD_LABELS[$t['payment_method']] ?? '') ?>. Waiting for <?= e($t['to_name']) ?> to confirm.</p>
                        <?php elseif ($t['status'] === 'confirmed'): ?>
                            <p class="settle-state ok"><?= icon('circle-check', 'icon-sm') ?> Completed. <?= e($t['to_name']) ?> confirmed receiving it.</p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <?php foreach ($myIn as $t): ?>
                    <div class="settle-item">
                        <p class="settle-headline"><strong><?= e($t['from_name']) ?></strong> owes you <strong class="text-primary"><?= money($t['amount']) ?></strong>.</p>
                        <?php if ($t['status'] === 'pending'): ?>
                            <p class="settle-state"><?= icon('clock', 'icon-sm') ?> Waiting for <?= e($t['from_name']) ?> to pay and mark it as paid.</p>
                        <?php elseif ($t['status'] === 'paid'): ?>
                            <p class="settle-state"><?= icon('info', 'icon-sm') ?> <?= e($t['from_name']) ?> says they paid via <strong><?= e(PAYMENT_METHOD_LABELS[$t['payment_method']] ?? '') ?></strong> on <?= date('j M, g:i a', strtotime($t['paid_at'])) ?>. Did you receive it?</p>
                            <?php if ($canAct): ?>
                                <div class="btn-row">
                                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="confirm"><input type="hidden" name="settlement_id" value="<?= $t['id'] ?>">
                                        <button class="btn btn-primary" type="submit"><?= icon('check-check', 'icon-sm') ?> Confirm Received</button></form>
                                    <form method="post" data-confirm="Mark as NOT received? The transfer goes back to pending."><?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="settlement_id" value="<?= $t['id'] ?>">
                                        <button class="btn btn-light" type="submit">Not received</button></form>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="settle-state ok"><?= icon('circle-check', 'icon-sm') ?> Received and confirmed.</p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </section>
        </div>

        <aside>
            <section class="summary-green">
                <span class="section-label">Payment summary</span>
                <strong class="summary-amount"><?= money(abs($myOpen)) ?></strong>
                <dl>
                    <div><dt>You</dt><dd><?= $myOpen < 0 ? 'still need to pay' : ($myOpen > 0 ? 'still need to receive' : 'are fully settled') ?></dd></div>
                    <div><dt>Type</dt><dd>Group expense settlement</dd></div>
                    <div><dt>Trip</dt><dd><?= e($group['title']) ?></dd></div>
                </dl>
            </section>

            <section class="card">
                <h2 class="card-title">Group Settlement</h2>
                <div class="mini-stats">
                    <div><small>Total expenses</small><strong><?= money($totalExpenses) ?></strong></div>
                    <div><small>Members</small><strong><?= count($balances) ?></strong></div>
                    <div><small>Confirmed transfers</small><strong><?= $confirmed ?>/<?= count($transfers) ?></strong></div>
                    <div><small>Total pending</small><strong class="text-accent"><?= money($pendingAmount) ?></strong></div>
                </div>
                <span class="section-label" style="margin-top:16px;display:block">All transfers</span>
                <?php foreach ($transfers as $t): ?>
                    <div class="mini-transfer">
                        <span><?= e(explode(' ', $t['from_name'])[0]) ?> &rarr; <?= e(explode(' ', $t['to_name'])[0]) ?><strong><?= money($t['amount']) ?></strong></span>
                        <span class="badge <?= $t['status'] === 'confirmed' ? '' : 'badge-accent' ?>"><?= e(strtoupper($t['status'])) ?></span>
                    </div>
                <?php endforeach; ?>
                <?php if (!$transfers): ?><p class="text-small mb-0">No transfers needed.</p><?php endif; ?>
            </section>

            <section class="card secure-list">
                <h3><?= icon('shield-check', 'text-primary') ?> Secure &amp; Transparent</h3>
                <ul>
                    <li>Based on recorded expenses only.</li>
                    <li>Visible to every group member.</li>
                    <li>Completed only after the receiver confirms.</li>
                    <li>No platform charges for settlements.</li>
                </ul>
            </section>
        </aside>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
