<?php
/**
 * bootstrap.php
 * -------------
 * The first line of EVERY page:
 *     require_once __DIR__ . '/includes/bootstrap.php';
 *
 * It loads the settings, the database connection, the helper functions
 * and starts the session, so the page can use all of them.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/pricing.php';
require_once __DIR__ . '/groups.php';
require_once __DIR__ . '/money.php';
require_once __DIR__ . '/matching.php';
require_once __DIR__ . '/auth.php';
