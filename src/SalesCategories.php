<?php

namespace App;

/** Export type and category configuration shared by the web app and scheduler. */
final class SalesCategories
{
    public const TYPE_SALES = 'sales';
    public const TYPE_NON_SALES = 'non_sales';

    private const DEFAULT_TYPES = [
        ['key' => self::TYPE_SALES, 'label' => 'Sales', 'invoice_mode' => 'required', 'zero_prices' => false, 'filename_mode' => 'unique', 'filename_prefix' => 'sales'],
        ['key' => self::TYPE_NON_SALES, 'label' => 'No sales', 'invoice_mode' => 'missing', 'zero_prices' => true, 'filename_mode' => 'daily', 'filename_prefix' => 'nosales'],
    ];

    private const DEFAULT_CATEGORIES = [
        ['key' => 'bev',              'label' => 'Beverage',        'type' => self::TYPE_SALES,     'departments' => ['BEVERAGE'],                               'folder' => 'bev'],
        ['key' => 'bev-attika',       'label' => 'Beverage Attika', 'type' => self::TYPE_SALES,     'departments' => ['BEVERAGE ATTIKA'],                        'folder' => 'bev attika'],
        ['key' => 'food',             'label' => 'Food',            'type' => self::TYPE_SALES,     'departments' => ['FOOD'],                                   'folder' => 'food'],
        ['key' => 'ticket',           'label' => 'Ticket',          'type' => self::TYPE_SALES,     'departments' => ['TICKET'],                                 'folder' => 'ticket'],
        ['key' => 'smoking',          'label' => 'Smoking',         'type' => self::TYPE_SALES,     'departments' => ['SMOKING', 'CIGAR & CIGARETTE', 'SHISHA'], 'folder' => 'smoking'],
        ['key' => 'other',            'label' => 'Other',           'type' => self::TYPE_SALES,     'departments' => ['OTHER'],                                  'folder' => 'other'],
        ['key' => 'bev-compl',        'label' => 'Beverage',        'type' => self::TYPE_NON_SALES, 'departments' => ['BEVERAGE'],                               'folder' => 'bev compl'],
        ['key' => 'event-compl',      'label' => 'Event',           'type' => self::TYPE_NON_SALES, 'departments' => ['EVENT'],                                  'folder' => 'event compl'],
        ['key' => 'bev-attika-compl', 'label' => 'Beverage Attika', 'type' => self::TYPE_NON_SALES, 'departments' => ['BEVERAGE ATTIKA'],                        'folder' => 'bev attika compl'],
        ['key' => 'food-compl',       'label' => 'Food',            'type' => self::TYPE_NON_SALES, 'departments' => ['FOOD'],                                   'folder' => 'food compl'],
        ['key' => 'ticket-compl',     'label' => 'Ticket',          'type' => self::TYPE_NON_SALES, 'departments' => ['TICKET'],                                 'folder' => 'ticket compl'],
        ['key' => 'smoking-compl',    'label' => 'Smoking',         'type' => self::TYPE_NON_SALES, 'departments' => ['SMOKING'],                                'folder' => 'smoking compl'],
        ['key' => 'other-compl',      'label' => 'Other',           'type' => self::TYPE_NON_SALES, 'departments' => ['OTHER'],                                  'folder' => 'other compl'],
        ['key' => 'promo-compl',      'label' => 'Promo',           'type' => self::TYPE_NON_SALES, 'departments' => ['PROMO'],                                  'folder' => 'promo compl'],
        ['key' => 'partner-compl',    'label' => 'Partner',         'type' => self::TYPE_NON_SALES, 'departments' => ['PARTNER'],                                'folder' => 'partner compl'],
        ['key' => 'promo',            'label' => 'Promo',           'type' => self::TYPE_SALES,     'departments' => ['PROMO'],                                  'folder' => 'promo'],
        ['key' => 'partner',          'label' => 'Partner',         'type' => self::TYPE_SALES,     'departments' => ['PARTNER'],                                'folder' => 'partner'],
        ['key' => 'event',            'label' => 'Event',           'type' => self::TYPE_SALES,     'departments' => ['EVENT'],                                  'folder' => 'event'],
        ['key' => 'voucher',          'label' => 'Voucher',         'type' => self::TYPE_SALES,     'departments' => ['TICKET'],                                 'folder' => 'voucher'],
    ];

    public static function types(): array
    {
        return self::configuration()['types'];
    }

    public static function all(): array
    {
        return self::configuration()['categories'];
    }

    public static function keys(): array
    {
        return array_column(self::all(), 'key');
    }

    public static function find(string $key): ?array
    {
        $key = strtolower(trim($key));
        foreach (self::all() as $category) {
            if ($category['key'] === $key) {
                return $category;
            }
        }
        return null;
    }

    public static function findType(string $key): ?array
    {
        $key = strtolower(trim($key));
        foreach (self::types() as $type) {
            if ($type['key'] === $key) {
                return $type;
            }
        }
        return null;
    }

    public static function typeFor(array $category): array
    {
        return self::findType((string) ($category['type'] ?? '')) ?? self::types()[0];
    }

    public static function default(): array
    {
        return self::all()[0];
    }

    /** Compatibility helper: true for any type configured to zero prices. */
    public static function isNonSales(array $category): bool
    {
        return (bool) (self::typeFor($category)['zero_prices'] ?? false);
    }

    public static function invoiceMode(array $category): string
    {
        return self::typeFor($category)['invoice_mode'];
    }

