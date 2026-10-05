<?php
/**
 * users.php
 * ---------
 * Database functions about students: finding users, university email
 * checks, verification/reset tokens, tags and profile statistics.
 */

// ---------------------------------------------------------------------
// Finding users
// ---------------------------------------------------------------------

/** One user + university + profile, or null. */
function find_user_by_id(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT u.*, un.name AS university_name, un.short_name AS university_short, un.email_domain AS university_domain,
                p.gender, p.phone, p.city, p.bio, p.profile_photo, p.budget_min, p.budget_max,
                p.group_size_min, p.group_size_max, p.trip_type_pref, p.accommodation_pref, p.show_profile
         FROM users u
         LEFT JOIN universities un    ON un.id = u.university_id
         LEFT JOIN student_profiles p ON p.user_id = u.id
         WHERE u.id = ?'
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function find_user_by_email(string $email): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([strtolower(trim($email))]);
    return $stmt->fetch() ?: null;
}

// ---------------------------------------------------------------------
// Universities & email domains
// ---------------------------------------------------------------------

function get_universities(): array
{
    return db()->query('SELECT * FROM universities WHERE is_active = 1 ORDER BY name')->fetchAll();
}

function find_university(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM universities WHERE id = ? AND is_active = 1');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Does the email belong to the university's domain?
 * Sub-domains are allowed:  x@g.bracu.ac.bd  matches  bracu.ac.bd
 * but                       x@gmail.com      does not match anything.
 */
function email_matches_domain(string $email, string $domain): bool
{
    $emailDomain = strtolower(substr(strrchr($email, '@') ?: '', 1));
    $domain      = strtolower($domain);

    return $emailDomain !== ''
        && ($emailDomain === $domain || str_ends_with($emailDomain, '.' . $domain));
}

/**
 * Does the user's email really belong to their university's domain?
 * Only then do we show "University email". Otherwise the university is
 * self-declared (chosen at registration) and we say so honestly.
 */
function has_university_email(array $user): bool
{
    return !empty($user['university_domain']) && email_matches_domain($user['email'], $user['university_domain']);
}

/**
 * Is this email allowed for this university?
 * Controlled by REQUIRE_UNIVERSITY_EMAIL in config.php:
 *   false -> any valid email is allowed (development/demo)
 *   true  -> the email must match the university's domain
 */
function email_allowed_for_university(string $email, array $university): bool
{
    if (!REQUIRE_UNIVERSITY_EMAIL) {
        return true;
    }
    return email_matches_domain($email, $university['email_domain']);
}

/** Error message shown when the strict rule rejects an email. */
function university_email_error(array $university): string
{
    return 'This is not a ' . $university['name'] . ' email. Use your @' . $university['email_domain'] . ' address.';
}

// ---------------------------------------------------------------------
// Tokens for email verification and password reset
// We create a random token, put the REAL token in the link, and save only
// its SHA-256 HASH in the database. When the link is opened we hash the
// token from the URL and look for that hash.
// ---------------------------------------------------------------------

function token_hash(string $token): string
{
    return hash('sha256', $token);
}

/** Create a new verification token. Returns the full link to show/email. */
function create_verification_link(int $userId): string
{
    $token = bin2hex(random_bytes(32));   // 64 random hex characters

    db()->prepare('UPDATE users SET verify_token_hash = ?, verify_token_expires = ?, verify_sent_at = ? WHERE id = ?')
        ->execute([
            token_hash($token),
            date('Y-m-d H:i:s', time() + VERIFY_TOKEN_HOURS * 3600),
            date('Y-m-d H:i:s'),
            $userId,
        ]);

    return APP_URL . '/verify.php?token=' . $token;
}

/** Create a new password reset token. Returns the full link. */
function create_reset_link(int $userId): string
{
    $token = bin2hex(random_bytes(32));

    db()->prepare('UPDATE users SET reset_token_hash = ?, reset_token_expires = ? WHERE id = ?')
        ->execute([token_hash($token), date('Y-m-d H:i:s', time() + RESET_TOKEN_MINUTES * 60), $userId]);

    return APP_URL . '/reset-password.php?token=' . $token;
}

/** Find the user a reset token belongs to (only if it hasn't expired). */
function find_user_by_reset_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE reset_token_hash = ? AND reset_token_expires > ?');
    $stmt->execute([token_hash($token), date('Y-m-d H:i:s')]);
    return $stmt->fetch() ?: null;
}

/** Seconds left before "Resend" is allowed again (0 = allowed now). */
function resend_wait_seconds(?string $sentAt): int
{
    if (!$sentAt) {
        return 0;
    }
    return max(0, strtotime($sentAt) + RESEND_COOLDOWN_SECONDS - time());
}

