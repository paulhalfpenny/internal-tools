<?php

namespace App\Domain\Reporting;

use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

final class JdwXlsxExport implements WithEvents, WithTitle
{
    // Programme block: columns C–S (17 task columns), total in T
    private const PROG_START_COL = 'C';

    private const PROG_TOTAL_COL = 'T';

    private const PROG_ROW = 13;

    // Projects block: columns C–I (7 task columns), total in J, metadata in L/N/O
    private const PROJ_START_COL = 'C';

    private const PROJ_END_COL = 'I';

    private const PROJ_TOTAL_COL = 'J';

    private const PROJ_STATUS_COL = 'L';

    private const PROJ_LAUNCH_COL = 'N';

    private const PROJ_DESC_COL = 'O';

    private const PROJ_HEADER_ROW = 16;

    private const PROJ_FIRST_DATA_ROW = 17;

    private const PROJ_LAST_DATA_ROW = 56;

    private const PROJ_TOTAL_ROW = 57;

    // S&M block: columns C–F (4 task columns), total in G
    private const SM_START_COL = 'C';

    private const SM_END_COL = 'F';

    private const SM_TOTAL_COL = 'G';

    private const SM_SECTION_ROW = 60;

    private const SM_HEADER_ROW = 62;

    private const SM_FIRST_DATA_ROW = 63;

    public function __construct(
        private readonly CarbonImmutable $month,
        private readonly JdwReportQuery $query
    ) {}

    public function title(): string
    {
        return $this->month->format('M - y');
    }

    /** @return array<class-string, callable> */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();

                // ── Row 1: month label ──────────────────────────────────────
                $sheet->setCellValue('A1', 'Month:');
                $sheet->setCellValue('B1', $this->month->startOfMonth()->format('d/m/Y'));

                // ── Row 9: section header ───────────────────────────────────
                $sheet->setCellValue('A9', 'Planning, Development & Delivery');

                // ── Row 10: R&P / TMC tag row ───────────────────────────────
                // Maps each Programme column to its billing category
                $progTasks = JdwReportQuery::PROGRAMME_TASKS;
                $rAndP = ['Planning', 'Project Management, Meetings & Reporting', 'Design', 'Admin', 'Systems Admin', 'Research', 'Finance'];
                $colIdx = Coordinate::columnIndexFromString(self::PROG_START_COL);
                foreach ($progTasks as $task) {
                    $col = Coordinate::stringFromColumnIndex($colIdx);
                    $sheet->setCellValue($col.'10', in_array($task, $rAndP, true) ? 'R&P' : 'TMC');
                    $colIdx++;
                }

                // ── Row 11: Programme Management column headers ─────────────
                $sheet->setCellValue('A11', 'Programme Management Hours');
                $sheet->setCellValue('B11', 'Project Code');
                $colIdx = Coordinate::columnIndexFromString(self::PROG_START_COL);
                foreach ($progTasks as $task) {
                    $col = Coordinate::stringFromColumnIndex($colIdx);
                    $sheet->setCellValue($col.'11', $task);
                    $colIdx++;
                }
                $sheet->setCellValue(self::PROG_TOTAL_COL.'11', 'Total');

