<?php
/**
 * expenses.php?group=7 - Trip Expenses (design: "Trip Expenses - ... (Full)")
 *
 * Members add what they paid during the trip. Each expense is split into
 * shares that add up EXACTLY to the amount (whole taka). Balances and the
 * settlement transfers are recalculated after every change.
 */
require_once __DIR__ . '/includes/bootstrap.php';
$me = require_login();

$groupId = int_param($_GET, 'group');
$group   = get_group($groupId);
if (!$group || (!is_group_member($groupId, (int) $me['id']) && !is_admin())) {
    show_error_page('Not available', 'Only members of this group can see its expenses.', 403, 'my-trips.php', 'My Trips');
}

$members   = group_members_list($groupId);
$memberIds = array_map('intval', array_column($members, 'id'));
$errors    = [];
$input     = ['title' => '', 'amount' => '', 'paid_by' => $me['id'], 'expense_date' => min(date('Y-m-d'), $group['end_date']),
              'category' => 'food', 'note' => '', 'split_type' => $group['cost_sharing'], 'members' => $memberIds, 'shares' => []];

if (is_post()) {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $input = array_merge($input, $_POST);
        $input['members'] = array_map('intval', (array) ($_POST['members'] ?? []));
        [$errors] = add_expense($group, $me, $_POST);
        if (!$errors) {
            set_flash('success', 'Expense added. Balances and settlement transfers were updated.');
            redirect('expenses.php?group=' . $groupId);
        }
    } elseif ($action === 'delete') {
        $error = delete_expense($group, $me, int_param($_POST, 'expense_id'));
        set_flash($error ? 'error' : 'success', $error ?: 'Expense deleted. Balances were updated.');
        redirect('expenses.php?group=' . $groupId);
    }
}

$expenses  = group_expenses($groupId);
$balances  = group_balances($groupId);
$transfers = group_settlements($groupId);
$mine      = $balances[(int) $me['id']] ?? ['paid' => 0, 'owed' => 0, 'net' => 0, 'remaining' => 0];
$total     = array_sum(array_column($expenses, 'amount'));
$canEdit   = expense_problem($group, (int) $me['id']) === '';
$names     = array_column($members, 'full_name', 'id');

// Shares of every expense, for the "details" rows
$stmt = db()->prepare('SELECT s.expense_id, s.user_id, s.share_amount FROM expense_shares s JOIN expenses e ON e.id = s.expense_id WHERE e.group_id = ?');
$stmt->execute([$groupId]);
$sharesByExpense = [];
foreach ($stmt->fetchAll() as $s) {
    $sharesByExpense[$s['expense_id']][$s['user_id']] = (int) $s['share_amount'];
}

// Category totals for the sidebar
$byCategory = [];
foreach ($expenses as $x) {
    $byCategory[$x['category']] = ($byCategory[$x['category']] ?? 0) + $x['amount'];
}
arsort($byCategory);

