<?php
/**
 * money.php
 * ---------
 * Everything about money inside a group:
 *   1. Expenses and how they are split (expense shares)
 *   2. Balances (who paid more / less than their share)
 *   3. Settlement transfers (greedy algorithm) and their status
 *   4. Booking payments (DEMO of an SSLCommerz-style sandbox)
 *
 * All amounts are WHOLE TAKA (integers) so shares always add up exactly.
 */

// =====================================================================
// 1. EXPENSES
// =====================================================================

/**
 * Split an amount equally between users, in whole taka.
 * The remainder goes 1 taka at a time to the members with the lowest user id,
 * so the result is always the same and always adds up exactly:
 *   split_equal(100, [5, 2, 6])  ->  [2 => 34, 5 => 33, 6 => 33]
 */
function split_equal(int $amount, array $userIds): array
{
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    sort($userIds);
    $count = count($userIds);
    if ($count === 0) {
        return [];
    }

    $base      = intdiv($amount, $count);
    $remainder = $amount % $count;

    $shares = [];
    foreach ($userIds as $i => $userId) {
        $shares[$userId] = $base + ($i < $remainder ? 1 : 0);
    }
    return $shares;
}

/** Expenses of a group with payer name and number of people sharing. */
function group_expenses(int $groupId): array
{
    $stmt = db()->prepare(
        'SELECT e.*, u.full_name AS payer_name,
                (SELECT COUNT(*) FROM expense_shares s WHERE s.expense_id = e.id AND s.share_amount > 0) AS share_count
         FROM expenses e JOIN users u ON u.id = e.paid_by
         WHERE e.group_id = ?
         ORDER BY e.expense_date DESC, e.id DESC'
    );
    $stmt->execute([$groupId]);
    return $stmt->fetchAll();
}

