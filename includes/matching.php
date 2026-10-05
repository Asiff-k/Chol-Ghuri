<?php
/**
 * matching.php
 * ------------
 * The explainable matching engine (NOT machine learning).
 *
 * A student's TRIP INTENT (destination, dates, budget, group size, trip type,
 * stay preference, interests) is compared with every existing group.
 *
 * STEP 1 - Hard filters. A group is rejected (and the reason is counted) if:
 *   destination differs, it is closed / already started / full, the dates don't
 *   overlap, a university or gender rule excludes the student, the student
 *   already has a trip on those dates, or the price after joining is over budget.
 *
 * STEP 2 - Score the groups that passed (total 100):
 *   Dates 30 + Budget 30 + Group size 15 + Interests 15 + Trip/stay type 10
 *   Every part returns points AND a sentence explaining them.
 */

const MATCH_WEIGHTS = ['dates' => 30, 'budget' => 30, 'size' => 15, 'interests' => 15, 'type' => 10];

/** Rejection reasons, in the order they are checked. */
const REJECT_REASONS = [
    'destination' => 'Different destination',
    'member'      => 'You are already a member',
    'closed'      => 'No longer taking members (already confirmed)',
    'started'     => 'Trip has already started',
    'full'        => 'Group is already full',
    'dates'       => 'Dates do not overlap yours',
    'university'  => 'Only for another university\'s students',
    'my_uni'      => 'Not from your university (you chose university-only)',
    'gender'      => 'Women-only or men-only rule',
    'conflict'    => 'Clashes with a trip you already joined',
    'budget'      => 'Price after you join is over your budget',
];

/** Helpful advice for the most important blocking reason. */
const REJECT_ADVICE = [
    'dates'      => 'Try widening your date range.',
    'budget'     => 'Try a higher budget, or wait: the price drops as more students join.',
    'full'       => 'Start your own group for these dates, others will find it.',
    'university' => 'Turn off university-only or look for open groups.',
    'my_uni'     => 'Turn off "only my university" to see more groups.',
    'gender'     => 'Look for groups open to everyone.',
    'conflict'   => 'Leave your other trip first, or pick different dates.',
    'closed'     => 'These groups are already booked. Start a new group instead.',
    'started'    => 'Pick dates in the future.',
    'member'     => 'You are already travelling with these groups.',
];

/** Load a trip intent that belongs to the user (or null). */
function find_trip_intent(int $id, int $userId): ?array
{
    $stmt = db()->prepare(
        'SELECT ti.*, d.name AS destination_name FROM trip_intents ti JOIN destinations d ON d.id = ti.destination_id
         WHERE ti.id = ? AND ti.user_id = ?'
    );
    $stmt->execute([$id, $userId]);
    return $stmt->fetch() ?: null;
}