// ---------------------------------------------------------------------
// Passwords
// ---------------------------------------------------------------------

/** Returns an error message, or '' if the password is acceptable. */
function password_problem(string $password): string
{
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Use at least one letter and one number.';
    }
    return '';
}

// ---------------------------------------------------------------------
// Tags (travel interests)
// ---------------------------------------------------------------------

function all_tags(): array
{
    return db()->query('SELECT * FROM tags ORDER BY id')->fetchAll();
}

function user_tags(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT t.* FROM tags t JOIN user_tags ut ON ut.tag_id = t.id WHERE ut.user_id = ? ORDER BY t.id'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------
// Choices for the "Trip Settings" drop-downs.
// The database stores numbers (min/max); these lists turn them into labels.
// ---------------------------------------------------------------------

const GROUP_SIZE_OPTIONS = [
    '2-3'  => ['label' => '2–3 people',  'min' => 2, 'max' => 3],
    '4-6'  => ['label' => '4–6 people',  'min' => 4, 'max' => 6],
    '7-12' => ['label' => '7–12 people', 'min' => 7, 'max' => 12],
];

const BUDGET_OPTIONS = [
    '0-1000'      => ['label' => 'Under ৳1,000',      'min' => 0,     'max' => 1000],
    '1000-2000'   => ['label' => '৳1,000 – ৳2,000',   'min' => 1000,  'max' => 2000],
    '2000-5000'   => ['label' => '৳2,000 – ৳5,000',   'min' => 2000,  'max' => 5000],
    '5000-10000'  => ['label' => '৳5,000 – ৳10,000',  'min' => 5000,  'max' => 10000],
    '10000-20000' => ['label' => '৳10,000 – ৳20,000', 'min' => 10000, 'max' => 20000],
];

const TRIP_TYPE_OPTIONS = [
    'any'       => 'Any trip type',
    'day_trip'  => 'Day trips',
    'overnight' => 'Weekend / overnight trips',
    'eco_tour'  => 'Eco tours',
];

const ACCOMMODATION_OPTIONS = [
    'any'     => 'No preference',
    'shared'  => 'Shared',
    'private' => 'Private',
];

/** Find which option key matches the saved min/max (e.g. '4-6'), or '' if none. */
function option_key_for(array $options, $min, $max): string
{
    foreach ($options as $key => $opt) {
        if ((int) $opt['min'] === (int) $min && (int) $opt['max'] === (int) $max) {
            return $key;
        }
    }
    return '';
}

// ---------------------------------------------------------------------
// Profile statistics (all calculated from the database)
// ---------------------------------------------------------------------

/**
 * Numbers shown on the profile: rating, trips, groups.
 *   trips  = completed trips the student was a member of
 *   groups = all groups joined or organised (not cancelled)
 */
function user_stats(int $userId): array
{
    $stmt = db()->prepare('SELECT ROUND(AVG(score), 1) AS avg_score, COUNT(*) AS total FROM ratings WHERE rated_user_id = ?');
    $stmt->execute([$userId]);
    $rating = $stmt->fetch();

    $stmt = db()->prepare(
        "SELECT SUM(g.status = 'completed') AS trips, COUNT(*) AS `groups`
         FROM group_members gm JOIN travel_groups g ON g.id = gm.group_id
         WHERE gm.user_id = ? AND gm.status = 'joined' AND g.status <> 'cancelled'"
    );
    $stmt->execute([$userId]);
    $counts = $stmt->fetch();

    return [
        'rating'        => $rating['avg_score'],        // null when no reviews yet
        'reviews_count' => (int) $rating['total'],
        'trips'         => (int) $counts['trips'],
        'groups'        => (int) $counts['groups'],
    ];
}

/** Average rating received (e.g. 4.8), or null when nobody rated them yet. */
function user_rating(int $userId): ?float
{
    $stmt = db()->prepare('SELECT ROUND(AVG(score), 1) FROM ratings WHERE rated_user_id = ?');
    $stmt->execute([$userId]);
    $value = $stmt->fetchColumn();
    return $value === null ? null : (float) $value;
}

/**
 * Which profile fields are still empty. An empty array means "complete".
 */
function missing_profile_fields(array $user, array $tags): array
{
    $missing = [];
    if (empty($user['profile_photo'])) $missing[] = 'profile photo';
    if (empty($user['bio']))           $missing[] = 'about me';
    if (empty($user['city']))          $missing[] = 'city';
    if (empty($user['phone']))         $missing[] = 'phone number';
    if (empty($user['budget_max']))    $missing[] = 'typical budget';
    if (empty($user['group_size_max'])) $missing[] = 'group size';
    if (count($tags) === 0)            $missing[] = 'travel interests';
    return $missing;
}
