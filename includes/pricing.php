<?php
/**
 * pricing.php
 * -----------
 * The dynamic per-person price of a package.
 *
 *   per_person_price(n) = (sum of shared costs) / n  +  (sum of per-person costs)
 *
 * Example (Sajek: shared ৳12,000, per person ৳1,500):
 *   2 people -> 6,000 + 1,500 = ৳7,500 each
 *   4 people -> 3,000 + 1,500 = ৳4,500 each
 *   6 people -> 2,000 + 1,500 = ৳3,500 each
 */

/** ['shared' => total shared cost, 'per_person' => total per-person cost] */
function package_cost_totals(int $packageId): array
{
    static $cache = [];

    if (!isset($cache[$packageId])) {
        $stmt = db()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN cost_type = 'shared'     THEN amount END), 0) AS shared,
                    COALESCE(SUM(CASE WHEN cost_type = 'per_person' THEN amount END), 0) AS per_person
             FROM package_costs WHERE package_id = ?"
        );
        $stmt->execute([$packageId]);
        $cache[$packageId] = array_map('floatval', $stmt->fetch());
    }

    return $cache[$packageId];
}

/**
 * Price each member pays when the group has $members people.
 * Rounded UP to whole taka, so members together always cover the full cost.
 */
function per_person_price(int $packageId, int $members): float
{
    $members = max(1, $members);
    $totals  = package_cost_totals($packageId);

    return ceil($totals['shared'] / $members + $totals['per_person']);
}

/**
 * Price for every group size from $from to $to:
 *   [2 => 7500, 3 => 5500, 4 => 4500, ...]
 * Used for the "your group gets cheaper as it grows" timeline and charts.
 */
function price_timeline(int $packageId, int $from, int $to): array
{
    $prices = [];
    for ($n = max(1, $from); $n <= $to; $n++) {
        $prices[$n] = per_person_price($packageId, $n);
    }
    return $prices;
}

/** Lowest possible price per person (when the group is at maximum size). */
function starting_price(array $package): float
{
    return per_person_price((int) $package['id'], (int) $package['max_group_size']);
}

/** All cost items of a package, shared items first. */
function package_cost_items(int $packageId): array
{
    $stmt = db()->prepare("SELECT * FROM package_costs WHERE package_id = ? ORDER BY cost_type = 'per_person', amount DESC");
    $stmt->execute([$packageId]);
    return $stmt->fetchAll();
}
