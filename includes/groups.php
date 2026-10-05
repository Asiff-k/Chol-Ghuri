<?php
/**
 * groups.php
 * ----------
 * Travel groups: loading them, membership, joining, leaving and the
 * group lifecycle (forming -> full -> confirmed -> completed).
 *
 * All rules are checked here on the server, so a page can't be tricked
 * by changing a hidden form field.
 */

/**
 * The SELECT used everywhere a group is shown. It adds the package,
 * destination, organiser and the current number of members.
 */
const GROUP_SELECT = "
    SELECT g.*,
           p.title AS package_title, p.trip_type, p.accommodation_type, p.accommodation_name,
           p.transport_info, p.location, p.amenities,
           p.image AS package_image, p.duration_days, p.destination_id,
           d.name AS destination_name, d.image AS destination_image,
           u.full_name AS organizer_name, u.university_id AS organizer_university_id,
           un.short_name AS organizer_uni, un.name AS organizer_university, osp.profile_photo AS organizer_photo,
           (SELECT COUNT(*) FROM group_members m WHERE m.group_id = g.id AND m.status = 'joined') AS member_count
    FROM travel_groups g
    JOIN packages p          ON p.id = g.package_id
    JOIN destinations d      ON d.id = p.destination_id
    JOIN users u             ON u.id = g.creator_id
    LEFT JOIN universities un ON un.id = u.university_id
    LEFT JOIN student_profiles osp ON osp.user_id = u.id
";

