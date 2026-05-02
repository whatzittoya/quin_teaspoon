<?php
/**
 * QUINOS — Automated Sales Upload (CLI)
 *
 * Generates two CSV files per date:
 * 1) invoiced sales   -> primary SFTP
 * 2) no_invoice sales -> secondary SFTP
 *
 * Usage: php upload_sales.php [YYYY-MM-DD]
 *   If no date is given, defaults to current date.
 */

$baseDir = dirname(__DIR__);

const SEGMENT_INVOICED = 'invoiced';
const SEGMENT_NO_INVOICE = 'no_invoice';

const REPORT_QUERY_INVOICED = "
    SELECT STRAIGHT_JOIN
        s.invoice_id AS invoice_id,
        s.date AS date,
        sl.id AS id,
        s.type AS salesType,
        sl.description AS description,
        (sl.quantity - sl.voidQuantity) AS quantity,
        sl.unitPrice AS unitPrice,
        sl.discountAmount AS discountAmount,
        item.code AS item_code,
        parentcode.code AS parent_code,
        s.tax1 AS vat_percent
    FROM tbl_sales s
    JOIN tbl_invoices inv ON s.invoice_id = inv.id
    JOIN tbl_sales_lines sl ON sl.sales_id = s.id
    JOIN tbl_items item ON sl.item_id = item.id
    JOIN tbl_categories cat ON item.category_id = cat.id
    JOIN tbl_departments dept ON cat.department_id = dept.id
    JOIN tbl_employees emp ON sl.employee_id = emp.id
    LEFT JOIN tbl_sales_lines parent ON sl.parent_id = parent.id
    LEFT JOIN tbl_items parentcode ON parent.item_id = parentcode.id
    WHERE s.closed = 1
        AND s.date BETWEEN :from AND :to
        AND s.invoice_id IS NOT NULL
        AND s.voidCheck = 0
        AND sl.quantity > 0
        AND (sl.unitPrice > 0 OR item.noReport = 0)
        AND dept.name = 'Restaurant'
        AND ROUND(
            ((sl.quantity - sl.voidQuantity) * sl.unitPrice)
            - sl.discountAmount + sl.serviceChargeAmount + sl.tax1Amount,
            2
        ) <> 0
    ORDER BY s.invoice_id, sl.id
";

const REPORT_QUERY_NO_INVOICE = "
    SELECT STRAIGHT_JOIN
        s.invoice_id AS invoice_id,
        s.date AS date,
        sl.id AS id,
        s.type AS salesType,
        sl.description AS description,
        (sl.quantity - sl.voidQuantity) AS quantity,
        sl.unitPrice AS unitPrice,
        sl.discountAmount AS discountAmount,
        item.code AS item_code,
        parentcode.code AS parent_code,
        s.tax1 AS vat_percent
    FROM tbl_sales s
    JOIN tbl_sales_lines sl ON sl.sales_id = s.id
    JOIN tbl_items item ON sl.item_id = item.id
    JOIN tbl_categories cat ON item.category_id = cat.id
    JOIN tbl_departments dept ON cat.department_id = dept.id
    JOIN tbl_employees emp ON sl.employee_id = emp.id
    LEFT JOIN tbl_sales_lines parent ON sl.parent_id = parent.id
    LEFT JOIN tbl_items parentcode ON parent.item_id = parentcode.id
    LEFT JOIN tbl_invoices inv_row ON s.invoice_id = inv_row.id
    WHERE s.closed = 1
        AND s.date BETWEEN :from AND :to
        AND (s.invoice_id IS NULL OR inv_row.id IS NULL)
        AND s.voidCheck = 0
        AND sl.quantity > 0
        AND (sl.unitPrice > 0 OR item.noReport = 0)
        AND dept.name = 'Restaurant'
        AND ROUND(
            ((sl.quantity - sl.voidQuantity) * sl.unitPrice)
            - sl.discountAmount + sl.serviceChargeAmount + sl.tax1Amount,
            2
        ) <> 0
    ORDER BY sl.id
";

function logLine(string $level, string $message): void
{
    echo date('Y-m-d H:i:s') . " [{$level}] {$message}\n";
}

