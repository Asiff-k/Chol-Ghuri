<?php
/**
 * edit-profile.php - Edit Profile (design: "Edit Profile")
 *
 * Saves: name, phone, city, about me, profile photo, interests (tags),
 * trip settings (group size, budget, trip type, accommodation) and privacy.
 * University and email are verified, so they can't be changed here.
 */
require_once __DIR__ . '/includes/bootstrap.php';
$me = require_login();

if ($me['role'] !== 'student') {
    redirect('admin/index.php');
}

$allTags = all_tags();
$errors  = [];

// Start the form with what is saved in the database
$form = [
    'full_name'     => $me['full_name'],
    'phone'         => $me['phone'] ?? '',
    'city'          => $me['city'] ?? '',
    'bio'           => $me['bio'] ?? '',
    'group_size'    => option_key_for(GROUP_SIZE_OPTIONS, $me['group_size_min'], $me['group_size_max']),
    'budget'        => option_key_for(BUDGET_OPTIONS, $me['budget_min'], $me['budget_max']),
    'trip_type'     => $me['trip_type_pref'] ?? 'any',
    'accommodation' => $me['accommodation_pref'] ?? 'any',
    'show_profile'  => (int) ($me['show_profile'] ?? 1),
    'gender'        => $me['gender'] ?? '',
    'tags'          => array_column(user_tags((int) $me['id']), 'id'),
];

if (is_post()) {
    verify_csrf();

    // ---- Read and clean the submitted values ----
    $form['full_name']     = trim($_POST['full_name'] ?? '');
    $form['phone']         = preg_replace('/[^0-9+]/', '', $_POST['phone'] ?? '');
    $form['gender']        = in_array($_POST['gender'] ?? '', ['female', 'male', 'other'], true) ? $_POST['gender'] : '';
    $form['city']          = trim($_POST['city'] ?? '');
    $form['bio']           = trim($_POST['bio'] ?? '');
    $form['group_size']    = $_POST['group_size'] ?? '';
    $form['budget']        = $_POST['budget'] ?? '';
    $form['trip_type']     = $_POST['trip_type'] ?? 'any';
    $form['accommodation'] = $_POST['accommodation'] ?? 'any';
    $form['show_profile']  = empty($_POST['show_profile']) ? 0 : 1;
    $form['tags']          = array_map('intval', (array) ($_POST['tags'] ?? []));

    // ---- Validate ----
    if (mb_strlen($form['full_name']) < 2 || mb_strlen($form['full_name']) > 100) {
        $errors['full_name'] = 'Please enter your full name.';
    }
    if ($form['phone'] !== '' && !preg_match('/^(\+?880)?01[3-9]\d{8}$/', $form['phone'])) {
        $errors['phone'] = 'Enter a Bangladeshi mobile number, e.g. 01712345678.';
    }
    if (mb_strlen($form['city']) > 60) {
        $errors['city'] = 'City name is too long.';
    }
    if (mb_strlen($form['bio']) > 600) {
        $errors['bio'] = 'Please keep About Me under 600 characters.';
    }
    if ($form['group_size'] !== '' && !isset(GROUP_SIZE_OPTIONS[$form['group_size']])) {
        $errors['group_size'] = 'Choose a group size from the list.';
    }
    if ($form['budget'] !== '' && !isset(BUDGET_OPTIONS[$form['budget']])) {
        $errors['budget'] = 'Choose a budget from the list.';
    }
    if (!isset(TRIP_TYPE_OPTIONS[$form['trip_type']])) {
        $errors['trip_type'] = 'Choose a trip type from the list.';
    }
    if (!isset(ACCOMMODATION_OPTIONS[$form['accommodation']])) {
        $errors['accommodation'] = 'Choose an accommodation type from the list.';
    }
    $validTagIds  = array_column($allTags, 'id');
    $form['tags'] = array_values(array_intersect($form['tags'], $validTagIds));

    // ---- Profile photo (optional) ----
    $newPhoto = null;
    $upload   = $_FILES['photo'] ?? null;
    if ($upload && $upload['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($upload['error'] !== UPLOAD_ERR_OK) {
            $errors['photo'] = 'The photo could not be uploaded. Please try again.';
        } elseif ($upload['size'] > AVATAR_MAX_BYTES) {
            $errors['photo'] = 'The photo must be 5 MB or smaller.';
        } else {
            // Check the REAL file type from its contents, not the file name
            $mime    = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
            if (!isset($allowed[$mime]) || !getimagesize($upload['tmp_name'])) {
                $errors['photo'] = 'Only JPG or PNG images are allowed.';
            } else {
                // Random file name: users can't overwrite or guess other files
                $newPhoto = 'u' . $me['id'] . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
            }
        }
    }

    // ---- Save ----
    if (!$errors) {
        if ($newPhoto) {
            if (!is_dir(AVATAR_DIR)) {
                mkdir(AVATAR_DIR, 0755, true);
            }
            move_uploaded_file($upload['tmp_name'], AVATAR_DIR . $newPhoto);
            // Delete the old photo file
            if ($me['profile_photo'] && is_file(AVATAR_DIR . $me['profile_photo'])) {
                unlink(AVATAR_DIR . $me['profile_photo']);
            }
        }

        $size   = GROUP_SIZE_OPTIONS[$form['group_size']] ?? null;
        $budget = BUDGET_OPTIONS[$form['budget']] ?? null;

        $pdo = db();
        $pdo->beginTransaction();

        $pdo->prepare('UPDATE users SET full_name = ? WHERE id = ?')->execute([$form['full_name'], $me['id']]);

        $pdo->prepare(
            'UPDATE student_profiles
             SET gender = ?, phone = ?, city = ?, bio = ?, profile_photo = COALESCE(?, profile_photo),
                 group_size_min = ?, group_size_max = ?, budget_min = ?, budget_max = ?,
                 trip_type_pref = ?, accommodation_pref = ?, show_profile = ?
             WHERE user_id = ?'
        )->execute([
            $form['gender'] ?: null,
            $form['phone'] ?: null,
            $form['city'] ?: null,
            $form['bio'] ?: null,
            $newPhoto,
            $size['min'] ?? null,  $size['max'] ?? null,
            $budget['min'] ?? null, $budget['max'] ?? null,
            $form['trip_type'],
            $form['accommodation'],
            $form['show_profile'],
            $me['id'],
        ]);

        // Interests: delete the old list, insert the new one
        $pdo->prepare('DELETE FROM user_tags WHERE user_id = ?')->execute([$me['id']]);
        $insertTag = $pdo->prepare('INSERT INTO user_tags (user_id, tag_id) VALUES (?, ?)');
        foreach ($form['tags'] as $tagId) {
            $insertTag->execute([$me['id'], $tagId]);
        }

        $pdo->commit();

        set_flash('success', 'Your profile has been saved.');
        redirect('profile.php');
    }
}