function get_group(int $id): ?array
{
    $stmt = db()->prepare(GROUP_SELECT . ' WHERE g.id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Several groups at once.
 *   fetch_groups("g.status = 'forming' AND p.destination_id = ?", [3])
 */
function fetch_groups(string $where = '1', array $params = [], string $order = 'g.start_date', int $limit = 100): array
{
    $stmt = db()->prepare(GROUP_SELECT . " WHERE $where ORDER BY $order LIMIT " . (int) $limit);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Upcoming groups that are still taking members. */
function open_groups_where(): string
{
    return "g.status IN ('forming','full') AND g.start_date >= CURDATE()";
}

/** Current price per person for a group (based on how many members it has now). */
function group_price_now(array $group): float
{
    return per_person_price((int) $group['package_id'], max(1, (int) $group['member_count']));
}

/** Members of a group with their university, photo and rating. */
function group_members_list(int $groupId): array
{
    $stmt = db()->prepare(
        "SELECT gm.role, gm.joined_at, gm.match_score, u.id, u.full_name, u.email, un.short_name AS uni, sp.profile_photo, sp.gender, sp.phone,
                (SELECT ROUND(AVG(score), 1) FROM ratings r WHERE r.rated_user_id = u.id) AS rating,
                (SELECT COUNT(*) FROM group_members m2 JOIN travel_groups g2 ON g2.id = m2.group_id
                  WHERE m2.user_id = u.id AND m2.status = 'joined' AND g2.status = 'completed') AS trips
         FROM group_members gm
         JOIN users u ON u.id = gm.user_id
         LEFT JOIN universities un ON un.id = u.university_id
         LEFT JOIN student_profiles sp ON sp.user_id = u.id
         WHERE gm.group_id = ? AND gm.status = 'joined'
         ORDER BY gm.role = 'organizer' DESC, gm.joined_at"
    );
    $stmt->execute([$groupId]);
    return $stmt->fetchAll();
}

function group_tags(int $groupId): array
{
    $stmt = db()->prepare('SELECT t.* FROM tags t JOIN group_tags gt ON gt.tag_id = t.id WHERE gt.group_id = ? ORDER BY t.id');
    $stmt->execute([$groupId]);
    return $stmt->fetchAll();
}

function is_group_member(int $groupId, int $userId): bool
{
    $stmt = db()->prepare("SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ? AND status = 'joined'");
    $stmt->execute([$groupId, $userId]);
    return (bool) $stmt->fetchColumn();
}

/** Is the user the organiser (creator) of this group? */
function is_organizer(array $group, int $userId): bool
{
    return (int) $group['creator_id'] === $userId;
}

/**
 * Another trip of this user whose dates overlap (or null).
 * A student can't be in two trips at the same time.
 */
function date_conflict(int $userId, string $start, string $end, int $exceptGroupId = 0): ?array
{
    $stmt = db()->prepare(
        "SELECT g.id, g.title FROM group_members gm JOIN travel_groups g ON g.id = gm.group_id
         WHERE gm.user_id = ? AND gm.status = 'joined' AND g.id <> ?
           AND g.status IN ('forming','full','confirmed')
           AND g.start_date <= ? AND g.end_date >= ?
         LIMIT 1"
    );
    $stmt->execute([$userId, $exceptGroupId, $end, $start]);
    return $stmt->fetch() ?: null;
}

/**
 * Eligibility rules of a group: "university only" (the organiser's university)
 * and the gender rule. Returns '' when the user is eligible.
 */
function eligibility_problem(array $group, array $user): string
{
    if ($group['university_only'] && (int) $user['university_id'] !== (int) $group['organizer_university_id']) {
        return 'This group is only for ' . ($group['organizer_university'] ?? 'the organiser\'s university') . ' students.';
    }
    $gender = $user['gender'] ?? null;
    if ($group['gender_rule'] === 'female_only' && $gender !== 'female') {
        return $gender ? 'This group is for women only.' : 'This group is for women only. Set your gender in Edit Profile if this applies to you.';
    }
    if ($group['gender_rule'] === 'male_only' && $gender !== 'male') {
        return $gender ? 'This group is for men only.' : 'This group is for men only. Set your gender in Edit Profile if this applies to you.';
    }
    return '';
}

/**
 * Why can't this user join this group? Returns '' when joining is allowed.
 * $budget (optional): the student's maximum price per person.
 */
function join_problem(array $group, array $user, ?float $budget = null): string
{
    if ($user['role'] !== 'student') {
        return 'Only student accounts can join groups.';
    }
    if (is_group_member((int) $group['id'], (int) $user['id'])) {
        return 'You are already a member of this group.';
    }
    if (!in_array($group['status'], ['forming', 'full'], true)) {
        return 'This group is no longer taking new members.';
    }
    if ($group['start_date'] < date('Y-m-d')) {
        return 'This trip has already started.';
    }
    if ((int) $group['member_count'] >= (int) $group['max_members']) {
        return 'This group is already full.';
    }
    if ($problem = eligibility_problem($group, $user)) {
        return $problem;
    }
    if ($conflict = date_conflict((int) $user['id'], $group['start_date'], $group['end_date'], (int) $group['id'])) {
        return 'You already have a trip on these dates: ' . $conflict['title'] . '.';
    }
    if ($budget !== null && $budget > 0) {
        $price = per_person_price((int) $group['package_id'], (int) $group['member_count'] + 1);
        if ($price > $budget) {
            return 'If you join, the price would be ' . money($price) . ' per person, which is over your budget of ' . money($budget) . '.';
        }
    }
    return '';
}

/**
 * Join a group. Returns '' on success, or an error message.
 * The group row is LOCKED (FOR UPDATE) while we check and insert, so two
 * students can't take the last spot at the same moment.
 */
function join_group(int $groupId, array $user, ?float $budget = null, ?int $matchScore = null): string
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT id FROM travel_groups WHERE id = ? FOR UPDATE')->execute([$groupId]);
        $group = get_group($groupId);
        if (!$group) {
            $pdo->rollBack();
            return 'This group does not exist.';
        }
        if ($problem = join_problem($group, $user, $budget)) {
            $pdo->rollBack();
            return $problem;
        }

        // Re-join (after leaving earlier) updates the old row; otherwise insert.
        $pdo->prepare(
            "INSERT INTO group_members (group_id, user_id, role, status, match_score, joined_at)
             VALUES (?, ?, 'member', 'joined', ?, NOW())
             ON DUPLICATE KEY UPDATE status = 'joined', role = 'member', match_score = VALUES(match_score), joined_at = NOW()"
        )->execute([$groupId, $user['id'], $matchScore]);

        update_fullness($groupId);
        $pdo->commit();
        return '';
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

/**
 * Leave a group (only before it is confirmed).
 * If the organiser leaves, the longest-standing member becomes organiser.
 * If nobody is left, the group is cancelled.
 */
function leave_group(int $groupId, array $user): string
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT id FROM travel_groups WHERE id = ? FOR UPDATE')->execute([$groupId]);
        $group = get_group($groupId);

        if (!$group || !is_group_member($groupId, (int) $user['id'])) {
            $pdo->rollBack();
            return 'You are not a member of this group.';
        }
        if (!in_array($group['status'], ['forming', 'full'], true)) {
            $pdo->rollBack();
            return 'You can no longer leave: the group is ' . $group['status'] . ' and bookings are locked.';
        }

        $pdo->prepare("UPDATE group_members SET status = 'left', role = 'member' WHERE group_id = ? AND user_id = ?")
            ->execute([$groupId, $user['id']]);

        if (is_organizer($group, (int) $user['id'])) {
            $stmt = $pdo->prepare("SELECT user_id FROM group_members WHERE group_id = ? AND status = 'joined' ORDER BY joined_at LIMIT 1");
            $stmt->execute([$groupId]);
            $next = $stmt->fetchColumn();

            if ($next) {
                $pdo->prepare("UPDATE group_members SET role = 'organizer' WHERE group_id = ? AND user_id = ?")->execute([$groupId, $next]);
                $pdo->prepare('UPDATE travel_groups SET creator_id = ? WHERE id = ?')->execute([$next, $groupId]);
            } else {
                $pdo->prepare("UPDATE travel_groups SET status = 'cancelled' WHERE id = ?")->execute([$groupId]);
            }
        }

        update_fullness($groupId);
        $pdo->commit();
        return '';
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

/** Switch between 'forming' and 'full' depending on the member count. */
function update_fullness(int $groupId): void
{
    db()->prepare(
        "UPDATE travel_groups g
         SET g.status = CASE
             WHEN (SELECT COUNT(*) FROM group_members m WHERE m.group_id = g.id AND m.status = 'joined') >= g.max_members THEN 'full'
             ELSE 'forming' END
         WHERE g.id = ? AND g.status IN ('forming','full')"
    )->execute([$groupId]);
}

/** Can the organiser confirm the group? '' = yes. */
function confirm_problem(array $group, int $userId): string
{
    if (!is_organizer($group, $userId)) {
        return 'Only the organiser can confirm the group.';
    }
    if (!in_array($group['status'], ['forming', 'full'], true)) {
        return 'This group is already ' . $group['status'] . '.';
    }
    if ((int) $group['member_count'] < (int) $group['min_members']) {
        $need = (int) $group['min_members'] - (int) $group['member_count'];
        return "The minimum headcount is not reached yet: $need more " . ($need === 1 ? 'member is' : 'members are') . ' needed.';
    }
    return '';
}

function confirm_group(array $group, int $userId): string
{
    if ($problem = confirm_problem($group, $userId)) {
        return $problem;
    }
    db()->prepare("UPDATE travel_groups SET status = 'confirmed', confirmed_at = NOW() WHERE id = ? AND status IN ('forming','full')")
        ->execute([$group['id']]);
    return '';
}

/** Can the organiser mark the trip as completed? '' = yes. */
function complete_problem(array $group, int $userId): string
{
    if (!is_organizer($group, $userId)) {
        return 'Only the organiser can mark the trip as completed.';
    }
    if ($group['status'] !== 'confirmed') {
        return 'Only confirmed trips can be completed.';
    }
    $unpaid = count(unpaid_members((int) $group['id']));
    if ($unpaid > 0) {
        return "$unpaid " . ($unpaid === 1 ? 'member has' : 'members have') . ' not paid the booking yet.';
    }
    $open = open_settlement_count((int) $group['id']);
    if ($open > 0) {
        return "$open settlement " . ($open === 1 ? 'transfer is' : 'transfers are') . ' not confirmed yet. Everyone must be settled first.';
    }
    return '';
}

function complete_group(array $group, int $userId): string
{
    if ($problem = complete_problem($group, $userId)) {
        return $problem;
    }
    db()->prepare("UPDATE travel_groups SET status = 'completed', completed_at = NOW() WHERE id = ? AND status = 'confirmed'")
        ->execute([$group['id']]);
    return '';
}

/** Groups of a user (any status), newest trips first. */
function user_groups(int $userId): array
{
    return fetch_groups(
        "g.id IN (SELECT group_id FROM group_members WHERE user_id = ? AND status = 'joined')",
        [$userId],
        "FIELD(g.status, 'confirmed','forming','full','completed','cancelled'), g.start_date"
    );
}
