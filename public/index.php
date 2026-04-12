<?php

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

require __DIR__ . '/../vendor/autoload.php';

// Load .env
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

session_start();

$containerBuilder = new ContainerBuilder();

$containerBuilder->addDefinitions([
    'db' => function () {
        $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
        $port = $_ENV['DB_PORT'] ?? '3306';
        $dbname = $_ENV['DB_DATABASE'] ?? 'db_parklife';
        $user = $_ENV['DB_USERNAME'] ?? 'root';
        $pass = $_ENV['DB_PASSWORD'] ?? '';

        return new PDO(
            "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    },
    'view' => function () {
        return Twig::create(__DIR__ . '/../templates', ['cache' => false]);
    },
]);

$container = $containerBuilder->build();
AppFactory::setContainer($container);
$app = AppFactory::create();

// Auto-detect base path (e.g. /sftp when running under C:\xampp\htdocs\sftp)
$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$scriptDir  = rtrim(str_replace('/index.php', '', $scriptName), '/');
if ($scriptDir !== '' && str_ends_with($scriptDir, '/public')) {
    $basePath = substr($scriptDir, 0, -7); // strip /public
} else {
    $basePath = $scriptDir;
}
if ($basePath !== '') {
    $app->setBasePath($basePath);
}

// Routes
require __DIR__ . '/../src/routes.php';

// Inject container + base_path into request attributes for controllers
$app->add(function ($request, $handler) use ($container, $basePath) {
    $request = $request->withAttribute('container', $container);
    $request = $request->withAttribute('base_path', $basePath);
    return $handler->handle($request);
});

$twig = $container->get('view');
$twig->getEnvironment()->addGlobal('base_path', $basePath);
$app->add(TwigMiddleware::create($app, $twig));
$app->addRoutingMiddleware();
$app->addErrorMiddleware(true, true, true);

$app->run();
