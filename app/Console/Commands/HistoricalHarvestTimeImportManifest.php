<?php

namespace App\Console\Commands;

final class HistoricalHarvestTimeImportManifest
{
    /**
     * @var list<array{
     *     target_code: string,
     *     source_client: string,
     *     source_code: string,
     *     source_project: string,
     *     from: string|null,
     *     expected_rows: int,
     *     table_amount: int
     * }>
     */
    private const PRODUCTION_MAPPINGS = [
        ['target_code' => 'DEN004', 'source_client' => '123Dentist', 'source_code' => 'DEN004', 'source_project' => 'Continuous Improvements Retainer (September 2025 - August 2026)', 'from' => '2025-09-01', 'expected_rows' => 282, 'table_amount' => 24105],
        ['target_code' => 'CEP001', 'source_client' => 'CEPA', 'source_code' => 'CEP001', 'source_project' => 'Website Maintenance Retainer (January 2026 - December 2026)', 'from' => '2026-01-01', 'expected_rows' => 165, 'table_amount' => 11025],
        ['target_code' => 'EAA001', 'source_client' => 'East Anglian Air Ambulance (EAAA)', 'source_code' => 'EAA001', 'source_project' => 'Continuous Improvements Retainer (August 2025 - July 2026)', 'from' => '2026-02-01', 'expected_rows' => 134, 'table_amount' => 10144],
        ['target_code' => 'ZED002', 'source_client' => 'Criterion Hospitality', 'source_code' => 'ZED002', 'source_project' => 'Zedwell Hotels - Continuous Improvement Retainer (December 2025 - November 2026)', 'from' => '2025-12-01', 'expected_rows' => 231, 'table_amount' => 17979],
        ['target_code' => 'HOP003', 'source_client' => 'Homeprotect', 'source_code' => 'HOP003', 'source_project' => 'Continuous Improvements', 'from' => '2026-05-01', 'expected_rows' => 70, 'table_amount' => 3863],
        ['target_code' => 'FUN006', 'source_client' => 'Fundraising Everywhere', 'source_code' => 'FUN008', 'source_project' => 'Continuous Improvements Retainer Uplift 2026', 'from' => '2026-06-01', 'expected_rows' => 73, 'table_amount' => 6847],
        ['target_code' => 'MED001', 'source_client' => 'Medivet', 'source_code' => 'MED001', 'source_project' => 'Digital Retainer FY27', 'from' => '2026-05-01', 'expected_rows' => 145, 'table_amount' => 16179],
        ['target_code' => 'AAB003', 'source_client' => 'AAB', 'source_code' => 'AAB003', 'source_project' => 'CRO Improvements + CR for Teams + Completion of CRO Project SOW - Build Phase', 'from' => null, 'expected_rows' => 228, 'table_amount' => 36715],
        ['target_code' => 'TOG013', 'source_client' => 'Tomorrows Guides', 'source_code' => 'TOG013', 'source_project' => "Tomorrow's Guides - Dynamic Care Home Fees", 'from' => null, 'expected_rows' => 5, 'table_amount' => 1083],
        ['target_code' => 'TOG012', 'source_client' => 'Tomorrows Guides', 'source_code' => 'TOG012', 'source_project' => 'CRO Improvements - carehome.co.uk - Build Phase', 'from' => null, 'expected_rows' => 96, 'table_amount' => 14947],
        ['target_code' => 'HOP005', 'source_client' => 'Homeprotect', 'source_code' => 'HOP005', 'source_project' => 'WebMCP Project', 'from' => null, 'expected_rows' => 15, 'table_amount' => 1337],
        ['target_code' => 'MED057', 'source_client' => 'Medivet', 'source_code' => 'MED057', 'source_project' => 'Key Modules Articles - Content Updates', 'from' => null, 'expected_rows' => 6, 'table_amount' => 1200],
    ];

    /** @var list<array{target_code: string, csv_amount: float}> */
    private const PRODUCTION_AMOUNT_EXCEPTIONS = [
        ['target_code' => 'DEN004', 'csv_amount' => 24743.0],
        ['target_code' => 'EAA001', 'csv_amount' => 10884.5],
    ];

    /**
     * @var list<array{
     *     source_id: string,
     *     target_code: string,
     *     spent_on: string,
     *     user_name: string,
     *     task_name: string,
     *     source_hours: float,
     *     source_amount: float,
     *     existing_rows: int,
     *     existing_hours: float,
     *     existing_amount: float
     * }>
     */
    private const PRODUCTION_SKIPS = [
        [
            'source_id' => 'historical-time:v1:2711fc10ba973071d9914fb40d3f97a9e4e0b895dff7d51a6d35ff1eafdd9cb6:1',
            'target_code' => 'MED001',
            'spent_on' => '2026-06-29',
            'user_name' => 'Chris Parsons',
            'task_name' => 'Development',
            'source_hours' => 6.0,
            'source_amount' => 600.0,
            'existing_rows' => 1,
            'existing_hours' => 7.5,
            'existing_amount' => 750.0,
        ],
        [
            'source_id' => 'historical-time:v1:fe2bf1b23a1557692d23c2dd8f8acb6df82d04cf5a0d8ea42dc23a2d952a05ae:1',
            'target_code' => 'AAB003',
            'spent_on' => '2026-06-29',
            'user_name' => 'Hayk Sargsyan',
            'task_name' => 'Development',
            'source_hours' => 7.0,
            'source_amount' => 700.0,
            'existing_rows' => 4,
            'existing_hours' => 7.0,
            'existing_amount' => 700.0,
        ],
        [
            'source_id' => 'historical-time:v1:3b89388219ac026137a1f2306eb540126813ea4b5f880b127a4ca05ed5aaee5f:1',
            'target_code' => 'AAB003',
            'spent_on' => '2026-06-30',
            'user_name' => 'Hayk Sargsyan',
            'task_name' => 'Development',
            'source_hours' => 7.75,
            'source_amount' => 775.0,
            'existing_rows' => 2,
            'existing_hours' => 7.0,
            'existing_amount' => 700.0,
        ],
    ];

    /**
     * @param  array<array-key, mixed>|null  $mappings
     * @param  array<array-key, mixed>|null  $approvedAmountExceptions
     * @param  array<array-key, mixed>|null  $approvedSkips
     */
    public function __construct(
        private readonly ?array $mappings = null,
        private readonly ?array $approvedAmountExceptions = null,
        private readonly ?array $approvedSkips = null,
    ) {}

    /** @return array<array-key, mixed> */
    public function mappings(): array
    {
        return $this->mappings ?? self::PRODUCTION_MAPPINGS;
    }

    /** @return array<array-key, mixed> */
    public function approvedAmountExceptions(): array
    {
        return $this->approvedAmountExceptions ?? ($this->mappings === null ? self::PRODUCTION_AMOUNT_EXCEPTIONS : []);
    }

    /** @return array<array-key, mixed> */
    public function approvedSkips(): array
    {
        return $this->approvedSkips ?? ($this->mappings === null ? self::PRODUCTION_SKIPS : []);
    }
}
