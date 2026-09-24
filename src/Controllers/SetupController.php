<?php

namespace App\Controllers;

use App\DatabaseConnectionFactory;
use App\EnvConfig;
use App\SalesCategories;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class SetupController
{
    private const SOURCE_TABLES = [
        'tbl_sales',
        'tbl_invoices',
        'tbl_sales_lines',
        'tbl_items',
        'tbl_categories',
        'tbl_departments',
        'tbl_employees',
        'tbl_customers',
    ];

    public function page(Request $request, Response $response): Response
    {
        $envPath = $this->envPath($request);
        $firstRun = !is_file($envPath);

        if (!$firstRun && empty($_SESSION['user_name'])) {
            return $this->redirect($request, $response, '/');
        }

        $config = EnvConfig::read($envPath);
        $form = $_SESSION['setup_form'] ?? $this->formFromConfig($config);
        $error = $_SESSION['setup_error'] ?? null;
        $success = $_SESSION['setup_success'] ?? null;

        unset(
            $_SESSION['setup_form'],
            $_SESSION['setup_error'],
            $_SESSION['setup_success']
        );

        return Twig::fromRequest($request)->render(
            $response,
            'setup.html.twig',
            [
                'first_run' => $firstRun,
                'form' => $form,
                'error' => $error,
                'success' => $success,
            ]
        );
    }

    public function save(Request $request, Response $response): Response
    {
        $envPath = $this->envPath($request);
        $firstRun = !is_file($envPath);

        if (!$firstRun && empty($_SESSION['user_name'])) {
            return $this->redirect($request, $response, '/');
        }

        $current = EnvConfig::read($envPath);
        $body = (array) $request->getParsedBody();
        $form = $this->normalizeForm($body, $current, $firstRun);

        try {
            $validated = $this->validateAndBuildConfig($form);
            $values = $validated['env'];
            $definitions = $validated['definitions'];

            $removeKeys = [];

            $knownCategories = array_merge(SalesCategories::all(), $definitions['categories']);
            foreach ($knownCategories as $category) {
                $key = EnvConfig::categoryDatabaseKey($category['key']);

                if (!array_key_exists($key, $values)) {
                    $removeKeys[] = $key;
                }
            }

            // Write the non-secret export rules first. If their directory is
            // not writable, a first-run .env is not created prematurely.
            SalesCategories::save($definitions['types'], $definitions['categories']);
            EnvConfig::save(
                $envPath,
                $values,
                $removeKeys,
                dirname(__DIR__, 2)
                    . DIRECTORY_SEPARATOR
                    . '.env.example'
            );
        } catch (\Throwable $e) {
            $_SESSION['setup_error'] = $e->getMessage();
            $_SESSION['setup_form'] = $this->withoutPasswords($form);

            return $this->redirect(
                $request,
                $response,
                '/setup'
            );
        }

        if ($firstRun) {
            $_SESSION['login_error'] =
                'Setup complete. Sign in to continue.';

            return $this->redirect(
                $request,
                $response,
                '/'
            );
        }

        $_SESSION['setup_success'] =
            'Configuration saved successfully.';

        return $this->redirect(
            $request,
            $response,
            '/setup'
        );
    }

    private function validateAndBuildConfig(array $form): array
    {
        $definitions = SalesCategories::normalize($form['types'], $form['categories']);
        foreach (
            [
                'db_host',
                'db_port',
                'db_username',
                'db_database'
            ] as $field
        ) {
            if ($form[$field] === '') {
                throw new \RuntimeException(
                    'Complete all required MySQL fields.'
                );
            }
        }

        $this->validatePort(
            $form['db_port'],
            'MySQL'
        );

        $this->validateDatabaseName(
            $form['db_database']
        );

        if ($form['upload_enabled']) {
            foreach (
                [
                    'sftp_host',
                    'sftp_port',
                    'sftp_user',
                    'sftp_password',
                    'sftp_remote_dir'
                ] as $field
            ) {
                if ($form[$field] === '') {
                    throw new \RuntimeException(
                        'Complete all SFTP fields when uploads are enabled.'
                    );
                }
            }

            $this->validatePort(
                $form['sftp_port'],
                'SFTP'
            );
        }

        $values = [
            'DB_HOST' => $form['db_host'],
            'DB_PORT' => $form['db_port'],
            'DB_DATABASE' => $form['db_database'],
            'DB_USERNAME' => $form['db_username'],
            'DB_PASSWORD' => $form['db_password'],

            'CSV_DATE_FORMAT' => $form['csv_date_format'],

            'UPLOAD_ENABLED' =>
                $form['upload_enabled']
                    ? 'true'
                    : 'false',

            'SFTP_HOST' => $form['sftp_host'],
            'SFTP_PORT' => $form['sftp_port'],
            'SFTP_USER' => $form['sftp_user'],
            'SFTP_PASSWORD' => $form['sftp_password'],

            'SFTP_REMOTE_DIR' =>
                trim(
                    $form['sftp_remote_dir'],
                    '/'
                ),
        ];

        /*
         * ==========================================================
         * DATABASE VALIDATION
         * ==========================================================
         */

        $databases = [
            $form['db_database'] => self::SOURCE_TABLES
        ];

        $databases[$form['db_database']][] =
            'tbl_daily_procedures';

        foreach ($definitions['categories'] as $index => $category) {
            $database = trim(
                (string) ($form['categories'][$index]['database'] ?? '')
            );

            if ($database === '') {
                $database = $form['db_database'];
            }

            $this->validateDatabaseName($database);

            $databases[$database] =
                array_values(
                    array_unique(
                        array_merge(
                            $databases[$database] ?? [],
                            self::SOURCE_TABLES
                        )
                    )
                );

            if ($database !== $form['db_database']) {
                $values[
                    EnvConfig::categoryDatabaseKey(
                        $category['key']
                    )
                ] = $database;
            }
        }

        /*
         * ==========================================================
         * TEST DATABASE CONNECTIONS
         * ==========================================================
         */

        $factory = new DatabaseConnectionFactory($values);

        foreach (
            $databases as $database => $requiredTables
        ) {
            try {
                $connection =
                    $factory->forDatabase($database);

                $this->assertTablesExist(
                    $connection,
                    $database,
                    $requiredTables
                );
            } catch (\PDOException $e) {
                throw new \RuntimeException(
                    "Unable to connect to database '{$database}'. "
                    . "Check the MySQL settings."
                );
            }
        }

        /*
         * ==========================================================
         * BYPASS CREATE UPLOAD TRACKING TABLE
         * ==========================================================
         *
         * tbl_trobex_uploads sudah dibuat secara manual.
         *
         * Versi asli menjalankan:
         *
         *     $this->createUploadTable(
         *         $factory->default()
         *     );
         *
         * Pemanggilan tersebut sengaja dinonaktifkan agar Setup
         * tidak membutuhkan permission CREATE TABLE.
         *
         * Koneksi database dan tabel sumber di atas tetap diperiksa.
         */

        // try {
        //     $this->createUploadTable(
        //         $factory->default()
        //     );
        // } catch (\PDOException $e) {
        //     throw new \RuntimeException(
        //         'Unable to initialize the upload tracking table '
        //         . 'in the default database.'
        //     );
        // }

        return ['env' => $values, 'definitions' => $definitions];
    }

    private function assertTablesExist(
        PDO $db,
        string $database,
        array $required
    ): void {
        $stmt = $db->prepare(
            'SELECT table_name
             FROM information_schema.tables
             WHERE table_schema = :database'
        );

        $stmt->execute([
            'database' => $database
        ]);

        $existing = array_flip(
            array_column(
                $stmt->fetchAll(PDO::FETCH_ASSOC),
                'table_name'
            )
        );

        $missing = array_values(
            array_filter(
                $required,
                static fn ($table) =>
                    !isset($existing[$table])
            )
        );

        if ($missing) {
            throw new \RuntimeException(
                "Database '{$database}' "
                . "is missing required tables: "
                . implode(', ', $missing)
            );
        }
    }

    /*
     * ==============================================================
     * ORIGINAL CREATE TABLE METHOD
     * ==============================================================
     *
     * Method tetap disimpan.
     *
     * Method ini TIDAK akan dijalankan selama tidak dipanggil dari
     * validateAndBuildConfig().
     *
     * Jadi jika suatu saat ingin mengaktifkan automatic CREATE TABLE
     * kembali, cukup aktifkan pemanggilan method di atas.
     */

    private function createUploadTable(PDO $db): void
    {
        $db->exec(
            'CREATE TABLE IF NOT EXISTS tbl_trobex_uploads (
                id BIGINT NOT NULL AUTO_INCREMENT,
                sale_date DATE NOT NULL,
                category_key VARCHAR(40) NOT NULL,
                filename VARCHAR(190) NOT NULL,
                rows_count INT NOT NULL DEFAULT 0,
                uploaded_at DATETIME NOT NULL,

                PRIMARY KEY (id),

                UNIQUE KEY uq_date_category (
                    sale_date,
                    category_key
                ),

                KEY idx_sale_date (
                    sale_date
                )

            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4'
        );
    }

    private function normalizeForm(
        array $body,
        array $current,
        bool $firstRun
    ): array {
        $dbPassword =
            (string) ($body['db_password'] ?? '');

        $sftpPassword =
            (string) ($body['sftp_password'] ?? '');

        if (!$firstRun && $dbPassword === '') {
            $dbPassword =
                $current['DB_PASSWORD'] ?? '';
        }

        if (!$firstRun && $sftpPassword === '') {
            $sftpPassword =
                $current['SFTP_PASSWORD'] ?? '';
        }

        return [
            'db_host' =>
                trim(
                    (string) ($body['db_host'] ?? '')
                ),

            'db_port' =>
                trim(
                    (string) ($body['db_port'] ?? '3306')
                ),

            'db_username' =>
                trim(
                    (string) ($body['db_username'] ?? '')
                ),

            'db_password' =>
                $dbPassword,

            'db_database' =>
                trim(
                    (string) ($body['db_database'] ?? '')
                ),

            'csv_date_format' =>
                in_array(($body['csv_date_format'] ?? ''), ['d/m/Y', 'm/d/Y', 'Y-m-d'], true)
                    ? (string) $body['csv_date_format']
                    : 'd/m/Y',

            'upload_enabled' =>
                isset($body['upload_enabled']),

            'sftp_host' =>
                trim(
                    (string) ($body['sftp_host'] ?? '')
                ),

            'sftp_port' =>
                trim(
                    (string) ($body['sftp_port'] ?? '22')
                ),

            'sftp_user' =>
                trim(
                    (string) ($body['sftp_user'] ?? '')
                ),

            'sftp_password' =>
                $sftpPassword,

            'sftp_remote_dir' =>
                trim(
                    (string) (
                        $body['sftp_remote_dir']
                        ?? 'uploads'
                    )
                ),

            'types' => is_array($body['types'] ?? null)
                ? array_values($body['types'])
                : [],

            'categories' => is_array($body['categories'] ?? null)
                ? array_values($body['categories'])
                : [],
        ];
    }

    private function formFromConfig(
        array $config
    ): array {
        $categories = array_map(function (array $category) use ($config): array {
            $category['departments_text'] = implode(', ', $category['departments']);
            $category['database'] = $config[EnvConfig::categoryDatabaseKey($category['key'])] ?? '';
            return $category;
        }, SalesCategories::all());

        return [
            'db_host' =>
                $config['DB_HOST'] ?? '127.0.0.1',

            'db_port' =>
                $config['DB_PORT'] ?? '3306',

            'db_username' =>
                $config['DB_USERNAME'] ?? 'root',

            'db_password' => '',

            'db_database' =>
                $config['DB_DATABASE']
                ?? 'db_parklife',

            'csv_date_format' =>
                in_array(($config['CSV_DATE_FORMAT'] ?? ''), ['d/m/Y', 'm/d/Y', 'Y-m-d'], true)
                    ? $config['CSV_DATE_FORMAT']
                    : 'd/m/Y',

            'upload_enabled' =>
                ($config['UPLOAD_ENABLED'] ?? 'true')
                === 'true',

            'sftp_host' =>
                $config['SFTP_HOST'] ?? '',

            'sftp_port' =>
                $config['SFTP_PORT'] ?? '22',

            'sftp_user' =>
                $config['SFTP_USER'] ?? '',

            'sftp_password' => '',

            'sftp_remote_dir' =>
                $config['SFTP_REMOTE_DIR']
                ?? 'uploads',

            'types' => SalesCategories::types(),

            'categories' => $categories,
        ];
    }

    private function withoutPasswords(
        array $form
    ): array {
        $form['db_password'] = '';
        $form['sftp_password'] = '';

        return $form;
    }

    private function validatePort(
        string $port,
        string $label
    ): void {
        if (
            !ctype_digit($port)
            || (int) $port < 1
            || (int) $port > 65535
        ) {
            throw new \RuntimeException(
                "{$label} port must be between 1 and 65535."
            );
        }
    }

    private function validateDatabaseName(
        string $database
    ): void {
        if (
            !preg_match(
                '/^[A-Za-z0-9_$-]+$/',
                $database
            )
        ) {
            throw new \RuntimeException(
                'Database names may contain only '
                . 'letters, numbers, underscores, '
                . 'hyphens, and dollar signs.'
            );
        }
    }

    private function envPath(
        Request $request
    ): string {
        return $request
            ->getAttribute('container')
            ->get('env_file');
    }

    private function redirect(
        Request $request,
        Response $response,
        string $path
    ): Response {
        return $response
            ->withHeader(
                'Location',
                $request->getAttribute('base_path')
                . $path
            )
            ->withStatus(302);
    }
}

