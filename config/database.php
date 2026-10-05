<?php
/**
 * database.php
 * ------------
 * Creates the connection to the MySQL/MariaDB database using PDO.
 *
 * How to use it in any page:
 *     $stmt = db()->prepare('SELECT * FROM packages WHERE id = ?');
 *     $stmt->execute([$id]);
 *     $package = $stmt->fetch();
 *
 * Always use prepare() + execute() with ? placeholders when a query
 * contains user input. This protects against SQL injection.
 */

require_once __DIR__ . '/config.php';

/**
 * Returns the database connection.
 * The connection is created only once per page load and then reused
 * (that is what the "static" variable does).
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // throw an error if a query fails
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // rows come back as ['column' => value]
            PDO::ATTR_EMULATE_PREPARES   => false,                  // use real prepared statements
        ];

        // If this fails it throws a PDOException (wrong password, MySQL stopped, DB missing...).
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

        // Make MySQL use Bangladesh time too, so PHP and MySQL agree on "now".
        $pdo->exec("SET time_zone = '+06:00'");
    }

    return $pdo;
}
