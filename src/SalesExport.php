<?php

namespace App;

use PDO;

/**
 * Shared export pipeline: query -> CSV -> SFTP -> upload log.
 *
 * Used by both App\Controllers\SalesController and scheduler/upload_sales.php
 * so the two paths cannot drift apart.
 */
final class SalesExport
{
    public const CSV_HEADER = 'date;time;article_code;article_descr;quantity;unit_price_excl_vat;amount_excl_vat;discount_excl_vat;vat_percent;parent_article_code';

    /**
     * Rows for one category on one date.
     *
     * @param array $category one entry from SalesCategories::all()
     */
    public static function fetchRows(PDO $db, string $date, array $category, ?int $limit = null): array
    {
        $sql = self::reportSql($category);
        if ($limit !== null && $limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        $params = [
            'from' => $date . ' 00:00:00',
            'to'   => $date . ' 23:59:59',
        ] + self::departmentParams($category);

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Named params for the department IN (...) list. */
    public static function departmentParams(array $category): array
    {
        $params = [];
        foreach (array_values($category['departments']) as $i => $name) {
            $params['dept' . $i] = $name;
        }
        return $params;
    }

    public static function reportSql(array $category): string
    {
        $placeholders = implode(', ', array_map(
            static fn ($i) => ':dept' . $i,
            array_keys(array_values($category['departments']))
        ));

        if (SalesCategories::isNonSales($category)) {
            // No invoice at all, or an invoice_id pointing at a row that no longer exists.
            $invoiceJoin  = 'LEFT JOIN tbl_invoices inv_row ON s.invoice_id = inv_row.id';
            $invoiceWhere = 'AND (s.invoice_id IS NULL OR inv_row.id IS NULL)';
            $orderBy      = 'ORDER BY sl.id';
        } else {
            $invoiceJoin  = 'JOIN tbl_invoices inv ON s.invoice_id = inv.id';
            $invoiceWhere = 'AND s.invoice_id IS NOT NULL';
            $orderBy      = 'ORDER BY s.invoice_id, sl.id';
        }

        return "
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
            {$invoiceJoin}
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
                {$invoiceWhere}
                AND s.voidCheck = 0
                AND sl.quantity > 0
                AND (sl.unitPrice > 0 OR item.noReport = 0)
                AND dept.name IN ({$placeholders})
                AND ROUND(
                    ((sl.quantity - sl.voidQuantity) * sl.unitPrice)
                    - sl.discountAmount + sl.serviceChargeAmount + sl.tax1Amount,
                    2
                ) <> 0
            {$orderBy}
        ";
    }

    public static function buildCsv(array $rows, array $category): string
    {
        $nonSales = SalesCategories::isNonSales($category);
        $lines = [self::CSV_HEADER];

        foreach ($rows as $row) {
            $qty       = (float) ($row['quantity'] ?? 0);
            $unitPrice = (float) ($row['unitPrice'] ?? 0);
            $discount  = (float) ($row['discountAmount'] ?? 0);

            $unitPriceExcl = $nonSales ? 0.00 : round($unitPrice, 2);
            $amountExcl    = $nonSales ? 0.00 : round($qty * $unitPrice - $discount, 2);

            $lines[] = implode(';', [
                date('d/m/Y', strtotime($row['date'])),
                date('H:i:s', strtotime($row['date'])),
                str_replace(';', '', $row['item_code'] ?? ''),
                str_replace(';', '', $row['description'] ?? ''),
                number_format($qty, 2, ',', ''),
                number_format($unitPriceExcl, 2, ',', ''),
                number_format($amountExcl, 2, ',', ''),
                number_format(round($discount, 2), 2, ',', ''),
                number_format((float) ($row['vat_percent'] ?? 0), 2, ',', ''),
                str_replace(';', '', $row['parent_code'] ?? ''),
            ]);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Non-sales keeps the historical nosalesYYMMDD.csv name in every "compl"
     * folder — the folder is what distinguishes them on the server.
     * $suffix adds the random token used for uploaded (not downloaded) files.
     */
    public static function csvFilename(string $date, array $category, string $suffix = ''): string
    {
        if (SalesCategories::isNonSales($category)) {
            return 'nosales' . date('ymd', strtotime($date . ' 12:00:00')) . '.csv';
        }
        $suffix = $suffix !== '' ? '-' . $suffix : '';
        return "sales-{$date}-{$category['key']}{$suffix}.csv";
    }

    /** Local filename, kept unique per category so exports/ doesn't collide. */
    public static function localFilename(string $date, array $category, string $suffix = ''): string
    {
        if (!SalesCategories::isNonSales($category)) {
            return self::csvFilename($date, $category, $suffix);
        }
        $suffix = $suffix !== '' ? '-' . $suffix : '';
        return "nosales-{$date}-{$category['key']}{$suffix}.csv";
    }

    public static function randomToken(): string
    {
        return substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 4);
    }

    /** Remote directory for a category: SFTP_REMOTE_DIR + the category folder. */
    public static function remoteDir(array $category): string
    {
        $base = trim($_ENV['SFTP_REMOTE_DIR'] ?? 'uploads', '/');
        if ($base === '') {
            $base = 'uploads';
        }
        $folder = trim($category['folder'], '/');
        return $folder === '' ? $base : $base . '/' . $folder;
    }

    /**
     * Upload one CSV into the category's folder.
     * phpseclib first, then WinSCP on Windows, then expect.
     */
    public static function sftpUpload(string $localPath, string $filename, array $category): array
    {
        $host = trim($_ENV['SFTP_HOST'] ?? '');
        $portRaw = $_ENV['SFTP_PORT'] ?? '22';
        $port = $portRaw !== '' ? (int) $portRaw : 22;
        if ($port < 1 || $port > 65535) {
            $port = 22;
        }
        $user = trim($_ENV['SFTP_USER'] ?? '');
        $pass = $_ENV['SFTP_PASSWORD'] ?? '';
        $remoteDir = self::remoteDir($category);

        if ($host === '' || $user === '' || $pass === '') {
            return ['exit_code' => 1, 'output' => 'SFTP not configured (SFTP_HOST/USER/PASSWORD)'];
        }

        if (class_exists('phpseclib3\\Net\\SFTP')) {
            try {
                $sftp = new \phpseclib3\Net\SFTP($host, $port);
                if (!$sftp->login($user, $pass)) {
                    return ['exit_code' => 1, 'output' => 'SFTP login failed'];
                }
                if (!$sftp->chdir($remoteDir)) {
                    // Folder may not exist yet for a newly added category.
                    $sftp->mkdir($remoteDir, -1, true);
                    if (!$sftp->chdir($remoteDir)) {
                        return ['exit_code' => 1, 'output' => "Remote folder not found: /{$remoteDir}"];
                    }
                }
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
            file_put_contents($winscpScript, implode("\n", [
                "open sftp://{$user}:{$pass}@{$host}:{$port}/ -hostkey=*",
                "cd \"{$remoteDir}\"",
                "put \"{$localPath}\"",
                'exit',
                '',
            ]));

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
        send "cd \"{$remoteDir}\"\r"
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

    /** Remember that (date, category) made it to the server. */
    public static function recordUpload(PDO $db, string $date, array $category, string $filename, int $rowCount): void
    {
        $stmt = $db->prepare(
            'INSERT INTO tbl_trobex_uploads (sale_date, category_key, filename, rows_count, uploaded_at)
             VALUES (:date, :category, :filename, :rows, NOW())
             ON DUPLICATE KEY UPDATE
                filename = VALUES(filename),
                rows_count = VALUES(rows_count),
                uploaded_at = VALUES(uploaded_at)'
        );
        $stmt->execute([
            'date'     => $date,
            'category' => $category['key'],
            'filename' => $filename,
            'rows'     => $rowCount,
        ]);
    }

    /**
     * Category keys already uploaded, grouped by date.
     * @return array<string, array<string, bool>>
     */
    public static function uploadedByDate(PDO $db, ?string $date = null): array
    {
        if ($date !== null) {
            $stmt = $db->prepare('SELECT sale_date, category_key FROM tbl_trobex_uploads WHERE sale_date = :date');
            $stmt->execute(['date' => $date]);
        } else {
            $stmt = $db->query('SELECT sale_date, category_key FROM tbl_trobex_uploads');
        }

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[$row['sale_date']][$row['category_key']] = true;
        }
        return $map;
    }

    /**
     * Keeps tbl_sales.trobex meaningful for anything downstream still reading it:
     * set once every category that had data for the date has been uploaded.
     */
    public static function markTrobexIfComplete(PDO $db, string $date): bool
    {
        $uploaded = self::uploadedByDate($db, $date)[$date] ?? [];

        foreach (SalesCategories::all() as $category) {
            if (isset($uploaded[$category['key']])) {
                continue;
            }
            // Not uploaded — only acceptable if the category genuinely had no rows.
            if (!empty(self::fetchRows($db, $date, $category, 1))) {
                return false;
            }
        }

        $stmt = $db->prepare(
            'UPDATE tbl_sales s
             SET s.trobex = 1
             WHERE s.date BETWEEN :from AND :to
               AND s.closed = 1
               AND s.voidCheck = 0'
        );
        $stmt->execute(['from' => $date . ' 00:00:00', 'to' => $date . ' 23:59:59']);
        return true;
    }
}