/** Shares of one expense: [user_id => amount] */
function expense_shares(int $expenseId): array
{
    $stmt = db()->prepare('SELECT user_id, share_amount FROM expense_shares WHERE expense_id = ?');
    $stmt->execute([$expenseId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
}

/** Can expenses be added/changed in this group? '' = yes. */
function expense_problem(array $group, int $userId): string
{
    if (!is_group_member((int) $group['id'], $userId)) {
        return 'Only members of this group can manage its expenses.';
    }
    if ($group['status'] === 'completed') {
        return 'This trip is completed, so expenses are locked.';
    }
    if ($group['status'] !== 'confirmed') {
        return 'Expenses can be added once the group is confirmed.';
    }
    return '';
}

/**
 * Validate and save a new expense.
 * $input: title, category, amount, paid_by, expense_date, note, split_type,
 *         members[] (equal split) or shares[user_id] (custom split)
 * Returns [errors array, new expense id or 0].
 */
function add_expense(array $group, array $user, array $input): array
{
    $errors = [];
    if ($problem = expense_problem($group, (int) $user['id'])) {
        return [['form' => $problem], 0];
    }

    $memberIds = array_map('intval', array_column(group_members_list((int) $group['id']), 'id'));

    $title    = trim($input['title'] ?? '');
    $category = $input['category'] ?? 'other';
    $amountIn = trim((string) ($input['amount'] ?? ''));
    $paidBy   = (int) ($input['paid_by'] ?? 0);
    $date     = $input['expense_date'] ?? '';
    $note     = trim($input['note'] ?? '');
    $split    = ($input['split_type'] ?? 'equal') === 'custom' ? 'custom' : 'equal';

    if ($title === '' || mb_strlen($title) > 150) {
        $errors['title'] = 'Enter a short description (max 150 characters).';
    }
    if (!isset(CATEGORY_LABELS[$category])) {
        $errors['category'] = 'Choose a category.';
    }
    if (!preg_match('/^\d{1,7}$/', $amountIn) || (int) $amountIn < 1) {
        $errors['amount'] = 'Enter the amount in whole taka (e.g. 1200).';
    }
    $amount = (int) $amountIn;
    if (!in_array($paidBy, $memberIds, true)) {
        $errors['paid_by'] = 'The payer must be a member of this group.';
    }
    if (!valid_date($date)) {
        $errors['expense_date'] = 'Choose a valid date.';
    }
    if (mb_strlen($note) > 255) {
        $errors['note'] = 'The note is too long.';
    }

    // Work out the shares
    $shares = [];
    if ($split === 'equal') {
        $chosen = array_map('intval', (array) ($input['members'] ?? []));
        $chosen = array_values(array_intersect($chosen, $memberIds));   // ignore anyone who isn't a member
        if (!$chosen) {
            $errors['members'] = 'Choose at least one member to share this expense.';
        } elseif (!isset($errors['amount'])) {
            $shares = split_equal($amount, $chosen);
        }
    } else {
        $total = 0;
        foreach ((array) ($input['shares'] ?? []) as $userId => $value) {
            $userId = (int) $userId;
            $value  = trim((string) $value);
            if ($value === '' || $value === '0') {
                continue;
            }
            if (!in_array($userId, $memberIds, true) || !preg_match('/^\d{1,7}$/', $value)) {
                $errors['shares'] = 'Custom shares must be whole taka amounts for group members.';
                break;
            }
            $shares[$userId] = (int) $value;
            $total += (int) $value;
        }
        if (!isset($errors['shares']) && !isset($errors['amount'])) {
            if (!$shares) {
                $errors['shares'] = 'Enter at least one custom share.';
            } elseif ($total !== $amount) {
                $errors['shares'] = 'Custom shares add up to ' . money($total) . ' but the expense is ' . money($amount) . '. They must be exactly equal.';
            }
        }
    }

    if ($errors) {
        return [$errors, 0];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO expenses (group_id, paid_by, title, category, amount, split_type, expense_date, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$group['id'], $paidBy, $title, $category, $amount, $split, $date, $note ?: null, $user['id']]);
        $expenseId = (int) $pdo->lastInsertId();

        $insert = $pdo->prepare('INSERT INTO expense_shares (expense_id, user_id, share_amount) VALUES (?, ?, ?)');
        foreach ($shares as $userId => $share) {
            $insert->execute([$expenseId, $userId, $share]);
        }

        sync_settlements((int) $group['id']);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    return [[], $expenseId];
}

/** Delete an expense (payer, the person who added it, or the organiser). */
function delete_expense(array $group, array $user, int $expenseId): string
{
    if ($problem = expense_problem($group, (int) $user['id'])) {
        return $problem;
    }
    $stmt = db()->prepare('SELECT * FROM expenses WHERE id = ? AND group_id = ?');
    $stmt->execute([$expenseId, $group['id']]);
    $expense = $stmt->fetch();
    if (!$expense) {
        return 'That expense does not exist.';
    }
    $uid = (int) $user['id'];
    if ($uid !== (int) $expense['paid_by'] && $uid !== (int) $expense['created_by'] && !is_organizer($group, $uid)) {
        return 'Only the payer, the person who added it, or the organiser can delete this expense.';
    }

    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM expenses WHERE id = ?')->execute([$expenseId]);   // shares are deleted by CASCADE
    sync_settlements((int) $group['id']);
    $pdo->commit();
    return '';
}

// =====================================================================
// 2. BALANCES
// =====================================================================

/**
 * Balance of every member:
 *   paid      = total of expenses they paid
 *   owed      = total of their shares ("your fair share")
 *   net       = paid - owed        (+ means the group owes them, - means they owe)
 *   remaining = net after transfers already marked paid / confirmed
 *
 * The nets of all members always add up to 0.
 */
function group_balances(int $groupId): array
{
    $balances = [];
    foreach (group_members_list($groupId) as $m) {
        $balances[(int) $m['id']] = ['id' => (int) $m['id'], 'name' => $m['full_name'], 'photo' => $m['profile_photo'],
                                     'paid' => 0, 'owed' => 0, 'net' => 0, 'remaining' => 0];
    }

    $stmt = db()->prepare('SELECT paid_by, SUM(amount) FROM expenses WHERE group_id = ? GROUP BY paid_by');
    $stmt->execute([$groupId]);
    foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $userId => $sum) {
        if (isset($balances[$userId])) $balances[$userId]['paid'] = (int) round($sum);
    }

    $stmt = db()->prepare(
        'SELECT s.user_id, SUM(s.share_amount) FROM expense_shares s JOIN expenses e ON e.id = s.expense_id
         WHERE e.group_id = ? GROUP BY s.user_id'
    );
    $stmt->execute([$groupId]);
    foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $userId => $sum) {
        if (isset($balances[$userId])) $balances[$userId]['owed'] = (int) round($sum);
    }

    foreach ($balances as &$b) {
        $b['net'] = $b['paid'] - $b['owed'];
        $b['remaining'] = $b['net'];
    }
    unset($b);

    // Transfers that are paid or confirmed already moved money between members
    $stmt = db()->prepare("SELECT from_user_id, to_user_id, amount FROM settlements WHERE group_id = ? AND status IN ('paid','confirmed')");
    $stmt->execute([$groupId]);
    foreach ($stmt->fetchAll() as $t) {
        if (isset($balances[$t['from_user_id']])) $balances[$t['from_user_id']]['remaining'] += (int) round($t['amount']);
        if (isset($balances[$t['to_user_id']]))   $balances[$t['to_user_id']]['remaining']   -= (int) round($t['amount']);
    }

    return $balances;
}

