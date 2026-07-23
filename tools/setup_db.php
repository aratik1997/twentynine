<?php
declare(strict_types=1);

/**
 * One-time schema installer — run this once through your browser on the
 * actual hosting environment (StackCP etc.), since only PHP running there
 * can reach the DB host. Shows exactly which statement succeeds or fails,
 * instead of guessing from a phpMyAdmin import.
 *
 * DELETE THIS FILE after a successful run — it's not something you want
 * left reachable on a public site indefinitely.
 */

header('Content-Type: text/plain; charset=utf-8');

if (($_GET['confirm'] ?? '') !== 'yes') {
    echo "This will create the game tables in your configured database.\n";
    echo "Re-visit this URL with ?confirm=yes to proceed.\n";
    exit;
}

require_once __DIR__ . '/../config/db.php';

echo "Connecting using DB_HOST=" . DB_HOST . " DB_NAME=" . DB_NAME . " DB_USER=" . DB_USER . " ...\n";
$pdo = db();
echo "Connected OK.\n\n";

$sql = file_get_contents(__DIR__ . '/../sql/schema.sql');
if ($sql === false) {
    echo "Could not read sql/schema.sql\n";
    exit(1);
}

// Strip comments (including trailing inline ones — some column comments in
// this schema contain semicolons, which would otherwise corrupt the split
// below), then split on statement-terminating semicolons.
$sql = preg_replace('/--.*$/m', '', $sql);
$statements = array_filter(array_map('trim', explode(';', $sql)));

$ok = 0;
$failed = 0;
foreach ($statements as $stmt) {
    if ($stmt === '') {
        continue;
    }
    $label = substr(preg_replace('/\s+/', ' ', $stmt), 0, 70);
    try {
        $pdo->exec($stmt);
        echo "OK   : $label\n";
        $ok++;
    } catch (Throwable $e) {
        echo "FAIL : $label\n";
        echo "       -> " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "\n$ok statement(s) succeeded, $failed failed.\n";

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo "\nTables now in this database: " . (count($tables) ? implode(', ', $tables) : '(none)') . "\n";

if ($failed === 0) {
    echo "\nAll good — delete tools/setup_db.php now.\n";
}
