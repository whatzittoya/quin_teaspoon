<?php

namespace App;

/**
 * The 18 upload categories (see SFTP.xlsx).
 *
 * Each category pairs a set of Quinos departments with a report type and the
 * SFTP subfolder its CSV lands in. Folders are relative to SFTP_REMOTE_DIR,
 * so `bev attika` becomes `uploads/bev attika` with the default .env.
 *
 * Single source of truth for both the web app and scheduler/upload_sales.php.
 */
final class SalesCategories
{
    public const TYPE_SALES = 'sales';
    public const TYPE_NON_SALES = 'non_sales';

    /**
     * Ordered as in the spreadsheet's NO column.
     *
     * key          URL/filename-safe identifier used by the API and the UI
     * label        department name as shown to staff
     * type         sales = invoiced, non_sales = no invoice (prices zeroed in the CSV)
     * departments  matched against tbl_departments.name
     * folder       SFTP subfolder under SFTP_REMOTE_DIR
     */
    private const CATEGORIES = [
        ['key' => 'bev',              'label' => 'Beverage',        'type' => self::TYPE_SALES,     'departments' => ['BEVERAGE'],                                  'folder' => 'bev'],
        ['key' => 'bev-attika',       'label' => 'Beverage Attika', 'type' => self::TYPE_SALES,     'departments' => ['BEVERAGE ATTIKA'],                           'folder' => 'bev attika'],
        ['key' => 'food',             'label' => 'Food',            'type' => self::TYPE_SALES,     'departments' => ['FOOD'],                                      'folder' => 'food'],
        ['key' => 'ticket',           'label' => 'Ticket',          'type' => self::TYPE_SALES,     'departments' => ['TICKET'],                                    'folder' => 'ticket'],
        // Sheet row 9 groups three departments here; the matching "smoking compl"
        // row lists only SMOKING, and that difference is intentional.
        ['key' => 'smoking',          'label' => 'Smoking',         'type' => self::TYPE_SALES,     'departments' => ['SMOKING', 'CIGAR & CIGARETTE', 'SHISHA'],    'folder' => 'smoking'],
        ['key' => 'other',            'label' => 'Other',           'type' => self::TYPE_SALES,     'departments' => ['OTHER'],                                     'folder' => 'other'],
        ['key' => 'bev-compl',        'label' => 'Beverage',        'type' => self::TYPE_NON_SALES, 'departments' => ['BEVERAGE'],                                  'folder' => 'bev compl'],
        ['key' => 'event-compl',      'label' => 'Event',           'type' => self::TYPE_NON_SALES, 'departments' => ['EVENT'],                                     'folder' => 'event compl'],
        ['key' => 'bev-attika-compl', 'label' => 'Beverage Attika', 'type' => self::TYPE_NON_SALES, 'departments' => ['BEVERAGE ATTIKA'],                           'folder' => 'bev attika compl'],
        ['key' => 'food-compl',       'label' => 'Food',            'type' => self::TYPE_NON_SALES, 'departments' => ['FOOD'],                                      'folder' => 'food compl'],
        ['key' => 'ticket-compl',     'label' => 'Ticket',          'type' => self::TYPE_NON_SALES, 'departments' => ['TICKET'],                                    'folder' => 'ticket compl'],
        ['key' => 'smoking-compl',    'label' => 'Smoking',         'type' => self::TYPE_NON_SALES, 'departments' => ['SMOKING'],                                   'folder' => 'smoking compl'],
        ['key' => 'other-compl',      'label' => 'Other',           'type' => self::TYPE_NON_SALES, 'departments' => ['OTHER'],                                     'folder' => 'other compl'],
        ['key' => 'promo-compl',      'label' => 'Promo',           'type' => self::TYPE_NON_SALES, 'departments' => ['PROMO'],                                     'folder' => 'promo compl'],
        ['key' => 'partner-compl',    'label' => 'Partner',         'type' => self::TYPE_NON_SALES, 'departments' => ['PARTNER'],                                   'folder' => 'partner compl'],
        ['key' => 'promo',            'label' => 'Promo',           'type' => self::TYPE_SALES,     'departments' => ['PROMO'],                                     'folder' => 'promo'],
        ['key' => 'partner',          'label' => 'Partner',         'type' => self::TYPE_SALES,     'departments' => ['PARTNER'],                                   'folder' => 'partner'],
        ['key' => 'event',            'label' => 'Event',           'type' => self::TYPE_SALES,     'departments' => ['EVENT'],                                     'folder' => 'event'],
    ];

    /** @return array<int, array> */
    public static function all(): array
    {
        return self::CATEGORIES;
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_column(self::CATEGORIES, 'key');
    }

    public static function find(string $key): ?array
    {
        $key = strtolower(trim($key));
        foreach (self::CATEGORIES as $category) {
            if ($category['key'] === $key) {
                return $category;
            }
        }
        return null;
    }

    /** First sales category — the default view when none is requested. */
    public static function default(): array
    {
        return self::CATEGORIES[0];
    }

    public static function isNonSales(array $category): bool
    {
        return $category['type'] === self::TYPE_NON_SALES;
    }
}