// =====================================================================
// 3. SETTLEMENT (greedy algorithm)
// =====================================================================

/**
 * Turn balances into transfers using the greedy method:
 *   1. find the largest debtor and the largest creditor
 *   2. the debtor pays the creditor min(debt, credit)
 *   3. reduce both; whoever reaches 0 is removed
 *   4. repeat until nobody owes anything
 *
 * Every step removes at least one person, so n people need at most n - 1
 * transfers. (Greedy is simple and good, but it does NOT always find the
 * smallest possible number of transfers; that problem is NP-hard.)
 * Ties are broken by the lowest user id, so the result is deterministic.
 *
 * $balances: [user_id => whole taka, + means receives, - means pays]
 * returns:   [['from' => id, 'to' => id, 'amount' => int], ...]
 */
function greedy_settlement(array $balances): array
{
    $debtors = [];
    $creditors = [];
    foreach ($balances as $userId => $amount) {
        $amount = (int) $amount;
        if ($amount < 0) $debtors[(int) $userId] = -$amount;
        if ($amount > 0) $creditors[(int) $userId] = $amount;
    }
    ksort($debtors);
    ksort($creditors);

    $transfers = [];
    while ($debtors && $creditors) {
        $from = largest_key($debtors);
        $to   = largest_key($creditors);
        $pay  = min($debtors[$from], $creditors[$to]);

        $transfers[] = ['from' => $from, 'to' => $to, 'amount' => $pay];

        $debtors[$from] -= $pay;
        $creditors[$to] -= $pay;
        if ($debtors[$from] === 0) unset($debtors[$from]);
        if ($creditors[$to] === 0) unset($creditors[$to]);
    }
    return $transfers;
}

/** Key of the largest value (lowest key wins a tie, because arrays are sorted by key). */
function largest_key(array $values): int
{
    $best = null;
    foreach ($values as $key => $value) {
        if ($best === null || $value > $values[$best]) {
            $best = $key;
        }
    }
    return (int) $best;
}

/**
 * Re-create the PENDING transfers of a group from its current balances.
 * Transfers already marked paid or confirmed are kept (they count as money moved).
 * Called after every change to expenses, inside the caller's transaction.
 */
function sync_settlements(int $groupId): void
{
    $remaining = array_column(group_balances($groupId), 'remaining', 'id');

    db()->prepare("DELETE FROM settlements WHERE group_id = ? AND status = 'pending'")->execute([$groupId]);

    $insert = db()->prepare('INSERT INTO settlements (group_id, from_user_id, to_user_id, amount) VALUES (?, ?, ?, ?)');
    foreach (greedy_settlement($remaining) as $t) {
        $insert->execute([$groupId, $t['from'], $t['to'], $t['amount']]);
    }
}

