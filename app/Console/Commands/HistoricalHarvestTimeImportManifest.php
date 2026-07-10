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

    /** @param array<array-key, mixed>|null $mappings */
    public function __construct(private readonly ?array $mappings = null) {}

    /** @return array<array-key, mixed> */
    public function mappings(): array
    {
        return $this->mappings ?? self::PRODUCTION_MAPPINGS;
    }
}