function latest_trip_intent(int $userId): ?array
{
    $stmt = db()->prepare(
        'SELECT ti.*, d.name AS destination_name FROM trip_intents ti JOIN destinations d ON d.id = ti.destination_id
         WHERE ti.user_id = ? ORDER BY ti.created_at DESC, ti.id DESC LIMIT 1'
    );
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

/** '1,4,5' -> [1, 4, 5] */
function intent_tag_ids(array $intent): array
{
    return array_values(array_filter(array_map('intval', explode(',', (string) $intent['tag_ids']))));
}

/**
 * Run the matching engine.
 * Returns [
 *   'matches'    => [ ['group' => .., 'score' => 93, 'parts' => [...], 'price_join' => .., 'price_full' => ..], ... ],
 *   'rejected'   => ['dates' => 2, 'budget' => 1, ...],
 *   'same_destination' => number of groups at the chosen destination,
 *   'cheapest_over_budget' => lowest price among groups rejected only for budget (or null),
 * ]
 */
function match_groups(array $intent, array $user): array
{
    $today   = date('Y-m-d');
    $myTags  = intent_tag_ids($intent);
    $tagNames = array_column(all_tags(), 'name', 'id');

    // Every upcoming or running group (cancelled and finished trips are ignored)
    $groups = fetch_groups("g.status IN ('forming','full','confirmed') AND g.end_date >= CURDATE()");

    $result = ['matches' => [], 'rejected' => [], 'same_destination' => 0, 'cheapest_over_budget' => null];

    foreach ($groups as $g) {
        $reason = null;
        $members = (int) $g['member_count'];
        $priceJoin = per_person_price((int) $g['package_id'], $members + 1);

        if ((int) $g['destination_id'] !== (int) $intent['destination_id']) {
            $reason = 'destination';
        } else {
            $result['same_destination']++;
            if (is_group_member((int) $g['id'], (int) $user['id']))                   $reason = 'member';
            elseif ($g['status'] === 'confirmed')                                     $reason = 'closed';
            elseif ($g['start_date'] < $today)                                        $reason = 'started';
            elseif ($members >= (int) $g['max_members'])                              $reason = 'full';
            elseif ($g['start_date'] > $intent['end_date'] || $g['end_date'] < $intent['start_date']) $reason = 'dates';
            elseif ($g['university_only'] && (int) $user['university_id'] !== (int) $g['organizer_university_id']) $reason = 'university';
            elseif ($intent['university_only'] && (int) $user['university_id'] !== (int) $g['organizer_university_id']) $reason = 'my_uni';
            // (the university rule was checked just above, so anything left here is the gender rule)
            elseif (eligibility_problem($g, $user) !== '')                            $reason = 'gender';
            elseif (date_conflict((int) $user['id'], $g['start_date'], $g['end_date'], (int) $g['id']))      $reason = 'conflict';
            elseif ($priceJoin > (float) $intent['budget_max']) {
                $reason = 'budget';
                $cheapest = $result['cheapest_over_budget'];
                $result['cheapest_over_budget'] = $cheapest === null ? $priceJoin : min($cheapest, $priceJoin);
            }
        }

        if ($reason !== null) {
            $result['rejected'][$reason] = ($result['rejected'][$reason] ?? 0) + 1;
            continue;
        }

        $parts = score_parts($intent, $g, $priceJoin, $myTags, $tagNames);
        $result['matches'][] = [
            'group'      => $g,
            'score'      => array_sum(array_column($parts, 'points')),
            'parts'      => $parts,
            'price_join' => $priceJoin,
            'price_full' => per_person_price((int) $g['package_id'], (int) $g['max_members']),
        ];
    }

    // Best score first; cheaper price wins a tie
    usort($result['matches'], fn($a, $b) => [$b['score'], $a['price_join']] <=> [$a['score'], $b['price_join']]);
    return $result;
}

/**
 * The five score parts for one group. Each: ['label', 'points', 'max', 'text'].
 */
function score_parts(array $intent, array $g, float $priceJoin, array $myTags, array $tagNames): array
{
    $parts = [];

    // ---- Dates (30): how many of the group's trip days fall inside my dates ----
    $overlapStart = max($intent['start_date'], $g['start_date']);
    $overlapEnd   = min($intent['end_date'], $g['end_date']);
    $overlap      = days_between($overlapStart, $overlapEnd);
    $tripDays     = days_between($g['start_date'], $g['end_date']);
    $parts['dates'] = [
        'label'  => 'Dates',
        'points' => (int) round(MATCH_WEIGHTS['dates'] * $overlap / $tripDays),
        'max'    => MATCH_WEIGHTS['dates'],
        'text'   => $overlap === $tripDays
            ? "All $tripDays trip " . ($tripDays === 1 ? 'day falls' : 'days fall') . ' within your dates'
            : "Dates overlap $overlap of $tripDays trip days",
    ];

    // ---- Budget (30): full points up to 75% of budget, then down to 20 at 100% ----
    $budget = (float) $intent['budget_max'];
    $ratio  = $budget > 0 ? $priceJoin / $budget : 1;
    $points = $ratio <= 0.75 ? 30 : (int) round(30 - ($ratio - 0.75) / 0.25 * 10);
    $parts['budget'] = [
        'label'  => 'Budget',
        'points' => max(20, min(30, $points)),
        'max'    => MATCH_WEIGHTS['budget'],
        'text'   => money($priceJoin) . '/person if you join, within your ' . money($budget) . ' budget'
                    . ($budget - $priceJoin >= 1 ? ' (' . money($budget - $priceJoin) . ' to spare)' : ''),
    ];

    // ---- Group size (15): is the group's target size inside my preferred range? ----
    $target = (int) $g['max_members'];
    $min = (int) $intent['group_size_min'];
    $max = (int) $intent['group_size_max'];
    $distance = $target < $min ? $min - $target : ($target > $max ? $target - $max : 0);
    $parts['size'] = [
        'label'  => 'Group size',
        'points' => max(0, MATCH_WEIGHTS['size'] - 5 * $distance),
        'max'    => MATCH_WEIGHTS['size'],
        'text'   => $distance === 0
            ? "Group of $target fits your preferred {$min}–{$max} people"
            : "Group of $target is " . ($target > $max ? 'bigger' : 'smaller') . " than your preferred {$min}–{$max}",
    ];

    // ---- Interests (15): shared tags ----
    $groupTags = array_map('intval', array_column(group_tags((int) $g['id']), 'id'));
    $shared    = array_values(array_intersect($myTags, $groupTags));
    if (!$myTags) {
        $parts['interests'] = ['label' => 'Interests', 'points' => MATCH_WEIGHTS['interests'], 'max' => MATCH_WEIGHTS['interests'],
                               'text' => 'You did not choose interests, so any style fits'];
    } else {
        $names = array_map(fn($id) => $tagNames[$id] ?? '', $shared);
        $parts['interests'] = [
            'label'  => 'Interests',
            'points' => (int) round(MATCH_WEIGHTS['interests'] * count($shared) / count($myTags)),
            'max'    => MATCH_WEIGHTS['interests'],
            'text'   => $shared
                ? 'Matches ' . count($shared) . ' of your ' . count($myTags) . ' interests (' . implode(', ', $names) . ')'
                : 'None of your interests match this group\'s style',
        ];
    }

    // ---- Trip & stay type (10): 6 for trip type, 4 for accommodation ----
    $typeOk = $intent['trip_type'] === 'any' || $intent['trip_type'] === $g['trip_type'];
    $stayOk = $intent['accommodation_pref'] === 'any' || $g['accommodation_type'] === 'none'
              || $intent['accommodation_pref'] === $g['accommodation_type'];
    $texts  = [];
    $texts[] = TRIP_TYPE_LABELS[$g['trip_type']] . ($typeOk ? ' matches' : ' differs from your choice');
    $texts[] = $g['accommodation_type'] === 'none' ? 'no overnight stay' : ucfirst($g['accommodation_type']) . ' rooms' . ($stayOk ? ' suit you' : ' (you prefer ' . $intent['accommodation_pref'] . ')');
    $parts['type'] = [
        'label'  => 'Trip & stay',
        'points' => ($typeOk ? 6 : 0) + ($stayOk ? 4 : 0),
        'max'    => MATCH_WEIGHTS['type'],
        'text'   => implode(' · ', $texts),
    ];

    return $parts;
}

/**
 * For the zero-match diagnosis: the reason that blocked the most groups at
 * the chosen destination (ignoring "different destination").
 */
function main_blocking_reason(array $rejected): ?string
{
    unset($rejected['destination'], $rejected['member']);   // not real "blocks"
    if (!$rejected) {
        return null;
    }
    arsort($rejected);
    return array_key_first($rejected);
}

/** Save a new trip intent from validated values. Returns its id. */
function save_trip_intent(int $userId, array $v): int
{
    db()->prepare(
        'INSERT INTO trip_intents (user_id, destination_id, start_date, end_date, budget_max, group_size_min, group_size_max,
                                   trip_type, accommodation_pref, tag_ids, university_only)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $userId, $v['destination_id'], $v['start_date'], $v['end_date'], $v['budget_max'],
        $v['group_size_min'], $v['group_size_max'], $v['trip_type'], $v['accommodation_pref'],
        $v['tag_ids'] ? implode(',', $v['tag_ids']) : null, $v['university_only'],
    ]);
    return (int) db()->lastInsertId();
}