/** All transfers of a group with names. */
function group_settlements(int $groupId): array
{
    $stmt = db()->prepare(
        "SELECT s.*, f.full_name AS from_name, t.full_name AS to_name,
                fp.profile_photo AS from_photo, tp.profile_photo AS to_photo
         FROM settlements s
         JOIN users f ON f.id = s.from_user_id
         JOIN users t ON t.id = s.to_user_id
         LEFT JOIN student_profiles fp ON fp.user_id = f.id
         LEFT JOIN student_profiles tp ON tp.user_id = t.id
         WHERE s.group_id = ?
         ORDER BY FIELD(s.status, 'paid', 'pending', 'confirmed'), s.amount DESC"
    );
    $stmt->execute([$groupId]);
    return $stmt->fetchAll();
}

/** Number of transfers that are not confirmed yet. */
function open_settlement_count(int $groupId): int
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM settlements WHERE group_id = ? AND status <> 'confirmed'");
    $stmt->execute([$groupId]);
    return (int) $stmt->fetchColumn();
}

/** Load one transfer that belongs to the group (or null). */
function find_settlement(int $id, int $groupId): ?array
{
    $stmt = db()->prepare('SELECT * FROM settlements WHERE id = ? AND group_id = ?');
    $stmt->execute([$id, $groupId]);
    return $stmt->fetch() ?: null;
}

/** Payer: "Mark as Paid". */
function mark_settlement_paid(array $group, array $user, int $settlementId, string $method): string
{
    $t = find_settlement($settlementId, (int) $group['id']);
    if (!$t) {
        return 'This transfer no longer exists (the balances changed). Please check the updated list.';
    }
    if ((int) $t['from_user_id'] !== (int) $user['id']) {
        return 'Only the person who owes this money can mark it as paid.';
    }
    if ($t['status'] !== 'pending') {
        return 'This transfer is already marked as paid.';
    }
    if (!isset(PAYMENT_METHOD_LABELS[$method])) {
        return 'Choose how you paid (bKash, Nagad, Card or Cash).';
    }
    if ($group['status'] !== 'confirmed') {
        return 'Settlements can only change while the trip is confirmed.';
    }
    db()->prepare("UPDATE settlements SET status = 'paid', payment_method = ?, paid_at = NOW() WHERE id = ? AND status = 'pending'")
        ->execute([$method, $settlementId]);
    return '';
}

/** Receiver: "Confirm Received" ($received = true) or "Not received" ($received = false). */
function answer_settlement(array $group, array $user, int $settlementId, bool $received): string
{
    $t = find_settlement($settlementId, (int) $group['id']);
    if (!$t) {
        return 'This transfer no longer exists.';
    }
    if ((int) $t['to_user_id'] !== (int) $user['id']) {
        return 'Only the person receiving this money can confirm it.';
    }
    if ($t['status'] !== 'paid') {
        return $t['status'] === 'confirmed' ? 'You already confirmed this transfer.' : 'The payer has not marked this as paid yet.';
    }
    if ($group['status'] !== 'confirmed') {
        return 'Settlements can only change while the trip is confirmed.';
    }

    if ($received) {
        db()->prepare("UPDATE settlements SET status = 'confirmed', confirmed_at = NOW() WHERE id = ? AND status = 'paid'")
            ->execute([$settlementId]);
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE settlements SET status = 'pending', payment_method = NULL, paid_at = NULL WHERE id = ?")
            ->execute([$settlementId]);
        sync_settlements((int) $group['id']);
        $pdo->commit();
    }
    return '';
}

// =====================================================================
// 4. BOOKING PAYMENTS (DEMO / SANDBOX)
// =====================================================================