$pageTitle = 'Trip Expenses';
$activeNav = 'my-trips.php';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="layout-sidebar">
        <div>
            <section class="card expense-hero" style="--hero:url('<?= image_url($group['package_image']) ?>')">
                <span class="badge"><?= icon('refresh-cw', 'icon-sm') ?> <?= $group['status'] === 'completed' ? 'Trip completed' : ($group['status'] === 'confirmed' ? 'Trip in progress' : 'Trip ' . e($group['status'])) ?></span>
                <h1>Trip Expenses</h1>
                <h2 class="expense-hero-sub"><?= e($group['title']) ?></h2>
                <p class="meta mb-0">
                    <span><?= icon('map-pin', 'icon-sm') ?> <?= e($group['destination_name']) ?></span>
                    <span><?= icon('calendar', 'icon-sm') ?> <?= e(date_range($group['start_date'], $group['end_date'])) ?></span>
                    <span><?= icon('users', 'icon-sm') ?> <?= count($members) ?> members</span>
                </p>
            </section>

            <?= render_flash() ?>
            <?php if (!$canEdit && $group['status'] !== 'completed'): ?><?= alert('info', expense_problem($group, (int) $me['id'])) ?><?php endif; ?>

            <div class="stat-grid">
                <div class="stat"><span><?= icon('receipt', 'icon-sm') ?> Total Expenses</span><strong><?= money($total) ?></strong></div>
                <div class="stat"><span><?= icon('wallet', 'icon-sm') ?> You Paid</span><strong><?= money($mine['paid']) ?></strong></div>
                <div class="stat"><span><?= icon('percent', 'icon-sm') ?> Your Share</span><strong><?= money($mine['owed']) ?></strong></div>
                <div class="stat stat-highlight <?= $mine['net'] < 0 ? 'is-negative' : '' ?>">
                    <span><?= icon('scale', 'icon-sm') ?> Your Balance</span>
                    <strong><?= $mine['net'] > 0 ? '+' : '' ?><?= money($mine['net']) ?></strong>
                    <small><?= $mine['net'] > 0 ? 'You are owed ' . money($mine['net']) : ($mine['net'] < 0 ? 'You owe ' . money(-$mine['net']) : 'You are even') ?></small>
                </div>
            </div>

            <!-- All expenses -->
            <section class="card">
                <div class="section-head">
                    <h2 class="card-section-title mb-0">All Expenses</h2>
                    <?php if ($canEdit): ?><a class="btn btn-primary btn-sm" href="#add-expense"><?= icon('plus', 'icon-sm') ?> Add Expense</a><?php endif; ?>
                </div>
                <?php if ($expenses): ?>
                    <div class="table-wrap">
                        <table class="table expense-table">
                            <thead><tr><th>Category</th><th>Description</th><th class="num">Amount</th><th>Paid by</th><th>Date</th><th>Shared by</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($expenses as $x): $cat = CATEGORY_LABELS[$x['category']]; ?>
                                <tr>
                                    <td><span class="icon-circle" title="<?= e($cat['label']) ?>"><?= icon($cat['icon'], 'icon-sm') ?></span></td>
                                    <td><strong><?= e($x['title']) ?></strong><?php if ($x['note']): ?><small class="d-block text-muted"><?= e($x['note']) ?></small><?php endif; ?></td>
                                    <td class="num"><?= money($x['amount']) ?></td>
                                    <td><?= e($x['payer_name']) ?></td>
                                    <td><?= date('j M', strtotime($x['expense_date'])) ?></td>
                                    <td><?= (int) $x['share_count'] ?> <?= (int) $x['share_count'] === 1 ? 'member' : 'members' ?></td>
                                    <td>
                                        <details class="row-details">
                                            <summary>Details</summary>
                                            <div class="row-details-panel">
                                                <strong><?= $x['split_type'] === 'equal' ? 'Split equally' : 'Custom split' ?></strong>
                                                <?php foreach ($sharesByExpense[$x['id']] ?? [] as $uid => $share): ?>
                                                    <div><span><?= e($names[$uid] ?? 'Former member') ?></span><span><?= money($share) ?></span></div>
                                                <?php endforeach; ?>
                                                <?php if ($canEdit && in_array((int) $me['id'], [(int) $x['paid_by'], (int) $x['created_by'], (int) $group['creator_id']], true)): ?>
                                                    <form method="post" data-confirm="Delete this expense? Balances will be recalculated.">
                                                        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="expense_id" value="<?= $x['id'] ?>">
                                                        <button class="link-button text-danger" type="submit"><?= icon('trash-2', 'icon-sm') ?> Delete</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </details>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">No expenses yet.<?= $canEdit ? ' Add the first one below.' : '' ?></div>
                <?php endif; ?>
            </section>

            <!-- Add expense -->
            <?php if ($canEdit): ?>
                <form class="card card-muted" method="post" id="add-expense" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">
                    <h2 class="card-section-title">Add Quick Expense</h2>
                    <?php if (isset($errors['form'])): ?><?= alert('error', $errors['form']) ?><?php endif; ?>
                    <div class="form-row form-row-3">
                        <div class="form-group">
                            <label for="title">Title</label>
                            <input class="input" id="title" name="title" value="<?= e($input['title']) ?>" placeholder="e.g. Lunch" maxlength="150" required>
                            <?= field_error($errors, 'title') ?>
                        </div>
                        <div class="form-group">
                            <label for="amount">Amount (৳, whole taka)</label>
                            <input class="input" id="amount" name="amount" type="number" min="1" step="1" value="<?= e((string) $input['amount']) ?>" placeholder="0" required>
                            <?= field_error($errors, 'amount') ?>
                        </div>
                        <div class="form-group">
                            <label for="paid_by">Paid by</label>
                            <select class="input" id="paid_by" name="paid_by">
                                <?php foreach ($members as $m): ?>
                                    <option value="<?= $m['id'] ?>" <?= (int) $input['paid_by'] === (int) $m['id'] ? 'selected' : '' ?>><?= (int) $m['id'] === (int) $me['id'] ? 'You (' . e($m['full_name']) . ')' : e($m['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= field_error($errors, 'paid_by') ?>
                        </div>
                        <div class="form-group">
                            <label for="expense_date">Date</label>
                            <input class="input" id="expense_date" name="expense_date" type="date" value="<?= e($input['expense_date']) ?>" required>
                            <?= field_error($errors, 'expense_date') ?>
                        </div>
                        <div class="form-group">
                            <label for="category">Category</label>
                            <select class="input" id="category" name="category">
                                <?php foreach (CATEGORY_LABELS as $key => $c): ?>
                                    <option value="<?= $key ?>" <?= $input['category'] === $key ? 'selected' : '' ?>><?= e($c['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="note">Note (optional)</label>
                            <input class="input" id="note" name="note" value="<?= e($input['note']) ?>" placeholder="Add a note" maxlength="255">
                        </div>
                    </div>

                    <fieldset class="form-group">
                        <legend class="label">Shared by</legend>
                        <div class="segmented segmented-sm" data-split-toggle>
                            <label><input type="radio" name="split_type" value="equal" <?= $input['split_type'] !== 'custom' ? 'checked' : '' ?>><span>Split equally</span></label>
                            <label><input type="radio" name="split_type" value="custom" <?= $input['split_type'] === 'custom' ? 'checked' : '' ?>><span>Custom amounts</span></label>
                        </div>
                        <div class="split-panel" data-split="equal">
                            <div class="tag-picker tag-picker-sm">
                                <?php foreach ($members as $m): ?>
                                    <label class="tag-option"><input type="checkbox" name="members[]" value="<?= $m['id'] ?>" <?= in_array((int) $m['id'], $input['members'], true) ? 'checked' : '' ?>><span><?= e($m['full_name']) ?></span></label>
                                <?php endforeach; ?>
                            </div>
                            <p class="field-help">Whole taka only. Any leftover taka goes to the first members, e.g. ৳100 / 3 = 34 + 33 + 33.</p>
                            <?= field_error($errors, 'members') ?>
                        </div>
                        <div class="split-panel" data-split="custom">
                            <div class="custom-shares">
                                <?php foreach ($members as $m): ?>
                                    <label><span><?= e($m['full_name']) ?></span>
                                        <input class="input" type="number" min="0" step="1" name="shares[<?= $m['id'] ?>]" value="<?= e((string) ($input['shares'][$m['id']] ?? '')) ?>" placeholder="0" data-share>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="field-help" data-share-total>Shares must add up exactly to the amount.</p>
                            <?= field_error($errors, 'shares') ?>
                        </div>
                    </fieldset>
                    <div class="form-actions"><button class="btn btn-primary" type="submit">Save Expense</button></div>
                </form>
            <?php endif; ?>

            <!-- Balance breakdown -->
            <section class="card">
                <h2 class="card-section-title">Your Balance Breakdown</h2>
                <div class="balance-flow">
                    <div class="balance-box"><small>You paid</small><strong><?= money($mine['paid']) ?></strong></div>
                    <span class="balance-op">&minus;</span>
                    <div class="balance-box"><small>Your fair share</small><strong><?= money($mine['owed']) ?></strong></div>
                    <span class="balance-op"><?= icon('arrow-right') ?></span>
                    <div class="balance-box is-result <?= $mine['net'] < 0 ? 'is-negative' : '' ?>">
                        <small><?= $mine['net'] >= 0 ? 'You should receive' : 'You should pay' ?></small>
                        <strong><?= money(abs($mine['net'])) ?></strong>
                    </div>
                </div>

                <h3 style="margin-top:28px">Everyone's balance</h3>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Member</th><th class="num">Paid</th><th class="num">Share</th><th class="num">Net</th><th class="num">Still to settle</th></tr></thead>
                        <tbody>
                        <?php foreach ($balances as $b): ?>
                            <tr>
                                <td><?= e($b['name']) ?><?= $b['id'] === (int) $me['id'] ? ' <small class="text-muted">(you)</small>' : '' ?></td>
                                <td class="num"><?= money($b['paid']) ?></td>
                                <td class="num"><?= money($b['owed']) ?></td>
                                <td class="num <?= $b['net'] > 0 ? 'text-primary' : ($b['net'] < 0 ? 'text-danger' : '') ?>"><?= $b['net'] > 0 ? '+' : '' ?><?= money($b['net']) ?></td>
                                <td class="num"><?= $b['remaining'] === 0 ? '<span class="text-muted">Settled</span>' : money($b['remaining']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot><tr><td>Total</td><td class="num"><?= money($total) ?></td><td class="num"><?= money(array_sum(array_column($balances, 'owed'))) ?></td><td class="num"><?= money(array_sum(array_column($balances, 'net'))) ?></td><td></td></tr></tfoot>
                    </table>
                </div>
            </section>

            <!-- Settlement -->
            <section class="card">
                <h2 class="card-section-title" style="margin-bottom:4px">Settlement</h2>
                <p class="text-small">Clear the group's balances with at most <?= max(0, count($members) - 1) ?> transfers (greedy method: largest debtor pays largest creditor).</p>
                <?php if ($transfers): ?>
                    <div class="transfer-grid">
                        <?php foreach ($transfers as $t): $mineT = in_array((int) $me['id'], [(int) $t['from_user_id'], (int) $t['to_user_id']], true); ?>
                            <div class="transfer <?= $mineT ? 'is-mine' : '' ?> status-<?= e($t['status']) ?>">
                                <span class="avatar avatar-sm avatar-initials"><?= e(initials($t['from_name'])) ?></span>
                                <div><strong><?= (int) $t['from_user_id'] === (int) $me['id'] ? 'You' : e($t['from_name']) ?></strong><small>pays</small></div>
                                <div class="transfer-amount"><strong><?= money($t['amount']) ?></strong><small>to <?= (int) $t['to_user_id'] === (int) $me['id'] ? 'You' : e($t['to_name']) ?></small></div>
                                <span class="transfer-status"><?= e(['pending' => 'Pending', 'paid' => 'Paid, awaiting confirmation', 'confirmed' => 'Confirmed'][$t['status']]) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">Everyone is even. No transfers needed.</div>
                <?php endif; ?>
                <div class="form-actions" style="justify-content:flex-start">
                    <a class="btn btn-accent" href="<?= url('settle.php?group=' . $groupId) ?>">Settle My Balance</a>
                </div>
            </section>
        </div>

        <aside>
            <section class="card">
                <span class="section-label">Group</span>
                <h2 class="side-title"><a href="<?= url('group.php?id=' . $groupId) ?>"><?= e($group['title']) ?></a></h2>
                <p class="meta"><?= icon('users', 'icon-sm') ?> <?= count($members) ?>/<?= (int) $group['max_members'] ?> members</p>
                <div class="category-summary">
                    <strong>Category Summary</strong>
                    <?php if ($byCategory): ?>
                        <?php foreach ($byCategory as $cat => $sum): ?>
                            <div class="cat-row">
                                <div class="progress-label"><span><?= e(CATEGORY_LABELS[$cat]['label']) ?></span><strong><?= money($sum) ?></strong></div>
                                <div class="progress progress-thin cat-<?= e($cat) ?>"><span style="width:<?= round(100 * $sum / max(1, $total)) ?>%"></span></div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-small mb-0">No expenses yet.</p>
                    <?php endif; ?>
                </div>
                <a class="btn btn-light btn-block" href="<?= url('group.php?id=' . $groupId) ?>"><?= icon('arrow-left', 'icon-sm') ?> Back to Group</a>
            </section>
        </aside>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
