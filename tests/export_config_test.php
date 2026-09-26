<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\SalesCategories;
use App\SalesExport;

function exportAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
    }
}

$temporary = sys_get_temp_dir() . '/quinos-exports-' . bin2hex(random_bytes(5)) . '.json';
$_ENV['EXPORT_CONFIG_FILE'] = $temporary;

try {
    exportAssertSame('voucher', SalesCategories::find('voucher')['key'], 'Client-added Voucher category must remain in the defaults.');
    $defaultCategories = SalesCategories::all();
    foreach (['sales', 'non_sales', 'compliment'] as $typeKey) {
        exportAssertSame(
            10,
            count(array_filter($defaultCategories, static fn (array $category): bool => $category['type'] === $typeKey)),
            "The {$typeKey} defaults must contain the ten screenshot categories."
        );
    }
    exportAssertSame(['SMOKING'], SalesCategories::find('smoking')['departments'], 'Smoking must match the screenshot department.');
    exportAssertSame(['VOUCHER'], SalesCategories::find('voucher')['departments'], 'Voucher must use the VOUCHER department.');
    exportAssertSame('bev no', SalesCategories::find('bev-compl')['folder'], 'Non Sales folder mismatch.');
    exportAssertSame('bev compl', SalesCategories::find('bev-compliment')['folder'], 'Compliment folder mismatch.');

    $types = [
        ['key' => 'sale', 'label' => 'Sale', 'invoice_mode' => 'required', 'zero_prices' => false, 'filename_mode' => 'unique', 'filename_prefix' => 'sales'],
        ['key' => 'compliment', 'label' => 'Compliment', 'invoice_mode' => 'any', 'zero_prices' => true, 'filename_mode' => 'daily', 'filename_prefix' => 'compliment'],
    ];
    $categories = [
        ['key' => 'food', 'label' => 'Food', 'type' => 'sale', 'departments' => 'FOOD, EVENT', 'folder' => 'food'],
        ['key' => 'food-compliment', 'label' => 'Food compliment', 'type' => 'compliment', 'departments' => ['FOOD'], 'folder' => 'food compliment'],
    ];

    SalesCategories::save($types, $categories);
    exportAssertSame(['FOOD', 'EVENT'], SalesCategories::find('food')['departments'], 'Departments should be normalized.');
    exportAssertSame('any', SalesCategories::invoiceMode(SalesCategories::find('food-compliment')), 'Configured invoice behavior should be used.');
    exportAssertSame(true, SalesCategories::isNonSales(SalesCategories::find('food-compliment')), 'Configured zero-price behavior should be used.');

    $row = [[
        'date' => '2026-09-11 13:14:15',
        'quantity' => 2,
        'unitPrice' => 5,
        'discountAmount' => 0,
        'vat_percent' => 10,
        'item_code' => 'A',
        'description' => 'Test',
        'parent_code' => '',
    ]];
    $_ENV['CSV_DATE_FORMAT'] = 'm/d/Y';
    $csv = SalesExport::buildCsv($row, SalesCategories::find('food'));
    exportAssertSame(true, str_contains($csv, "09/11/2026;13:14:15"), 'CSV date format should come from setup.');
    $_ENV['CSV_DATE_FORMAT'] = 'invalid';
    exportAssertSame('d/m/Y', SalesExport::csvDateFormat(), 'Invalid CSV date formats should safely fall back.');
    $_ENV['CSV_DATE_FORMAT'] = 'm/d/Y';

    $compliment = SalesCategories::find('food-compliment');
    $complimentCsv = SalesExport::buildCsv($row, $compliment);
    exportAssertSame(true, str_contains($complimentCsv, ';0,00;0,00;'), 'Zero-price types should zero price and amount.');
    exportAssertSame('compliment260911.csv', SalesExport::csvFilename('2026-09-11', $compliment), 'Daily filename rule mismatch.');
    exportAssertSame('sales-2026-09-11-food-x1.csv', SalesExport::csvFilename('2026-09-11', SalesCategories::find('food'), 'x1'), 'Unique filename rule mismatch.');

    $failed = false;
    try {
        SalesCategories::normalize($types, [['key' => 'bad', 'label' => 'Bad', 'type' => 'unknown', 'departments' => ['FOOD'], 'folder' => 'bad']]);
    } catch (RuntimeException $e) {
        $failed = true;
    }
    exportAssertSame(true, $failed, 'Unknown category types must be rejected.');

    echo "Export configuration tests passed.\n";
} finally {
    @unlink($temporary);
    unset($_ENV['EXPORT_CONFIG_FILE'], $_ENV['CSV_DATE_FORMAT']);
}