    public static function configPath(): string
    {
        $override = trim((string) ($_ENV['EXPORT_CONFIG_FILE'] ?? ''));
        return $override !== '' ? $override : dirname(__DIR__) . '/config/sales_exports.json';
    }

    /** Validate setup input and return a normalized configuration. */
    public static function normalize(array $types, array $categories): array
    {
        if (!$types) {
            throw new \RuntimeException('Add at least one export type.');
        }
        if (!$categories) {
            throw new \RuntimeException('Add at least one export category.');
        }

        $normalizedTypes = [];
        $typeKeys = [];
        foreach (array_values($types) as $type) {
            $key = self::normalizeKey((string) ($type['key'] ?? ''), 'Type');
            if (isset($typeKeys[$key])) {
                throw new \RuntimeException("Export type key '{$key}' is duplicated.");
            }
            $label = trim((string) ($type['label'] ?? ''));
            $invoiceMode = (string) ($type['invoice_mode'] ?? 'required');
            $filenameMode = (string) ($type['filename_mode'] ?? 'unique');
            $prefix = trim((string) ($type['filename_prefix'] ?? ''));
            if ($label === '' || $prefix === '') {
                throw new \RuntimeException("Complete the label and filename prefix for type '{$key}'.");
            }
            if (!in_array($invoiceMode, ['required', 'missing', 'any'], true)) {
                throw new \RuntimeException("Invalid invoice rule for type '{$key}'.");
            }
            if (!in_array($filenameMode, ['unique', 'daily'], true)) {
                throw new \RuntimeException("Invalid filename rule for type '{$key}'.");
            }
            if (!preg_match('/^[A-Za-z0-9_-]+$/', $prefix)) {
                throw new \RuntimeException("Filename prefix for type '{$key}' may use letters, numbers, hyphens, and underscores only.");
            }
            $typeKeys[$key] = true;
            $normalizedTypes[] = [
                'key' => $key,
                'label' => $label,
                'invoice_mode' => $invoiceMode,
                'zero_prices' => self::toBool($type['zero_prices'] ?? false),
                'filename_mode' => $filenameMode,
                'filename_prefix' => $prefix,
            ];
        }

        $normalizedCategories = [];
        $categoryKeys = [];
        foreach (array_values($categories) as $category) {
            $key = self::normalizeKey((string) ($category['key'] ?? ''), 'Category');
            if (isset($categoryKeys[$key])) {
                throw new \RuntimeException("Category key '{$key}' is duplicated.");
            }
            $label = trim((string) ($category['label'] ?? ''));
            $type = strtolower(trim((string) ($category['type'] ?? '')));
            $folder = trim((string) ($category['folder'] ?? ''), "/\\ \t\n\r\0\x0B");
            $departments = $category['departments'] ?? [];
            if (is_string($departments)) {
                $departments = explode(',', $departments);
            }
            $departments = array_values(array_unique(array_filter(array_map(
                static fn ($department) => trim((string) $department),
                is_array($departments) ? $departments : []
            ), static fn ($department) => $department !== '')));
            if ($label === '' || $folder === '' || !$departments) {
                throw new \RuntimeException("Complete the label, departments, and SFTP folder for category '{$key}'.");
            }
            if (!isset($typeKeys[$type])) {
                throw new \RuntimeException("Category '{$key}' uses an unknown export type.");
            }
            if (str_contains($folder, '..') || preg_match('/[\r\n]/', $folder)) {
                throw new \RuntimeException("Invalid SFTP folder for category '{$key}'.");
            }
            $categoryKeys[$key] = true;
            $normalizedCategories[] = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'departments' => $departments,
                'folder' => $folder,
            ];
        }

        return ['types' => $normalizedTypes, 'categories' => $normalizedCategories];
    }

    public static function save(array $types, array $categories): void
    {
        $configuration = self::normalize($types, $categories);
        $path = self::configPath();
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create the export configuration directory.');
        }
        $contents = json_encode($configuration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $temporary = tempnam($directory, 'sales-exports-');
        if ($contents === false || $temporary === false || file_put_contents($temporary, $contents . PHP_EOL, LOCK_EX) === false) {
            @unlink((string) $temporary);
            throw new \RuntimeException('Unable to write the export configuration.');
        }
        if (!@rename($temporary, $path)) {
            // Windows cannot rename over an existing file. Keep a rollback copy
            // while replacing it, matching the safe .env writer behavior.
            $backup = $path . '.backup-' . bin2hex(random_bytes(4));
            if (!is_file($path) || !@rename($path, $backup) || !@rename($temporary, $path)) {
                if (is_file($backup) && !is_file($path)) {
                    @rename($backup, $path);
                }
                @unlink($temporary);
                throw new \RuntimeException('Unable to replace the export configuration.');
            }
            @unlink($backup);
        }
    }

    private static function configuration(): array
    {
        $fallback = ['types' => self::DEFAULT_TYPES, 'categories' => self::DEFAULT_CATEGORIES];
        $path = self::configPath();
        if (!is_file($path)) {
            return $fallback;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return $fallback;
        }
        try {
            return self::normalize(
                is_array($decoded['types'] ?? null) ? $decoded['types'] : [],
                is_array($decoded['categories'] ?? null) ? $decoded['categories'] : []
            );
        } catch (\RuntimeException $e) {
            return $fallback;
        }
    }

    private static function normalizeKey(string $key, string $label): string
    {
        $key = strtolower(trim($key));
        if (!preg_match('/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/', $key)) {
            throw new \RuntimeException("{$label} keys may use lowercase letters, numbers, hyphens, and underscores only.");
        }
        return $key;
    }

    private static function toBool($value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'on';
    }
}
