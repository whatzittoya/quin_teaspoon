<?php
/**
 * QUINOS — Automated Sales Upload (CLI)
 *
 * Exports one CSV per category (see src/SalesCategories.php) and uploads each
 * into its own SFTP folder under SFTP_REMOTE_DIR.
 *
 * Usage: php upload_sales.php [YYYY-MM-DD] [category-key]
 *   No date     -> yesterday (the scheduler runs the following morning).
 *   No category -> all 18 categories.
 */

use App\DatabaseConnectionFactory;
use App\EnvConfig;
use App\SalesCategories;
use App\SalesExport;

$baseDir = dirname(__DIR__);
require $baseDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

function logLine(string $level, string $message): void
{
    echo date('Y-m-d H:i:s') . " [{$level}] {$message}\n";
}

// Check enabled flag
$enabledFile = __DIR__ . DIRECTORY_SEPARATOR . '.enabled';
if (!file_exists($enabledFile)) {
    logLine('SKIP', 'Scheduler is paused (no .enabled flag)');
    exit(0);
}

EnvConfig::load($baseDir . DIRECTORY_SEPARATOR . '.env');
$dbFactory = new DatabaseConnectionFactory($_ENV);

try {
    $db = $dbFactory->default();
} catch (Throwable $e) {
    logLine('ERROR', 'DB connection failed: ' . $e->getMessage());
    exit(1);
}

$date = $argv[1] ?? date('Y-m-d', strtotime('-1 day'));

$categories = SalesCategories::all();
if (!empty($argv[2])) {
    $one = SalesCategories::find($argv[2]);
    if ($one === null) {
        logLine('ERROR', "Unknown category '{$argv[2]}'. Known: " . implode(', ', SalesCategories::keys()));
        exit(1);
    }
    $categories = [$one];
}

logLine('START', "Processing sales for {$date} (" . count($categories) . ' categories)');

$dpStmt = $db->prepare('SELECT id FROM tbl_daily_procedures WHERE DATE(date) = :date AND closed IS NOT NULL LIMIT 1');
$dpStmt->execute(['date' => $date]);
if (!$dpStmt->fetch()) {
    logLine('SKIP', "Daily procedure not closed for {$date} — upload aborted");
    exit(0);
}

$exportDir = $baseDir . DIRECTORY_SEPARATOR . 'exports';
if (!is_dir($exportDir)) {
    mkdir($exportDir, 0755, true);
}

$uploadEnabled = ($_ENV['UPLOAD_ENABLED'] ?? 'true') === 'true';
$alreadyUploaded = SalesExport::uploadedByDate($db, $date)[$date] ?? [];

$anyData = false;
$hadError = false;
$uploadedNow = 0;

foreach ($categories as $category) {
    $key = $category['key'];

    if (isset($alreadyUploaded[$key])) {
        logLine('SKIP', "{$key}: already uploaded for {$date}");
        continue;
    }

    try {
        $sourceDb = $dbFactory->forCategory($category);
        $rows = SalesExport::fetchRows($sourceDb, $date, $category);
    } catch (Throwable $e) {
        $hadError = true;
        logLine('ERROR', "{$key}: database query failed: " . $e->getMessage());
        continue;
    }
    if (empty($rows)) {
        logLine('SKIP', "{$key}: no data");
        continue;
    }
    $anyData = true;

    $token = SalesExport::randomToken();
    $remoteName = SalesExport::csvFilename($date, $category, $token);
    $localName = SalesExport::localFilename($date, $category, $token);
    $localPath = $exportDir . DIRECTORY_SEPARATOR . $localName;
    file_put_contents($localPath, SalesExport::buildCsv($rows, $category));
    logLine('INFO', "{$key}: saved {$localName} (" . count($rows) . ' rows)');

    if (!$uploadEnabled) {
        logLine('SKIP', "{$key}: SFTP upload disabled");
        continue;
    }

    $upload = SalesExport::sftpUpload($localPath, $remoteName, $category);
    if (($upload['exit_code'] ?? 1) !== 0) {
        $hadError = true;
        logLine('ERROR', "{$key}: upload failed");
        logLine('ERROR', $upload['output'] ?? 'Unknown upload error');
        continue;
    }

    SalesExport::recordUpload($db, $date, $category, $remoteName, count($rows));
    $uploadedNow++;
    logLine('OK', "{$key}: " . ($upload['output'] ?? "Uploaded {$remoteName}"));
}

if (!$anyData) {
    logLine('SKIP', "No sales data for {$date}");
    exit(0);
}

if ($uploadEnabled && !$hadError && SalesExport::markTrobexIfComplete(
    $db,
    $date,
    static fn (array $category) => $dbFactory->forCategory($category)
)) {
    logLine('OK', "All categories uploaded for {$date} — marked trobex = 1");
}

if ($hadError) {
    logLine('DONE', "Completed with errors ({$uploadedNow} uploaded)");
    exit(1);
}

logLine('DONE', "Sales upload complete ({$uploadedNow} uploaded)");