function loadEnv(string $envFile): void
{
    if (!file_exists($envFile)) {
        return;
    }
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

function nosalesCsvFilename(string $dateYmd): string
{
    return 'nosales' . date('ymd', strtotime($dateYmd . ' 12:00:00')) . '.csv';
}

function fetchRows(PDO $db, string $from, string $to, string $segment): array
{
    $sql = $segment === SEGMENT_NO_INVOICE ? REPORT_QUERY_NO_INVOICE : REPORT_QUERY_INVOICED;
    $stmt = $db->prepare($sql);
    $stmt->execute(['from' => $from, 'to' => $to]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function buildCsv(array $rows, string $segment): string
{
    $header = 'date;time;article_code;article_descr;quantity;unit_price_excl_vat;amount_excl_vat;discount_excl_vat;vat_percent;parent_article_code';
    $lines = [$header];

    foreach ($rows as $row) {
        $dateStr = date('d/m/Y', strtotime($row['date']));
        $timeStr = date('H:i:s', strtotime($row['date']));
        $code = str_replace(';', '', $row['item_code'] ?? '');
        $desc = str_replace(';', '', $row['description'] ?? '');
        $qty = (float) ($row['quantity'] ?? 0);
        $vatPct = (float) ($row['vat_percent'] ?? 0);
        $unitPrice = (float) ($row['unitPrice'] ?? 0);
        $discount = (float) ($row['discountAmount'] ?? 0);

        $unitPriceExcl = round($unitPrice, 2);
        $amountExcl = round(($qty * $unitPrice - $discount), 2);
        $discountExcl = round($discount, 2);

        if ($segment === SEGMENT_NO_INVOICE) {
            $unitPriceExcl = 0.00;
            $amountExcl = 0.00;
        }

        $parent = str_replace(';', '', $row['parent_code'] ?? '');

        $lines[] = implode(';', [
            $dateStr,
            $timeStr,
            $code,
            $desc,
            number_format($qty, 2, ',', ''),
            number_format($unitPriceExcl, 2, ',', ''),
            number_format($amountExcl, 2, ',', ''),
            number_format($discountExcl, 2, ',', ''),
            number_format($vatPct, 2, ',', ''),
            $parent,
        ]);
    }

    return implode("\n", $lines) . "\n";
}

function sftpUpload(string $localPath, string $filename, string $segment, string $baseDir): array
{
    $prefix = $segment === SEGMENT_NO_INVOICE ? 'SFTP_NO_INVOICE_' : 'SFTP_';
    $host = trim($_ENV[$prefix . 'HOST'] ?? '');
    $portRaw = $_ENV[$prefix . 'PORT'] ?? '22';
    $port = $portRaw !== '' ? (int) $portRaw : 22;
    if ($port < 1 || $port > 65535) {
        $port = 22;
    }
    $user = trim($_ENV[$prefix . 'USER'] ?? '');
    $pass = $_ENV[$prefix . 'PASSWORD'] ?? '';
    $remoteDir = trim($_ENV[$prefix . 'REMOTE_DIR'] ?? 'uploads', '/');
    if ($remoteDir === '') {
        return ['exit_code' => 1, 'output' => "Missing {$prefix}REMOTE_DIR"];
    }

    if ($host === '' || $user === '' || $pass === '') {
        return ['exit_code' => 1, 'output' => "SFTP not configured for {$segment}"];
    }

    $autoload = $baseDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    }

    if (class_exists('phpseclib3\\Net\\SFTP')) {
        try {
            $sftp = new \phpseclib3\Net\SFTP($host, $port);
            if (!$sftp->login($user, $pass)) {
                return ['exit_code' => 1, 'output' => 'SFTP login failed'];
            }
            $sftp->chdir($remoteDir);
            if (!$sftp->put($filename, $localPath, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE)) {
                return ['exit_code' => 1, 'output' => 'SFTP put failed'];
            }
            return ['exit_code' => 0, 'output' => "Uploaded {$filename} to /{$remoteDir}"];
        } catch (\Exception $e) {
            return ['exit_code' => 1, 'output' => 'SFTP error: ' . $e->getMessage()];
        }
    }

    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $winscpScript = tempnam(sys_get_temp_dir(), 'winscp') . '.txt';
        $script = "open sftp://{$user}:{$pass}@{$host}:{$port}/ -hostkey=*\n";
        $script .= "cd {$remoteDir}\n";
        $script .= "put \"{$localPath}\"\n";
        $script .= "exit\n";
        file_put_contents($winscpScript, $script);

        $output = [];
        $exitCode = 0;
        exec("winscp.com /script=\"{$winscpScript}\" /log=NUL 2>&1", $output, $exitCode);
        @unlink($winscpScript);
        return ['exit_code' => $exitCode, 'output' => implode("\n", $output)];
    }

    $expectScript = <<<EXPECT
set timeout 30
spawn sftp -o StrictHostKeyChecking=no -P {$port} {$user}@{$host}
expect "password:"
send "{$pass}\r"
expect "sftp>"
send "cd {$remoteDir}\r"
expect "sftp>"
send "put {$localPath}\r"
expect "sftp>"
send "bye\r"
expect eof
EXPECT;
    $output = [];
    $exitCode = 0;
    exec("expect <<'EOF'\n{$expectScript}\nEOF\n 2>&1", $output, $exitCode);
    return ['exit_code' => $exitCode, 'output' => implode("\n", $output)];
}

// Check enabled flag
$enabledFile = __DIR__ . DIRECTORY_SEPARATOR . '.enabled';
if (!file_exists($enabledFile)) {
    logLine('SKIP', 'Scheduler is paused (no .enabled flag)');
    exit(0);
}

loadEnv($baseDir . DIRECTORY_SEPARATOR . '.env');

// DB connection
$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$port = $_ENV['DB_PORT'] ?? '3306';
$dbname = $_ENV['DB_DATABASE'] ?? 'db_parklife';
$user = $_ENV['DB_USERNAME'] ?? 'root';
$pass = $_ENV['DB_PASSWORD'] ?? '';

try {
    $db = new PDO(
        "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    logLine('ERROR', 'DB connection failed: ' . $e->getMessage());
    exit(1);
}

$date = $argv[1] ?? date('Y-m-d');
$from = $date . ' 00:00:00';
$to = $date . ' 23:59:59';

logLine('START', "Processing sales for {$date}");

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
$segments = [SEGMENT_INVOICED, SEGMENT_NO_INVOICE];
$anyData = false;
$hadError = false;

foreach ($segments as $segment) {
    $rows = fetchRows($db, $from, $to, $segment);
    if (empty($rows)) {
        logLine('SKIP', "{$segment}: no data");
        continue;
    }
    $anyData = true;

    $csv = buildCsv($rows, $segment);
    $rand = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 4);
    $filename = $segment === SEGMENT_NO_INVOICE
        ? nosalesCsvFilename($date)
        : "sales-{$date}-{$segment}-{$rand}.csv";
    $localPath = $exportDir . DIRECTORY_SEPARATOR . $filename;
    file_put_contents($localPath, $csv);
    logLine('INFO', "{$segment}: saved {$filename} (" . count($rows) . ' rows)');

    if (!$uploadEnabled) {
        logLine('SKIP', "{$segment}: SFTP upload disabled");
        continue;
    }

    $upload = sftpUpload($localPath, $filename, $segment, $baseDir);
    if (($upload['exit_code'] ?? 1) !== 0) {
        $hadError = true;
        logLine('ERROR', "{$segment}: upload failed");
        logLine('ERROR', $upload['output'] ?? 'Unknown upload error');
        continue;
    }

    logLine('OK', "{$segment}: " . ($upload['output'] ?? "Uploaded {$filename}"));

    if ($segment === SEGMENT_INVOICED) {
        $stmt = $db->prepare('UPDATE tbl_sales SET trobex = 1 WHERE date BETWEEN :from AND :to AND closed = 1 AND voidCheck = 0 AND invoice_id IS NOT NULL');
        $stmt->execute(['from' => $from, 'to' => $to]);
        logLine('OK', "Marked trobex = 1 for invoiced sales on {$date}");
    }
}

if (!$anyData) {
    logLine('SKIP', "No sales data for {$date}");
    exit(0);
}

if ($hadError) {
    logLine('DONE', 'Completed with errors');
    exit(1);
}

logLine('DONE', 'Sales upload complete');
