<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\DatabaseConnectionFactory;
use App\EnvConfig;
use App\SalesCategories;

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true)
        );
    }
}

$tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quinos-config-' . bin2hex(random_bytes(5));
if (!mkdir($tempDir, 0700)) {
    throw new RuntimeException('Unable to create test directory.');
}

try {
    $template = $tempDir . DIRECTORY_SEPARATOR . '.env.example';
    $env = $tempDir . DIRECTORY_SEPARATOR . '.env';
    file_put_contents($template, "# Existing comment\nLIMIT_COLLECT=false\nDB_DATABASE=old_db\nCATEGORY_DB_BEV=old_override\n");

    EnvConfig::save(
        $env,
        [
            'DB_DATABASE' => 'main_db',
            'DB_PASSWORD' => "secret\nignored",
            'CATEGORY_DB_FOOD' => 'food-db',
        ],
        ['CATEGORY_DB_BEV'],
        $template
    );

    $contents = file_get_contents($env);
    assertSameValue(true, str_contains($contents, '# Existing comment'), 'Comments should be preserved.');
    assertSameValue(true, str_contains($contents, 'LIMIT_COLLECT=false'), 'Unmanaged keys should be preserved.');
    assertSameValue(false, str_contains($contents, 'CATEGORY_DB_BEV='), 'Removed overrides should be deleted.');
    assertSameValue('secretignored', EnvConfig::read($env)['DB_PASSWORD'], 'Values must be kept to one line.');
    assertSameValue('CATEGORY_DB_BEV_ATTIKA', EnvConfig::categoryDatabaseKey('bev-attika'), 'Category env key mismatch.');

    $bev = SalesCategories::find('bev');
    $food = SalesCategories::find('food');
    $config = EnvConfig::read($env);
    $factory = new DatabaseConnectionFactory($config);
    assertSameValue('main_db', $factory->databaseForCategory($bev), 'Category should inherit the default database.');
    assertSameValue('food-db', $factory->databaseForCategory($food), 'Category override should be selected.');

    echo "Configuration tests passed.\n";
} finally {
    @unlink($tempDir . DIRECTORY_SEPARATOR . '.env');
    @unlink($tempDir . DIRECTORY_SEPARATOR . '.env.example');
    @rmdir($tempDir);
}
