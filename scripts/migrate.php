<?php
declare(strict_types=1);

/**
 * Inventory Intelligence Platform migration runner.
 * Default mode is read-only. Apply requires an explicit database name and backup confirmation.
 *
 * Dry-run: php scripts/migrate.php
 * Status:   php scripts/migrate.php --status
 * Apply:    php scripts/migrate.php --apply --expect-db=DATABASE --backup-confirmed
 * One file: php scripts/migrate.php --apply --only=019_example.sql --expect-db=DATABASE --backup-confirmed
 *
 * MySQL/MariaDB DDL can implicitly commit. If a migration fails, inspect the schema
 * before retrying because some statements may already have succeeded.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERROR: Migration runner can only be executed from CLI.\n");
    exit(1);
}

$options = getopt('', ['help', 'status', 'apply', 'expect-db:', 'target-db:', 'only:', 'backup-confirmed']);
$knownOptions = ['help', 'status', 'apply', 'expect-db', 'target-db', 'only', 'backup-confirmed'];
foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--')) {
        fwrite(STDERR, "ERROR: Unexpected argument: {$arg}\n"); exit(1);
    }
    $name = explode('=', substr($arg, 2), 2)[0];
    if (!in_array($name, $knownOptions, true)) {
        fwrite(STDERR, "ERROR: Unknown option: --{$name}\n"); exit(1);
    }
}

if (isset($options['help'])) {
    echo "Migration runner options:\n"
        . "  --status              Read-only status report\n"
        . "  --apply               Apply pending migrations (otherwise dry-run)\n"
        . "  --expect-db=DATABASE  Required with --apply; must match selected DB\n"
        . "  --target-db=DATABASE  Connect to existing DB using .env credentials\n"
        . "  --only=FILE.sql       Optional one migration; earlier migrations must be applied\n"
        . "  --backup-confirmed    Required with --apply\n";
    exit(0);
}

$apply = isset($options['apply']);
if ($apply && isset($options['status'])) {
    fwrite(STDERR, "ERROR: Use either --status or --apply, not both.\n");
    exit(1);
}
$expectedDb = trim((string)($options['expect-db'] ?? ''));
$only = trim((string)($options['only'] ?? ''));
$targetDb = trim((string)($options['target-db'] ?? ''));
if (isset($options['target-db']) && ($targetDb === '' || !preg_match('/^[A-Za-z0-9_]+$/D', $targetDb))) {
    fwrite(STDERR, "ERROR: Invalid --target-db value.\n"); exit(1);
}
if ($apply && $targetDb !== '' && !hash_equals($targetDb, $expectedDb)) {
    fwrite(STDERR, "ERROR: --target-db and --expect-db must match.\n"); exit(1);
}

if ($apply && $expectedDb === '') {
    fwrite(STDERR, "ERROR: --apply requires --expect-db=DATABASE.\n");
    exit(1);
}
if ($apply && !isset($options['backup-confirmed'])) {
    fwrite(STDERR, "ERROR: --apply requires --backup-confirmed after verifying a usable backup.\n");
    exit(1);
}
if ($only !== '' && (basename($only) !== $only || !preg_match('/^\d{3}_[A-Za-z0-9_-]+\.sql$/', $only))) {
    fwrite(STDERR, "ERROR: Invalid --only filename. Use a name like 019_example.sql.\n");
    exit(1);
}

try {
    if ($targetDb === '') {
        require_once __DIR__ . '/../config/db.php';
        if (!isset($pdo) || !$pdo instanceof PDO) {
            throw new RuntimeException('config/db.php did not provide PDO variable $pdo.');
        }
    } else {
        // Uses configured credentials; does not modify .env or create a database.
        require_once __DIR__ . '/../config/env.php';
        $host = (string)($_ENV['DB_HOST'] ?? '127.0.0.1');
        $user = (string)($_ENV['DB_USER'] ?? '');
        $password = (string)($_ENV['DB_PASSWORD'] ?? '');
        $charset = (string)($_ENV['DB_CHARSET'] ?? 'utf8mb4');
        $port = (string)($_ENV['DB_PORT'] ?? '');
        if ($user === '' || !preg_match('/^[A-Za-z0-9_]+$/D', $charset)) {
            throw new RuntimeException('Invalid or incomplete DB configuration.');
        }
        if ($port !== '' && !ctype_digit($port)) {
            throw new RuntimeException('Invalid DB_PORT.');
        }
        $dsn = "mysql:host={$host};dbname={$targetDb};charset={$charset}";
        if ($port !== '') $dsn .= ";port={$port}";
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    if ($db === '') {
        throw new RuntimeException('No database selected in PDO connection.');
    }
    echo "Database: {$db}\nServer: {$version}\n";
    if ($apply && !hash_equals($expectedDb, $db)) {
        throw new RuntimeException("Database mismatch: expected '{$expectedDb}', connected to '{$db}'. No migration run.");
    }

    $dir = __DIR__ . '/../migrations';
    if (!is_dir($dir)) {
        throw new RuntimeException("Migrations directory not found: {$dir}");
    }
    $paths = glob($dir . DIRECTORY_SEPARATOR . '*.sql');
    if ($paths === false) {
        throw new RuntimeException('Unable to read migrations directory.');
    }
    sort($paths, SORT_STRING);
    $files = [];
    foreach ($paths as $path) {
        $name = basename($path);
        if (!preg_match('/^\d{3}_[A-Za-z0-9_-]+\.sql$/', $name)) {
            echo "[WARN] Ignoring non-standard migration filename: {$name}\n";
            continue;
        }
        $files[$name] = $path;
    }
    if ($only !== '' && !isset($files[$only])) {
        throw new RuntimeException("Migration file not found: {$only}");
    }

    $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $check->execute(['migrations']);
    $historyExists = (int)$check->fetchColumn() > 0;

    // Read-only modes never create the history table.
    if (!$historyExists && !$apply) {
        echo "Migration history table: MISSING (no changes made)\n";
        echo "Migration files found: " . count($files) . "\n";
        echo "DRY RUN ONLY. Review this database and migration sequence before initialization.\n";
        exit(0);
    }
    if (!$historyExists) {
        $pdo->exec("CREATE TABLE migrations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            migration VARCHAR(255) NOT NULL,
            executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_migrations_migration (migration)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "[OK] Created migration history table.\n";
        $done = [];
    } else {
        $done = [];
        foreach ($pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $done[(string)$name] = true;
        }
    }

    $pending = [];
    foreach ($files as $name => $path) {
        if (!isset($done[$name])) {
            $pending[$name] = $path;
        }
    }
    if ($only !== '') {
        if (isset($done[$only])) {
            echo "[SKIP] {$only} already recorded.\n";
            exit(0);
        }
        $pending = isset($pending[$only]) ? [$only => $pending[$only]] : [];
    }

    echo "Migration files: " . count($files) . "\n";
    echo "Recorded migrations: " . count($done) . "\n";
    echo "Pending migrations: " . count($pending) . "\n";
    foreach ($pending as $name => $_) {
        echo "[PENDING] {$name}\n";
    }
    if (!$pending) {
        echo "Nothing to do.\n";
        exit(0);
    }
    if (!$apply) {
        echo "DRY RUN ONLY: no database changes made.\n";
        echo "To apply: --apply --expect-db={$db} --backup-confirmed\n";
        exit(0);
    }

    if ($only !== '') {
        foreach ($files as $name => $_path) {
            if (strcmp($name, $only) >= 0) {
                break;
            }
            if (!isset($done[$name])) {
                throw new RuntimeException("Cannot apply {$only}: earlier migration {$name} is pending. Apply in order.");
            }
        }
    }

    $count = 0;
    foreach ($pending as $name => $path) {
        $sql = file_get_contents($path);
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException("Migration file unreadable or empty: {$name}");
        }
        echo "[RUN ] {$name}\n";
        try {
            // Do not wrap MySQL/MariaDB DDL in transactions; DDL may implicitly commit.
            $pdo->exec($sql);
            $stmt = $pdo->prepare('INSERT INTO migrations (migration) VALUES (:migration)');
            $stmt->execute([':migration' => $name]);
            $done[$name] = true;
            $count++;
            echo "[ OK ] {$name}\n";
        } catch (Throwable $e) {
            fwrite(STDERR, "\nERROR: Migration failed: {$name}\n" . $e->getMessage() . "\n");
            fwrite(STDERR, "Runner stopped. DDL may be partially applied; inspect schema before retrying.\n");
            exit(1);
        }
    }
    echo "\nMigration process completed. Executed: {$count}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
