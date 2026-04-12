<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class SalesController
{
    private const REPORT_QUERY = "
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
        ORDER BY s.invoice_id, sl.id
    ";

    public function list(Request $request, Response $response): Response
    {
        if (empty($_SESSION['user_name'])) {
            return $this->json($response, ['error' => 'Unauthorized'], 401);
        }

        $db = $request->getAttribute('container')->get('db');
        $stmt = $db->query('
            SELECT DATE(date) as sale_date,
                   COUNT(*) as total_sales,
                   SUM(total) as total_amount,
                   BIT_OR(trobex) as uploaded
            FROM tbl_sales
            GROUP BY DATE(date)
            ORDER BY sale_date DESC
        ');

        $data = array_map(function ($row) {
            return [
                'date' => $row['sale_date'],
                'sales' => (int) $row['total_sales'],
                'total' => round((float) $row['total_amount'], 2),
                'uploaded' => (bool) $row['uploaded'],
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

        $rows = $this->fetchReport($request, $date);

        $data = array_map(function ($row) {
            return [
                'date' => date('d/m/Y', strtotime($row['date'])),
                'bill_no' => (int) $row['invoice_id'],
                'code' => $row['item_code'],
                'description' => $row['description'],
                'quantity' => (float) $row['quantity'],
                'amount' => round((float) $row['revenue'], 2),
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
        $view = Twig::fromRequest($request);
        return $view->render($response, 'report.html.twig', [
            'name' => $_SESSION['user_name'],
            'date' => $date,
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

        $rows = $this->fetchReport($request, $date);
        if (empty($rows)) {
            return $this->json($response, ['error' => 'No data for this date'], 400);
        }

        $csvContent = $this->buildCsv($rows);
        $filename = "sales-{$date}.csv";

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

        $date = ($request->getParsedBody())['date'] ?? '';
        if (!$date) {
            return $this->json($response, ['error' => 'Date required'], 400);
        }

        $limitCollect = ($_ENV['LIMIT_COLLECT'] ?? 'false') === 'true';
        $limit = $limitCollect ? 5 : null;

        $rows = $this->fetchReport($request, $date, $limit);
        if (empty($rows)) {
            return $this->json($response, ['error' => 'No data for this date'], 400);
        }

        $csvContent = $this->buildCsv($rows);

        $rand = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 4);
        $filename = "sales-{$date}-{$rand}.csv";
        $exportDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'exports';
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }
        $localPath = $exportDir . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($localPath, $csvContent);

        $result = [
            'file' => $filename,
            'rows' => count($rows),
        ];

        $uploadEnabled = ($_ENV['UPLOAD_ENABLED'] ?? 'true') === 'true';
        if ($uploadEnabled) {
            $sftpResult = $this->sftpUpload($localPath, $filename);
            $result['sftp_exit_code'] = $sftpResult['exit_code'];
            $result['sftp_output'] = $sftpResult['output'];
            if ($sftpResult['exit_code'] !== 0) {
                $result['error'] = 'SFTP upload failed';
            } else {
                // Mark sales as uploaded
                $db = $request->getAttribute('container')->get('db');
                $stmt = $db->prepare('UPDATE tbl_sales SET trobex = 1 WHERE date BETWEEN :from AND :to AND closed = 1 AND voidCheck = 0');
                $stmt->execute(['from' => $date . ' 00:00:00', 'to' => $date . ' 23:59:59']);
            }
        } else {
            $result['sftp_output'] = 'Upload disabled — CSV saved locally only';
        }

        return $this->json($response, $result);
    }

    private function fetchReport(Request $request, string $date, ?int $limit = null): array
    {
        $from = $date . ' 00:00:00';
        $to = $date . ' 23:59:59';

        $sql = self::REPORT_QUERY;
        if ($limit) {
            $sql .= " LIMIT {$limit}";
        }

        $db = $request->getAttribute('container')->get('db');
        $stmt = $db->prepare($sql);
        $stmt->execute(['from' => $from, 'to' => $to]);
        return $stmt->fetchAll();
    }

    private function buildCsv(array $rows): string
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

            $vatDivisor = 1 + ($vatPct / 100);
            $unitPriceExcl = round($unitPrice / $vatDivisor, 2);
            $amountExcl = round(($qty * $unitPrice - $discount) / $vatDivisor, 2);
            $discountExcl = round($discount / $vatDivisor, 2);

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

    private function sftpUpload(string $localPath, string $filename): array
    {
        $host = 'proxy.tspoonlab.com';
        $port = 2222;
        $user = 'renew.bali';
        $pass = 'KprKuh1';

        // Use phpseclib (works on both Windows and Linux)
        if (class_exists('phpseclib3\\Net\\SFTP')) {
            try {
                $sftp = new \phpseclib3\Net\SFTP($host, $port);
                if (!$sftp->login($user, $pass)) {
                    return ['exit_code' => 1, 'output' => 'SFTP login failed'];
                }
                $sftp->chdir('uploads');
                if (!$sftp->put($filename, $localPath, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE)) {
                    return ['exit_code' => 1, 'output' => 'SFTP put failed'];
                }
                return ['exit_code' => 0, 'output' => "Uploaded {$filename} to /uploads"];
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
send "cd uploads\r"
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

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
