<?php
/**
 * api/group-status.php?id=1
 * -------------------------
 * Small JSON endpoint: the live member count and price of a group.
 * The group room (assets/js/main.js) calls it every few seconds so the
 * numbers update when someone joins or leaves. You can also test it in
 * Postman: GET http://localhost/chol-ghuri/api/group-status.php?id=1
 *
 * The price is calculated here on the server (the source of truth).
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$group = get_group(int_param($_GET, 'id'));
if (!$group || $group['status'] === 'cancelled') {
    json_response(['error' => 'Group not found'], 404);
}

$members = (int) $group['member_count'];
$price   = per_person_price((int) $group['package_id'], max(1, $members));

json_response([
    'id'              => (int) $group['id'],
    'status'          => $group['status'],
    'members'         => $members,
    'max_members'     => (int) $group['max_members'],
    'min_members'     => (int) $group['min_members'],
    'spots_left'      => max(0, (int) $group['max_members'] - $members),
    'minimum_reached' => $members >= (int) $group['min_members'],
    'price_per_person'      => $price,
    'price_per_person_text' => money($price),
    'price_if_full'         => per_person_price((int) $group['package_id'], (int) $group['max_members']),
    'fill_percent'          => (int) round(100 * $members / max(1, (int) $group['max_members'])),
]);