function find_booking(int $groupId, int $userId): ?array
{
    $stmt = db()->prepare('SELECT * FROM bookings WHERE group_id = ? AND user_id = ?');
    $stmt->execute([$groupId, $userId]);
    return $stmt->fetch() ?: null;
}

function find_booking_by_ref(string $ref): ?array
{
    $stmt = db()->prepare('SELECT * FROM bookings WHERE transaction_ref = ?');
    $stmt->execute([$ref]);
    return $stmt->fetch() ?: null;
}

/** Members of a group who have not paid their booking. */
function unpaid_members(int $groupId): array
{
    $stmt = db()->prepare(
        "SELECT u.id, u.full_name FROM group_members gm JOIN users u ON u.id = gm.user_id
         LEFT JOIN bookings b ON b.group_id = gm.group_id AND b.user_id = gm.user_id AND b.status = 'paid'
         WHERE gm.group_id = ? AND gm.status = 'joined' AND b.id IS NULL"
    );
    $stmt->execute([$groupId]);
    return $stmt->fetchAll();
}

/** Booking amount for one member = current per-person price (server-side). */
function booking_amount(array $group): float
{
    return per_person_price((int) $group['package_id'], (int) $group['member_count']);
}

/** Can this user pay a booking for this group? '' = yes. */
function booking_problem(array $group, array $user): string
{
    if (!is_group_member((int) $group['id'], (int) $user['id'])) {
        return 'Only members of this group can pay for it.';
    }
    if ($group['status'] !== 'confirmed') {
        return in_array($group['status'], ['forming', 'full'], true)
            ? 'Booking opens when the organiser confirms the group (after the minimum headcount is reached).'
            : 'Booking is closed for this group.';
    }
    $booking = find_booking((int) $group['id'], (int) $user['id']);
    if ($booking && $booking['status'] === 'paid') {
        return 'You have already paid for this trip.';
    }
    return '';
}

/**
 * Start a demo payment: create (or reuse) the booking row with a fresh
 * transaction reference. Returns [error, reference].
 */
function start_booking(array $group, array $user, string $method): array
{
    if ($problem = booking_problem($group, $user)) {
        return [$problem, ''];
    }
    if (!in_array($method, ['bkash', 'nagad', 'card'], true)) {
        return ['Choose a payment method.', ''];
    }
    $ref    = 'CG-DEMO-' . strtoupper(bin2hex(random_bytes(5)));
    $amount = booking_amount($group);

    db()->prepare(
        "INSERT INTO bookings (group_id, user_id, amount, payment_method, transaction_ref, status)
         VALUES (?, ?, ?, ?, ?, 'pending')
         ON DUPLICATE KEY UPDATE amount = VALUES(amount), payment_method = VALUES(payment_method),
                                 transaction_ref = VALUES(transaction_ref), status = 'pending', paid_at = NULL"
    )->execute([$group['id'], $user['id'], $amount, $method, $ref]);

    return ['', $ref];
}

/**
 * The demo gateway reports back. We only trust the reference, the logged-in
 * user and the database - never an amount sent by the browser.
 */
function finish_booking(string $ref, array $user, bool $success): array
{
    $booking = find_booking_by_ref($ref);
    if (!$booking || (int) $booking['user_id'] !== (int) $user['id']) {
        return ['This payment reference is not valid.', null];
    }
    if ($booking['status'] !== 'pending') {
        return ['This payment was already processed.', $booking];
    }
    $group = get_group((int) $booking['group_id']);
    if (!$group || $group['status'] !== 'confirmed') {
        return ['This group is no longer accepting payments.', $booking];
    }
    // Amount check: what the gateway "charged" must equal the server-side price
    if ((float) $booking['amount'] !== booking_amount($group)) {
        $success = false;
    }

    db()->prepare('UPDATE bookings SET status = ?, paid_at = ? WHERE id = ? AND status = ?')
        ->execute([$success ? 'paid' : 'failed', $success ? date('Y-m-d H:i:s') : null, $booking['id'], 'pending']);

    return ['', find_booking_by_ref($ref)];
}
