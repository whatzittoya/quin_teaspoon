<?php

namespace App;

final class EnvConfig
{
    public static function load(string $path): array
    {
        $values = self::read($path);
        foreach ($values as $key => $value) {
            $_ENV[$key] = $value;
        }
        return $values;
    }

    public static function read(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $values[trim($key)] = trim($value);
        }
        return $values;
    }

    public static function categoryDatabaseKey(string $categoryKey): string
    {
        return 'CATEGORY_DB_' . strtoupper(str_replace('-', '_', $categoryKey));
    }

    public static function categoryDatabase(array $config, array $category): string
    {
        $key = self::categoryDatabaseKey($category['key']);
        $override = trim($config[$key] ?? '');
        return $override !== '' ? $override : trim($config['DB_DATABASE'] ?? 'db_parklife');
    }

    public static function save(
        string $path,
        array $values,
        array $removeKeys = [],
        ?string $templatePath = null
    ): void {
        $sourcePath = is_file($path) ? $path : $templatePath;
        $lines = ($sourcePath && is_file($sourcePath))
            ? (file($sourcePath, FILE_IGNORE_NEW_LINES) ?: [])
            : [];

        $remove = array_fill_keys($removeKeys, true);
        $written = [];
        $output = [];

        foreach ($lines as $line) {
            if (!preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=/', $line, $match)) {
                $output[] = $line;
                continue;
            }

            $key = $match[1];
            if (isset($remove[$key]) || isset($written[$key])) {
                continue;
            }
            if (array_key_exists($key, $values)) {
                $output[] = $key . '=' . self::singleLine((string) $values[$key]);
                $written[$key] = true;
                continue;
            }
            $output[] = $line;
            $written[$key] = true;
        }

        foreach ($values as $key => $value) {
            if (!isset($written[$key]) && !isset($remove[$key])) {
                $output[] = $key . '=' . self::singleLine((string) $value);
            }
        }

        $contents = rtrim(implode(PHP_EOL, $output)) . PHP_EOL;
        $directory = dirname($path);
        $temporary = tempnam($directory, '.env-');
        if ($temporary === false || file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write the configuration file.');
        }
        @chmod($temporary, 0600);

        if (!@rename($temporary, $path)) {
            // Windows cannot rename over an existing file. Keep a rollback copy
            // while replacing it so a failed second rename does not lose config.
            $backup = $path . '.backup-' . bin2hex(random_bytes(4));
            if (!is_file($path) || !@rename($path, $backup) || !@rename($temporary, $path)) {
                if (is_file($backup) && !is_file($path)) {
                    @rename($backup, $path);
                }
                @unlink($temporary);
                throw new \RuntimeException('Unable to replace the configuration file.');
            }
            @unlink($backup);
        }
    }

    private static function singleLine(string $value): string
    {
        return str_replace(["\r", "\n"], '', $value);
    }
}
