<?php

namespace App\Controllers;

use App\DatabaseConnectionFactory;
use App\SalesCategories;
use App\SalesExport;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class SalesController
{
    public function list(Request $request, Response $response): Response
    {
        if (empty($_SESSION['user_name'])) {
            return $this->json($response, ['error' => 'Unauthorized'], 401);
        }

        $db = $this->db($request);
        $stmt = $db->query('
            SELECT DATE(s.date) AS sale_date,
                   COUNT(*) AS total_sales,
                   SUM(s.total) AS total_amount
            FROM tbl_sales s
            GROUP BY DATE(s.date)
            ORDER BY sale_date DESC
        ');

        // Dates where the daily procedure is closed — upload is gated on this.
        $closedDates = [];
        $dpStmt = $db->query('SELECT DATE(date) as dp_date FROM tbl_daily_procedures WHERE closed IS NOT NULL');
        foreach ($dpStmt->fetchAll() as $dp) {
            $closedDates[$dp['dp_date']] = true;
        }

        $uploadedByDate = SalesExport::uploadedByDate($db);
        $categoryKeys = SalesCategories::keys();
        $totalCategories = count($categoryKeys);

        $data = array_map(function ($row) use ($closedDates, $uploadedByDate, $categoryKeys, $totalCategories) {
            $uploaded = $uploadedByDate[$row['sale_date']] ?? [];

            $flags = [];
            foreach ($categoryKeys as $key) {
                $flags[$key] = isset($uploaded[$key]);
            }
            $doneCount = count(array_filter($flags));

            return [
                'date'               => $row['sale_date'],
                'sales'              => (int) $row['total_sales'],
                'total'              => round((float) $row['total_amount'], 2),
                'uploaded_categories' => $flags,
                'uploaded_count'     => $doneCount,
                'category_count'     => $totalCategories,
                'uploaded'           => $doneCount === $totalCategories,
                'daily_closed'       => isset($closedDates[$row['sale_date']]),
            ];
        }, $stmt->fetchAll());

        return $this->json($response, [
            'data'       => $data,
            'categories' => SalesCategories::all(),
            'types'      => SalesCategories::types(),
        ]);
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

        $category = $this->requestedCategory($request);
        if ($category === null) {
            return $this->json($response, ['error' => 'Invalid category'], 400);
        }

        $rows = SalesExport::fetchRows($this->dbFactory($request)->forCategory($category), $date, $category);

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
        $category = $this->requestedCategory($request) ?? SalesCategories::default();

        $view = Twig::fromRequest($request);
        return $view->render($response, 'report.html.twig', [
            'name' => $_SESSION['user_name'],
            'date' => $date,
            'category' => $category,
            'categories' => SalesCategories::all(),
            'types' => SalesCategories::types(),
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

        $category = $this->requestedCategory($request);
        if ($category === null) {
            return $this->json($response, ['error' => 'Invalid category'], 400);
        }

        $rows = SalesExport::fetchRows($this->dbFactory($request)->forCategory($category), $date, $category);
        if (empty($rows)) {
            return $this->json($response, ['error' => 'No data for this category'], 400);
        }

        $response->getBody()->write(SalesExport::buildCsv($rows, $category));
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="' . SalesExport::localFilename($date, $category) . '"'
            );
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

        $mode = strtolower(trim($body['mode'] ?? ($body['category'] ?? 'all')));
        if ($mode === 'all') {
            $categories = SalesCategories::all();
        } else {
            $one = SalesCategories::find($mode);
            if ($one === null) {
                return $this->json($response, ['error' => 'Invalid category'], 400);
            }
            $categories = [$one];
        }

        $db = $this->db($request);
        $limit = ($_ENV['LIMIT_COLLECT'] ?? 'false') === 'true' ? 5 : null;
        $uploadEnabled = ($_ENV['UPLOAD_ENABLED'] ?? 'true') === 'true';

        $exportDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'exports';
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }

        $uploads = [];
        $hasData = false;
        $hasFailure = false;

        foreach ($categories as $category) {
            $rows = SalesExport::fetchRows(
                $this->dbFactory($request)->forCategory($category),
                $date,
                $category,
                $limit
            );
            if (empty($rows)) {
                $uploads[] = [
                    'category' => $category['key'],
                    'label'    => $this->categoryLabel($category),
                    'folder'   => SalesExport::remoteDir($category),
                    'status'   => 'skipped',
                    'message'  => 'No data for this category',
                ];
                continue;
            }

            $hasData = true;
            $token = SalesExport::randomToken();
            $remoteName = SalesExport::csvFilename($date, $category, $token);
            $localName = SalesExport::localFilename($date, $category, $token);
            $localPath = $exportDir . DIRECTORY_SEPARATOR . $localName;
            file_put_contents($localPath, SalesExport::buildCsv($rows, $category));

            $item = [
                'category' => $category['key'],
                'label'    => $this->categoryLabel($category),
                'folder'   => SalesExport::remoteDir($category),
                'file'     => $remoteName,
                'rows'     => count($rows),
            ];

            if (!$uploadEnabled) {
                $item['status'] = 'saved';
                $item['sftp_output'] = 'Upload disabled — CSV saved locally as ' . $localName;
                $uploads[] = $item;
                continue;
            }

            $result = SalesExport::sftpUpload($localPath, $remoteName, $category);
            $item['sftp_exit_code'] = $result['exit_code'];
            $item['sftp_output'] = $result['output'];
            $item['status'] = $result['exit_code'] === 0 ? 'uploaded' : 'failed';

            if ($result['exit_code'] === 0) {
                SalesExport::recordUpload($db, $date, $category, $remoteName, count($rows));
            } else {
                $hasFailure = true;
            }

            $uploads[] = $item;
        }

        if (!$hasData) {
            return $this->json($response, ['error' => 'No data for this date'], 400);
        }

        if ($uploadEnabled && !$hasFailure) {
            $factory = $this->dbFactory($request);
            SalesExport::markTrobexIfComplete(
                $db,
                $date,
                static fn (array $category) => $factory->forCategory($category)
            );
        }

        $result = ['uploads' => $uploads];
        if ($hasFailure) {
            $result['error'] = 'One or more uploads failed';
        }

        return $this->json($response, $result);
    }

    /** Include the configured type so duplicate category labels stay clear. */
    private function categoryLabel(array $category): string
    {
        $type = SalesCategories::typeFor($category);
        return $category['label'] . ' (' . strtolower($type['label']) . ')';
    }

    private function requestedCategory(Request $request): ?array
    {
        $params = $request->getQueryParams();
        $key = $params['category'] ?? '';
        if ($key === '') {
            return SalesCategories::default();
        }
        return SalesCategories::find($key);
    }

    private function db(Request $request): \PDO
    {
        return $request->getAttribute('container')->get('db');
    }

    private function dbFactory(Request $request): DatabaseConnectionFactory
    {
        return $request->getAttribute('container')->get('db_factory');
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