                // ── Row 13: Programme hours ─────────────────────────────────
                $progHours = $this->query->programmeRow();
                $colIdx = Coordinate::columnIndexFromString(self::PROG_START_COL);
                foreach ($progTasks as $task) {
                    $col = Coordinate::stringFromColumnIndex($colIdx);
                    $val = $progHours[$task] ?? null;
                    if ($val !== null) {
                        $sheet->setCellValue($col.self::PROG_ROW, $val);
                        $sheet->getStyle($col.self::PROG_ROW)
                            ->getNumberFormat()
                            ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2);
                    }
                    $colIdx++;
                }
                $endCol = Coordinate::stringFromColumnIndex(Coordinate::columnIndexFromString(self::PROG_START_COL) + count($progTasks) - 1);
                $sheet->setCellValue(self::PROG_TOTAL_COL.self::PROG_ROW, '=SUM('.self::PROG_START_COL.self::PROG_ROW.':'.$endCol.self::PROG_ROW.')');

                // ── Row 16: Projects block headers ─────────────────────────
                $sheet->setCellValue('A'.self::PROJ_HEADER_ROW, 'Projects Hours');
                $sheet->setCellValue('B'.self::PROJ_HEADER_ROW, 'Project Code');
                $projTasks = JdwReportQuery::PROJECTS_TASKS;
                $colIdx = Coordinate::columnIndexFromString(self::PROJ_START_COL);
                foreach ($projTasks as $task) {
                    $col = Coordinate::stringFromColumnIndex($colIdx);
                    $sheet->setCellValue($col.self::PROJ_HEADER_ROW, $task);
                    $colIdx++;
                }
                $sheet->setCellValue(self::PROJ_TOTAL_COL.self::PROJ_HEADER_ROW, 'Total');
                $sheet->setCellValue(self::PROJ_STATUS_COL.self::PROJ_HEADER_ROW, 'Project Status');
                $sheet->setCellValue(self::PROJ_LAUNCH_COL.self::PROJ_HEADER_ROW, 'Est Launch');
                $sheet->setCellValue(self::PROJ_DESC_COL.self::PROJ_HEADER_ROW, 'Project Description');

                // ── Rows 17–56: Project data rows ──────────────────────────
                $projects = $this->query->projectsRows();
                $row = self::PROJ_FIRST_DATA_ROW;
                foreach ($projects as $project) {
                    $sheet->setCellValue('A'.$row, $project['name']);
                    if ($project['code'] !== null) {
                        $sheet->setCellValue('B'.$row, $project['code']);
                    }
                    $colIdx = Coordinate::columnIndexFromString(self::PROJ_START_COL);
                    foreach ($projTasks as $task) {
                        $col = Coordinate::stringFromColumnIndex($colIdx);
                        $val = $project['hours'][$task] ?? null;
                        if ($val !== null) {
                            $sheet->setCellValue($col.$row, $val);
                            $sheet->getStyle($col.$row)
                                ->getNumberFormat()
                                ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2);
                        }
                        $colIdx++;
                    }
                    $sheet->setCellValue(self::PROJ_TOTAL_COL.$row, '=SUM('.self::PROJ_START_COL.$row.':'.self::PROJ_END_COL.$row.')');
                    if ($project['jdw_status'] !== null) {
                        $sheet->setCellValue(self::PROJ_STATUS_COL.$row, $project['jdw_status']);
                    }
                    if ($project['jdw_estimated_launch'] !== null) {
                        $sheet->setCellValue(self::PROJ_LAUNCH_COL.$row, $project['jdw_estimated_launch']);
                    }
                    if ($project['jdw_description'] !== null) {
                        $sheet->setCellValue(self::PROJ_DESC_COL.$row, $project['jdw_description']);
                    }
                    $row++;
                }
                // Fill remaining rows up to row 56 with empty + formula
                for ($r = $row; $r <= self::PROJ_LAST_DATA_ROW; $r++) {
                    $sheet->setCellValue(self::PROJ_TOTAL_COL.$r, '=SUM('.self::PROJ_START_COL.$r.':'.self::PROJ_END_COL.$r.')');
                }

                // ── Row 57: Projects total ──────────────────────────────────
                $sheet->setCellValue(self::PROJ_TOTAL_COL.self::PROJ_TOTAL_ROW, '=SUM('.self::PROJ_TOTAL_COL.self::PROJ_FIRST_DATA_ROW.':'.self::PROJ_TOTAL_COL.self::PROJ_LAST_DATA_ROW.')');

                // ── Row 60: S&M section header ──────────────────────────────
                $sheet->setCellValue('A'.self::SM_SECTION_ROW, 'Support and Maintenance');

                // ── Row 62: S&M column headers ──────────────────────────────
                $sheet->setCellValue('A'.self::SM_HEADER_ROW, 'Support and Maintenance Hours');
                $sheet->setCellValue('B'.self::SM_HEADER_ROW, 'Project Code');
                $smTasks = JdwReportQuery::SM_TASKS;
                $colIdx = Coordinate::columnIndexFromString(self::SM_START_COL);
                foreach ($smTasks as $task) {
                    $col = Coordinate::stringFromColumnIndex($colIdx);
                    $sheet->setCellValue($col.self::SM_HEADER_ROW, $task);
                    $colIdx++;
                }
                $sheet->setCellValue(self::SM_TOTAL_COL.self::SM_HEADER_ROW, 'Total');

                // ── S&M data rows ───────────────────────────────────────────
                $smRows = $this->query->smRows();
                $row = self::SM_FIRST_DATA_ROW;
                $smFirstRow = $row;
                foreach ($smRows as $project) {
                    $sheet->setCellValue('A'.$row, $project['name']);
                    if ($project['code'] !== null) {
                        $sheet->setCellValue('B'.$row, $project['code']);
                    }
                    $colIdx = Coordinate::columnIndexFromString(self::SM_START_COL);
                    foreach ($smTasks as $task) {
                        $col = Coordinate::stringFromColumnIndex($colIdx);
                        $val = $project['hours'][$task] ?? null;
                        if ($val !== null) {
                            $sheet->setCellValue($col.$row, $val);
                            $sheet->getStyle($col.$row)
                                ->getNumberFormat()
                                ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2);
                        }
                        $colIdx++;
                    }
                    $sheet->setCellValue(self::SM_TOTAL_COL.$row, '=SUM('.self::SM_START_COL.$row.':'.self::SM_END_COL.$row.')');
                    $row++;
                }
                $smLastRow = $row - 1;

                // S&M totals row
                if ($smLastRow >= $smFirstRow) {
                    $totRow = $row;
                    $colIdx = Coordinate::columnIndexFromString(self::SM_START_COL);
                    foreach ($smTasks as $task) {
                        $col = Coordinate::stringFromColumnIndex($colIdx);
                        $sheet->setCellValue($col.$totRow, '=SUM('.$col.$smFirstRow.':'.$col.$smLastRow.')');
                        $colIdx++;
                    }
                    $sheet->setCellValue(self::SM_TOTAL_COL.$totRow, '=SUM('.self::SM_TOTAL_COL.$smFirstRow.':'.self::SM_TOTAL_COL.$smLastRow.')');
                }
            },
        ];
    }
}
