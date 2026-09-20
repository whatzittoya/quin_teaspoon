<?php

use DI\ContainerBuilder;
use App\DatabaseConnectionFactory;
use App\EnvConfig;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

require __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
$config = EnvConfig::load($envFile);

session_start();

$containerBuilder = new ContainerBuilder();

$containerBuilder->addDefinitions([
    'config' => $config,
    'env_file' => $envFile,
    'db_factory' => fn () => new DatabaseConnectionFactory($_ENV),
    'db' => fn (\Psr\Container\ContainerInterface $container) => $container->get('db_factory')->default(),
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

// First-run setup is the only reachable page until .env has been created.
$app->add(function ($request, $handler) use ($envFile, $basePath) {
    $setupPath = ($basePath ?: '') . '/setup';
    if (!is_file($envFile) && rtrim($request->getUri()->getPath(), '/') !== rtrim($setupPath, '/')) {
        $response = new \Slim\Psr7\Response();
        return $response->withHeader('Location', $setupPath)->withStatus(302);
    }
    return $handler->handle($request);
});

$twig = $container->get('view');
$twig->getEnvironment()->addGlobal('base_path', $basePath);
$app->add(TwigMiddleware::create($app, $twig));
$app->addRoutingMiddleware();
$app->addErrorMiddleware(true, true, true);

$app->run();
