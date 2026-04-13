<?php
/**
 * QUINOS — Automated Sales Upload (CLI)
 *
 * Fetches yesterday's sales data, builds a CSV, and uploads via SFTP.
 * Designed to run from Windows Task Scheduler via run.bat.
 *
 * Usage: php upload_sales.php [YYYY-MM-DD]
 *   If no date is given, defaults to yesterday.
 */

$baseDir = dirname(__DIR__);

// Check enabled flag
$enabledFile = __DIR__ . DIRECTORY_SEPARATOR . '.enabled';
if (!file_exists($enabledFile)) {
    echo date('Y-m-d H:i:s') . " [SKIP] Scheduler is paused (no .enabled flag)\n";
    exit(0);
}

// Load .env
$envFile = $baseDir . DIRECTORY_SEPARATOR . '.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

// DB connection
$host   = $_ENV['DB_HOST'] ?? '127.0.0.1';
$port   = $_ENV['DB_PORT'] ?? '3306';
$dbname = $_ENV['DB_DATABASE'] ?? 'db_parklife';
$user   = $_ENV['DB_USERNAME'] ?? 'root';
$pass   = $_ENV['DB_PASSWORD'] ?? '';

try {
    $db = new PDO(
        "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    echo date('Y-m-d H:i:s') . " [ERROR] DB connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Determine date
$date = $argv[1] ?? date('Y-m-d', strtotime('-1 day'));
$from = $date . ' 00:00:00';
$to   = $date . ' 23:59:59';

echo date('Y-m-d H:i:s') . " [START] Processing sales for {$date}\n";

// Fetch sales data (same query as SalesController)
$sql = "
    SELECT STRAIGHT_JOIN
        s.invoice_id AS invoice_id,
        s.date AS date,
        sl.id AS id,
        s.type AS salesType,
        sl.description AS description,
        (sl.quantity - sl.voidQuantity) AS quantity,
        sl.unitPrice AS unitPrice,
        ((sl.quantity - sl.voidQuantity) * sl.unitPrice) - sl.discountAmount + sl.serviceChargeAmount + sl.tax1Amount AS revenue,
        sl.discountAmount AS discountAmount,
        sl.tax1Amount AS tax1Amount,
        item.code AS item_code,
        parentcode.code AS parent_code,
        dept.name AS department_name,
        s.tax1 AS vat_percent
    FROM tbl_sales s
    JOIN tbl_invoices inv ON s.invoice_id = inv.id
    JOIN tbl_sales_lines sl ON sl.sales_id = s.id
    JOIN tbl_items item ON sl.item_id = item.id
    JOIN tbl_categories cat ON item.category_id = cat.id
    JOIN tbl_departments dept ON cat.department_id = dept.id
    JOIN tbl_employees emp ON sl.employee_id = emp.id
    LEFT JOIN tbl_sales_lines discountline ON sl.discountLine_id = discountline.id
    LEFT JOIN tbl_customers cust ON s.customer_id = cust.id
    LEFT JOIN tbl_sales_lines parent ON sl.parent_id = parent.id
    LEFT JOIN tbl_items parentcode ON parent.item_id = parentcode.id
    WHERE s.closed = 1
        AND s.date BETWEEN :from AND :to
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

$stmt = $db->prepare($sql);
$stmt->execute(['from' => $from, 'to' => $to]);
$rows = $stmt->fetchAll();

if (empty($rows)) {
    echo date('Y-m-d H:i:s') . " [SKIP] No sales data for {$date}\n";
    exit(0);
}

echo date('Y-m-d H:i:s') . " [INFO] Found " . count($rows) . " rows\n";

// Build CSV (same format as SalesController::buildCsv)
$header = 'date;time;article_code;article_descr;quantity;unit_price_excl_vat;amount_excl_vat;discount_excl_vat;vat_percent;parent_article_code';
$lines = [$header];

foreach ($rows as $row) {
    $dateStr  = date('d/m/Y', strtotime($row['date']));
    $timeStr  = date('H:i:s', strtotime($row['date']));
    $code     = str_replace(';', '', $row['item_code'] ?? '');
    $desc     = str_replace(';', '', $row['description'] ?? '');
    $qty      = (float) $row['quantity'];
    $vatPct   = (float) $row['vat_percent'];
    $unitPrice = (float) $row['unitPrice'];
    $discount  = (float) $row['discountAmount'];

    $vatDivisor     = 1 + ($vatPct / 100);
    $unitPriceExcl  = round($unitPrice / $vatDivisor, 2);
    $amountExcl     = round(($qty * $unitPrice - $discount) / $vatDivisor, 2);
    $discountExcl   = round($discount / $vatDivisor, 2);

    $parent = str_replace(';', '', $row['parent_code'] ?? '');

    $fmtQty          = number_format($qty, 2, ',', '');
    $fmtUnitPrice    = number_format($unitPriceExcl, 2, ',', '');
    $fmtAmount       = number_format($amountExcl, 2, ',', '');
    $fmtDiscount     = number_format($discountExcl, 2, ',', '');
    $fmtVat          = number_format($vatPct, 2, ',', '');

    $lines[] = implode(';', [
        $dateStr, $timeStr, $code, $desc, $fmtQty,
        $fmtUnitPrice, $fmtAmount, $fmtDiscount, $fmtVat, $parent,
    ]);
}

$csvContent = implode("\n", $lines) . "\n";

// Save CSV locally
$exportDir = $baseDir . DIRECTORY_SEPARATOR . 'exports';
if (!is_dir($exportDir)) {
    mkdir($exportDir, 0755, true);
}
$rand     = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 4);
$filename = "sales-{$date}-{$rand}.csv";
$localPath = $exportDir . DIRECTORY_SEPARATOR . $filename;
file_put_contents($localPath, $csvContent);

echo date('Y-m-d H:i:s') . " [INFO] Saved {$filename} (" . count($rows) . " rows)\n";

// SFTP upload
$uploadEnabled = ($_ENV['UPLOAD_ENABLED'] ?? 'true') === 'true';
if (!$uploadEnabled) {
    echo date('Y-m-d H:i:s') . " [SKIP] SFTP upload disabled in .env\n";
    exit(0);
}

$sftpHost = trim($_ENV['SFTP_HOST'] ?? '');
$sftpPortRaw = $_ENV['SFTP_PORT'] ?? '22';
$sftpPort = $sftpPortRaw !== '' ? (int) $sftpPortRaw : 22;
if ($sftpPort < 1 || $sftpPort > 65535) {
    $sftpPort = 22;
}
$sftpUser = trim($_ENV['SFTP_USER'] ?? '');
$sftpPass = $_ENV['SFTP_PASSWORD'] ?? '';
$sftpRemoteDir = trim($_ENV['SFTP_REMOTE_DIR'] ?? 'uploads', '/');
if ($sftpRemoteDir === '') {
    $sftpRemoteDir = 'uploads';
}

if ($sftpHost === '' || $sftpUser === '' || $sftpPass === '') {
    echo date('Y-m-d H:i:s') . " [ERROR] SFTP not configured: set SFTP_HOST, SFTP_USER, SFTP_PASSWORD in .env\n";
    exit(1);
}

// Try phpseclib first (works on Windows + Linux)
$autoload = $baseDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

if (class_exists('phpseclib3\\Net\\SFTP')) {
    echo date('Y-m-d H:i:s') . " [INFO] Using phpseclib for SFTP\n";
    $sftp = new \phpseclib3\Net\SFTP($sftpHost, $sftpPort);
    if (!$sftp->login($sftpUser, $sftpPass)) {
        echo date('Y-m-d H:i:s') . " [ERROR] SFTP login failed\n";
        exit(1);
    }
    $sftp->chdir($sftpRemoteDir);
    if (!$sftp->put($filename, $localPath, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE)) {
        echo date('Y-m-d H:i:s') . " [ERROR] SFTP upload failed\n";
        exit(1);
    }
    echo date('Y-m-d H:i:s') . " [OK] Uploaded {$filename} via phpseclib\n";
} elseif (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    // Fallback: WinSCP CLI on Windows
    echo date('Y-m-d H:i:s') . " [INFO] phpseclib not found, trying WinSCP CLI\n";
    $winscpScript = tempnam(sys_get_temp_dir(), 'winscp') . '.txt';
    $scriptContent = "open sftp://{$sftpUser}:{$sftpPass}@{$sftpHost}:{$sftpPort}/ -hostkey=*\n";
    $scriptContent .= "cd {$sftpRemoteDir}\n";
    $scriptContent .= "put \"{$localPath}\"\n";
    $scriptContent .= "exit\n";
    file_put_contents($winscpScript, $scriptContent);

    $output = [];
    $exitCode = 0;
    exec("winscp.com /script=\"{$winscpScript}\" /log=NUL 2>&1", $output, $exitCode);
    unlink($winscpScript);

    if ($exitCode !== 0) {
        echo date('Y-m-d H:i:s') . " [ERROR] WinSCP upload failed (exit {$exitCode})\n";
        echo implode("\n", $output) . "\n";
        exit(1);
    }
    echo date('Y-m-d H:i:s') . " [OK] Uploaded {$filename} via WinSCP\n";
} else {
    // Fallback: expect on Linux/Mac
    echo date('Y-m-d H:i:s') . " [INFO] phpseclib not found, trying expect\n";
    $expectScript = <<<EXPECT
set timeout 30
spawn sftp -o StrictHostKeyChecking=no -P {$sftpPort} {$sftpUser}@{$sftpHost}
expect "password:"
send "{$sftpPass}\r"
expect "sftp>"
send "cd {$sftpRemoteDir}\r"
expect "sftp>"
send "put {$localPath}\r"
expect "sftp>"
send "bye\r"
expect eof
EXPECT;

    $output = [];
    $exitCode = 0;
    exec("expect <<'EOF'\n{$expectScript}\nEOF\n 2>&1", $output, $exitCode);

    if ($exitCode !== 0) {
        echo date('Y-m-d H:i:s') . " [ERROR] SFTP upload failed (exit {$exitCode})\n";
        echo implode("\n", $output) . "\n";
        exit(1);
    }
    echo date('Y-m-d H:i:s') . " [OK] Uploaded {$filename} via expect\n";
}

// Mark sales as uploaded (trobex = 1)
$stmt = $db->prepare('UPDATE tbl_sales SET trobex = 1 WHERE date BETWEEN :from AND :to AND closed = 1 AND voidCheck = 0');
$stmt->execute(['from' => $from, 'to' => $to]);
echo date('Y-m-d H:i:s') . " [OK] Marked trobex = 1 for {$date}\n";

echo date('Y-m-d H:i:s') . " [DONE] Sales upload complete\n";