$pageTitle = 'Edit Profile';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <nav class="breadcrumb" aria-label="Breadcrumb">
        <a href="<?= url('profile.php') ?>">Profile</a> <?= icon('chevron-right', 'icon-sm') ?> <span>Edit Profile</span>
    </nav>
    <div class="page-head">
        <h1>Edit Profile</h1>
        <p>Keep your information up to date so fellow travelers know who they're joining.</p>
    </div>

    <?= render_flash() ?>
    <?php if ($errors): ?>
        <?= alert('error', 'Please fix the highlighted fields below.') ?>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>

        <div class="layout-sidebar">
            <div>
                <!-- Profile photo -->
                <section class="card">
                    <h2 class="card-section-title">Profile Photo</h2>
                    <div class="photo-row">
                        <div id="photo-preview"><?= avatar($me['profile_photo'], $me['full_name'], 'lg') ?></div>
                        <div>
                            <label for="photo" class="btn btn-grey btn-sm" style="margin:0"><?= icon('camera', 'icon-sm') ?> Change Photo</label>
                            <input type="file" id="photo" name="photo" accept="image/jpeg,image/png" hidden>
                            <div class="field-help" id="photo-name">JPG or PNG &middot; Max 5MB</div>
                            <?= field_error($errors, 'photo') ?>
                        </div>
                    </div>
                </section>

                <!-- Basic information -->
                <section class="card">
                    <h2 class="card-section-title">Basic Information</h2>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="full_name">Full Name</label>
                            <input class="input" type="text" id="full_name" name="full_name" value="<?= e($form['full_name']) ?>" required>
                            <?= field_error($errors, 'full_name') ?>
                        </div>
                        <div class="form-group">
                            <label for="university" class="label-muted">University <?= icon('badge-check', 'icon-sm text-primary') ?></label>
                            <input class="input" type="text" id="university" value="<?= e($me['university_name']) ?>" readonly>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="phone">Phone Number</label>
                            <input class="input" type="tel" id="phone" name="phone" value="<?= e($form['phone']) ?>" placeholder="01XXXXXXXXX">
                            <?= field_error($errors, 'phone') ?>
                            <div class="field-help">Shown only to members of groups you join, for trip coordination.</div>
                        </div>
                        <div class="form-group">
                            <label for="city">Home City</label>
                            <input class="input" type="text" id="city" name="city" value="<?= e($form['city']) ?>" placeholder="e.g. Sylhet">
                            <?= field_error($errors, 'city') ?>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="gender">Gender</label>
                            <select class="input" id="gender" name="gender">
                                <?php foreach (['' => 'Prefer not to say', 'female' => 'Female', 'male' => 'Male', 'other' => 'Other'] as $key => $label): ?>
                                    <option value="<?= $key ?>" <?= $form['gender'] === $key ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="field-help">Only used for women-only / men-only groups. Never shown on your profile.</div>
                        </div>
                    </div>
                    <div class="form-group mb-0">
                        <label for="bio">About Me</label>
                        <textarea class="input" id="bio" name="bio" maxlength="600"
                                  placeholder="What kind of traveler are you? What do you enjoy?"><?= e($form['bio']) ?></textarea>
                        <?= field_error($errors, 'bio') ?>
                    </div>
                </section>

                <!-- Travel preferences -->
                <section class="card">
                    <h2 class="card-section-title" style="margin-bottom:8px">Travel Preferences</h2>
                    <p>Select the tags that best describe your travel style.</p>
                    <div class="tag-picker">
                        <?php foreach ($allTags as $tag): ?>
                            <label class="tag-option">
                                <input type="checkbox" name="tags[]" value="<?= $tag['id'] ?>" <?= in_array((int) $tag['id'], $form['tags'], true) ? 'checked' : '' ?>>
                                <span><?= e($tag['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <h3 style="margin:32px 0 16px">Trip Settings</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="group_size">Preferred Group Size</label>
                            <select class="input" id="group_size" name="group_size">
                                <option value="">Not set</option>
                                <?php foreach (GROUP_SIZE_OPTIONS as $key => $opt): ?>
                                    <option value="<?= e($key) ?>" <?= $form['group_size'] === $key ? 'selected' : '' ?>><?= e($opt['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= field_error($errors, 'group_size') ?>
                        </div>
                        <div class="form-group">
                            <label for="budget">Typical Budget (per person)</label>
                            <select class="input" id="budget" name="budget">
                                <option value="">Not set</option>
                                <?php foreach (BUDGET_OPTIONS as $key => $opt): ?>
                                    <option value="<?= e($key) ?>" <?= $form['budget'] === $key ? 'selected' : '' ?>><?= e($opt['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= field_error($errors, 'budget') ?>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group mb-0">
                            <label for="trip_type">Trip Type</label>
                            <select class="input" id="trip_type" name="trip_type">
                                <?php foreach (TRIP_TYPE_OPTIONS as $key => $label): ?>
                                    <option value="<?= e($key) ?>" <?= $form['trip_type'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group mb-0">
                            <label for="accommodation">Accommodation</label>
                            <select class="input" id="accommodation" name="accommodation">
                                <?php foreach (ACCOMMODATION_OPTIONS as $key => $label): ?>
                                    <option value="<?= e($key) ?>" <?= $form['accommodation'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </section>
            </div>

            <!-- Sidebar -->
            <div>
                <section class="card verified-card">
                    <h2 class="card-title"><span class="icon-circle"><?= icon('shield-check') ?></span> Account</h2>
                    <div class="verify-list">
                        <div class="verify-row">
                            <div><small>University</small><span><?= e($me['university_name']) ?></span></div>
                            <?php if (has_university_email($me)): ?><span class="ok" title="Matches your university email"><?= icon('check') ?></span><?php else: ?><small class="text-muted">self-declared</small><?php endif; ?>
                        </div>
                        <div class="verify-row">
                            <div><small>Email</small><span><?= e($me['email']) ?></span></div>
                            <span class="ok" title="Verified"><?= icon('check') ?></span>
                        </div>
                    </div>
                    <p class="text-small mb-0" style="margin-top:12px">Your email and university are set when you register and can't be changed here.</p>
                </section>

                <section class="card">
                    <h2 class="card-title">Profile Privacy</h2>
                    <div class="setting-row">
                        <div>
                            <strong>Show my profile to students</strong>
                            <p>When enabled, other logged-in students can view your profile. When off, only you (and admins) can see it.</p>
                        </div>
                        <label class="switch" aria-label="Show my profile to students">
                            <input type="checkbox" name="show_profile" value="1" <?= $form['show_profile'] ? 'checked' : '' ?>>
                            <span class="switch-track"></span>
                        </label>
                    </div>

                    <hr class="divider">
                    <h2 class="card-title">Account Actions</h2>
                    <a class="action-link" href="<?= url('change-password.php') ?>">Change Password <?= icon('chevron-right', 'icon-sm') ?></a>
                </section>

                <div class="form-actions">
                    <a href="<?= url('profile.php') ?>" class="btn btn-light">Cancel</a>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </div>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
