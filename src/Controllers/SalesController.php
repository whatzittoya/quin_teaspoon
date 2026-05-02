<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class SalesController
{
    private const SEGMENT_INVOICED = 'invoiced';
    private const SEGMENT_NO_INVOICE = 'no_invoice';

    private const REPORT_QUERY_INVOICED = "
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

    private const REPORT_QUERY_NO_INVOICE = "
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
        JOIN tbl_sales_lines sl ON sl.sales_id = s.id
        JOIN tbl_items item ON sl.item_id = item.id
        JOIN tbl_categories cat ON item.category_id = cat.id
        JOIN tbl_departments dept ON cat.department_id = dept.id
        JOIN tbl_employees emp ON sl.employee_id = emp.id
        LEFT JOIN tbl_sales_lines discountline ON sl.discountLine_id = discountline.id
        LEFT JOIN tbl_customers cust ON s.customer_id = cust.id
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

    public function list(Request $request, Response $response): Response
    {
        if (empty($_SESSION['user_name'])) {
            return $this->json($response, ['error' => 'Unauthorized'], 401);
        }

        $db = $request->getAttribute('container')->get('db');
        $stmt = $db->query('
            SELECT DATE(s.date) AS sale_date,
                   COUNT(*) AS total_sales,
                   SUM(s.total) AS total_amount,
                   BIT_OR(CASE WHEN s.invoice_id IS NOT NULL AND inv.id IS NOT NULL THEN s.trobex ELSE 0 END) AS uploaded_invoiced,
                   BIT_OR(CASE WHEN s.invoice_id IS NULL OR inv.id IS NULL THEN s.trobex ELSE 0 END) AS uploaded_no_sales
            FROM tbl_sales s
            LEFT JOIN tbl_invoices inv ON s.invoice_id = inv.id
            GROUP BY DATE(s.date)
            ORDER BY sale_date DESC
        ');

        // Fetch dates where daily procedure is closed
        $closedDates = [];
        $dpStmt = $db->query('SELECT DATE(date) as dp_date FROM tbl_daily_procedures WHERE closed IS NOT NULL');
        foreach ($dpStmt->fetchAll() as $dp) {
            $closedDates[$dp['dp_date']] = true;
        }

        $data = array_map(function ($row) use ($closedDates) {
            $inv = (bool) $row['uploaded_invoiced'];
            $nos = (bool) $row['uploaded_no_sales'];

            return [
                'date'              => $row['sale_date'],
                'sales'             => (int) $row['total_sales'],
                'total'             => round((float) $row['total_amount'], 2),
                'uploaded_invoiced' => $inv,
                'uploaded_no_sales' => $nos,
                'uploaded'          => $inv && $nos,
                'daily_closed'      => isset($closedDates[$row['sale_date']]),
            ];
        }, $stmt->fetchAll());

        return $this->json($response, ['data' => $data]);
    }

    public function report(Request $request, Response $response): Response
    {
        if (empty($_SESSION['user_name'])) {
            return $this->json($response, ['error' => 'Unauthorized'], 401);
        }

        $date = $request->getQueryParams()['date'] ?? '';
        if (!$date) {
            return $this->json($response, ['error' => 'Date required'], 400);
        }

        $segment = $this->normalizeSegment($request->getQueryParams()['segment'] ?? self::SEGMENT_INVOICED);
        if ($segment === null) {
            return $this->json($response, ['error' => 'Invalid segment'], 400);
        }

        $rows = $this->fetchReport($request, $date, $segment);

        $data = array_map(function ($row) {
            return [
                'date' => date('d/m/Y', strtotime($row['date'])),
                'bill_no' => $row['invoice_id'] !== null ? (int) $row['invoice_id'] : null,
                'code' => $row['item_code'],
                'description' => $row['description'],
                'department' => $row['department_name'] ?? '',
                'quantity' => (float) $row['quantity'],
                'unit_price' => round((float) $row['unitPrice'], 2),
                'amount' => round(((float) $row['quantity'] * (float) $row['unitPrice'] - (float) $row['discountAmount']), 2),
                'parent' => $row['parent_code'],
            ];
        }, $rows);

        return $this->json($response, ['data' => $data]);
    }

    public function reportPage(Request $request, Response $response): Response
    {
        if (empty($_SESSION['user_name'])) {
            return $response->withHeader('Location', $request->getAttribute('base_path') . '/')->withStatus(302);
        }
        $date = $request->getQueryParams()['date'] ?? '';
        $segment = $this->normalizeSegment($request->getQueryParams()['segment'] ?? self::SEGMENT_INVOICED) ?? self::SEGMENT_INVOICED;
        $view = Twig::fromRequest($request);
        return $view->render($response, 'report.html.twig', [
            'name' => $_SESSION['user_name'],
            'date' => $date,
            'segment' => $segment,
        ]);
    }

    public function download(Request $request, Response $response): Response
    {
        if (empty($_SESSION['user_name'])) {
            return $this->json($response, ['error' => 'Unauthorized'], 401);
        }

        $date = $request->getQueryParams()['date'] ?? '';
        if (!$date) {
            return $this->json($response, ['error' => 'Date required'], 400);
        }

        $segment = $this->normalizeSegment($request->getQueryParams()['segment'] ?? self::SEGMENT_INVOICED);
        if ($segment === null) {
            return $this->json($response, ['error' => 'Invalid segment'], 400);
        }

        $rows = $this->fetchReport($request, $date, $segment);
        if (empty($rows)) {
            return $this->json($response, ['error' => 'No data for this date'], 400);
        }

        $csvContent = $this->buildCsv($rows, $segment);
        $filename = $segment === self::SEGMENT_NO_INVOICE
            ? $this->nosalesCsvFilename($date)
            : "sales-{$date}-{$segment}.csv";

        $response->getBody()->write($csvContent);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    public function upload(Request $request, Response $response): Response
    {
        if (empty($_SESSION['user_name'])) {
            return $this->json($response, ['error' => 'Unauthorized'], 401);
        }

        $body = (array) $request->getParsedBody();
        $date = $body['date'] ?? '';
        if (!$date) {
            return $this->json($response, ['error' => 'Date required'], 400);
        }

        $mode = $this->normalizeUploadMode($body['mode'] ?? ($body['segment'] ?? 'both'));
        if ($mode === null) {
            return $this->json($response, ['error' => 'Invalid mode'], 400);
        }

        $limitCollect = ($_ENV['LIMIT_COLLECT'] ?? 'false') === 'true';
        $limit = $limitCollect ? 5 : null;

        $exportDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'exports';
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }

        $uploadEnabled = ($_ENV['UPLOAD_ENABLED'] ?? 'true') === 'true';
        $segments = $mode === 'both' ? [self::SEGMENT_INVOICED, self::SEGMENT_NO_INVOICE] : [$mode];
        $uploads = [];

        foreach ($segments as $segment) {
            $rows = $this->fetchReport($request, $date, $segment, $limit);
            if (empty($rows)) {
                $uploads[] = [
                    'segment' => $segment,
                    'status' => 'skipped',
                    'message' => 'No data for this segment',
                ];
                continue;
            }

            $csvContent = $this->buildCsv($rows, $segment);
            $rand = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 4);
            $filename = $segment === self::SEGMENT_NO_INVOICE
                ? $this->nosalesCsvFilename($date)
                : "sales-{$date}-{$segment}-{$rand}.csv";
            $localPath = $exportDir . DIRECTORY_SEPARATOR . $filename;
            file_put_contents($localPath, $csvContent);

            $item = [
                'segment' => $segment,
                'file' => $filename,
                'rows' => count($rows),
            ];

            if ($uploadEnabled) {
                $sftpResult = $this->sftpUpload($localPath, $filename, $segment);
                $item['sftp_exit_code'] = $sftpResult['exit_code'];
                $item['sftp_output'] = $sftpResult['output'];
                if ($sftpResult['exit_code'] === 0) {
                    $db = $request->getAttribute('container')->get('db');
                    if ($segment === self::SEGMENT_INVOICED) {
                        $stmt = $db->prepare(
                            'UPDATE tbl_sales s
                             INNER JOIN tbl_invoices inv ON s.invoice_id = inv.id
                             SET s.trobex = 1
                             WHERE s.date BETWEEN :from AND :to
                               AND s.closed = 1
                               AND s.voidCheck = 0'
                        );
                        $stmt->execute(['from' => $date . ' 00:00:00', 'to' => $date . ' 23:59:59']);
                    } elseif ($segment === self::SEGMENT_NO_INVOICE) {
                        $stmt = $db->prepare(
                            'UPDATE tbl_sales s
                             LEFT JOIN tbl_invoices inv_row ON s.invoice_id = inv_row.id
                             SET s.trobex = 1
                             WHERE s.date BETWEEN :from AND :to
                               AND s.closed = 1
                               AND s.voidCheck = 0
                               AND (s.invoice_id IS NULL OR inv_row.id IS NULL)'
                        );
                        $stmt->execute(['from' => $date . ' 00:00:00', 'to' => $date . ' 23:59:59']);
                    }
                }
                $item['status'] = $sftpResult['exit_code'] === 0 ? 'uploaded' : 'failed';
            } else {
                $item['status'] = 'saved';
                $item['sftp_output'] = 'Upload disabled — CSV saved locally only';
            }

            $uploads[] = $item;
        }

        $hasData = false;
        $hasFailure = false;
        foreach ($uploads as $upload) {
            if (($upload['status'] ?? '') !== 'skipped') {
                $hasData = true;
            }
            if (($upload['status'] ?? '') === 'failed') {
                $hasFailure = true;
            }
        }

        if (!$hasData) {
            return $this->json($response, ['error' => 'No data for this date'], 400);
        }

        $result = ['uploads' => $uploads];

        // Backward-compatible keys for existing UI widgets.
        foreach ($uploads as $upload) {
            if (!isset($upload['file'])) {
                continue;
            }
            $result['file'] = $upload['file'];
            $result['rows'] = $upload['rows'];
            $result['sftp_output'] = $upload['sftp_output'] ?? '';
            break;
        }

        if ($hasFailure) {
            $result['error'] = 'One or more uploads failed';
        }

        return $this->json($response, $result);
    }

    /** No-invoice / orphan-invoice exports: nosales + YYMMDD + .csv */
    private function nosalesCsvFilename(string $dateYmd): string
    {
        return 'nosales' . date('ymd', strtotime($dateYmd . ' 12:00:00')) . '.csv';
    }

    private function fetchReport(Request $request, string $date, string $segment, ?int $limit = null): array
    {
        $from = $date . ' 00:00:00';
        $to = $date . ' 23:59:59';

        $sql = $segment === self::SEGMENT_NO_INVOICE ? self::REPORT_QUERY_NO_INVOICE : self::REPORT_QUERY_INVOICED;
        if ($limit) {
            $sql .= " LIMIT {$limit}";
        }

        $db = $request->getAttribute('container')->get('db');
        $stmt = $db->prepare($sql);
        $stmt->execute(['from' => $from, 'to' => $to]);
        return $stmt->fetchAll();
    }

    private function buildCsv(array $rows, string $segment): string
    {
        $header = 'date;time;article_code;article_descr;quantity;unit_price_excl_vat;amount_excl_vat;discount_excl_vat;vat_percent;parent_article_code';
        $lines = [$header];

        foreach ($rows as $row) {
            $dateStr = date('d/m/Y', strtotime($row['date']));
            $timeStr = date('H:i:s', strtotime($row['date']));
            $code = str_replace(';', '', $row['item_code'] ?? '');
            $desc = str_replace(';', '', $row['description'] ?? '');
            $qty = (float) $row['quantity'];
            $vatPct = (float) $row['vat_percent'];
            $unitPrice = (float) $row['unitPrice'];
            $discount = (float) $row['discountAmount'];

            $unitPriceExcl = round($unitPrice, 2);
            $amountExcl = round(($qty * $unitPrice - $discount), 2);
            $discountExcl = round($discount, 2);

            if ($segment === self::SEGMENT_NO_INVOICE) {
                $unitPriceExcl = 0.00;
                $amountExcl = 0.00;
            }

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

        return implode("\n", $lines) . "\n";
    }

    private function sftpUpload(string $localPath, string $filename, string $segment): array
    {
        $prefix = $segment === self::SEGMENT_NO_INVOICE ? 'SFTP_NO_INVOICE_' : 'SFTP_';
        $host = trim($_ENV[$prefix . 'HOST'] ?? '');
        $portRaw = $_ENV[$prefix . 'PORT'] ?? '22';
        $port = $portRaw !== '' ? (int) $portRaw : 22;
        if ($port < 1 || $port > 65535) {
            $port = 22;
        }
        $user = trim($_ENV[$prefix . 'USER'] ?? '');
        $pass = $_ENV[$prefix . 'PASSWORD'] ?? '';
        $remoteDir = trim($_ENV[$prefix . 'REMOTE_DIR'] ?? 'uploads', '/');
        if ($segment === self::SEGMENT_NO_INVOICE && $remoteDir === '') {
            return [
                'exit_code' => 1,
                'output' => 'Missing SFTP_NO_INVOICE_REMOTE_DIR',
            ];
        }
        if ($remoteDir === '') {
            $remoteDir = 'uploads';
        }

        if ($host === '' || $user === '' || $pass === '') {
            return [
                'exit_code' => 1,
                'output' => 'SFTP not configured for segment "' . $segment . '"',
            ];
        }

        // Use phpseclib (works on both Windows and Linux)
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

        // Fallback: expect (Linux/Mac only)
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

        return [
            'exit_code' => $exitCode,
            'output' => implode("\n", $output),
        ];
    }

    private function normalizeSegment(string $segment): ?string
    {
        $segment = strtolower(trim($segment));
        if ($segment === self::SEGMENT_INVOICED || $segment === self::SEGMENT_NO_INVOICE) {
            return $segment;
        }
        return null;
    }

    private function normalizeUploadMode(string $mode): ?string
    {
        $mode = strtolower(trim($mode));
        if ($mode === 'both') {
            return $mode;
        }
        return $this->normalizeSegment($mode);
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
