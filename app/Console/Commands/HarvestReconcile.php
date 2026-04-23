<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class HarvestReconcile extends Command
{
    protected $signature = 'harvest:reconcile
        {path : Path to the Harvest detailed-time CSV file}';

    protected $description = 'Compare a Harvest CSV export against the database month-by-month';

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! file_exists($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $csvMonths = $this->aggregateCsv($path);
        $dbMonths = $this->aggregateDb(array_keys($csvMonths));

        $hasVariance = false;

        $this->table(
            ['Month', 'CSV Hours', 'DB Hours', 'Δ Hours', 'CSV Amount', 'DB Amount', 'Δ Amount', 'Status'],
            collect($csvMonths)->map(function (array $csv, string $month) use ($dbMonths, &$hasVariance): array {
                $db = $dbMonths[$month] ?? ['hours' => 0.0, 'amount' => 0.0];
                $deltaHours = round(abs($csv['hours'] - $db['hours']), 2);
                $deltaAmount = round(abs($csv['amount'] - $db['amount']), 2);
                $flagged = $deltaHours > 0.1 || $deltaAmount > 1.0;

                if ($flagged) {
                    $hasVariance = true;
                }

                return [
                    $month,
                    number_format($csv['hours'], 2),
                    number_format($db['hours'], 2),
                    $deltaHours > 0 ? "⚠ {$deltaHours}" : '✓',
                    '£'.number_format($csv['amount'], 2),
                    '£'.number_format($db['amount'], 2),
                    $deltaAmount > 0 ? "⚠ £{$deltaAmount}" : '✓',
                    $flagged ? '❌ VARIANCE' : '✓ OK',
                ];
            })->values()->all()
        );

        if ($hasVariance) {
            $this->warn('Variance detected above thresholds (>0.1 h or >£1). Investigate before proceeding.');

            return self::FAILURE;
        }

        $this->info('All months reconcile within tolerance.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, array{hours: float, amount: float}>
     */
    private function aggregateCsv(string $path): array
    {
        $months = [];
        $handle = fopen($path, 'r');
        assert($handle !== false);
        fgetcsv($handle); // skip header

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 17) {
                continue;
            }

            $date = $row[0];
            $hours = (float) $row[6];
            $amount = (float) $row[16];
            $month = substr($date, 0, 7); // YYYY-MM

            $months[$month]['hours'] = ($months[$month]['hours'] ?? 0.0) + $hours;
            $months[$month]['amount'] = ($months[$month]['amount'] ?? 0.0) + $amount;
        }

        fclose($handle);
        ksort($months);

        return $months;
    }

    /**
     * @param  array<int, string>  $months  YYYY-MM strings
     * @return array<string, array{hours: float, amount: float}>
     */
    private function aggregateDb(array $months): array
    {
        if (empty($months)) {
            return [];
        }

        $rows = DB::table('time_entries')
            ->selectRaw("DATE_FORMAT(spent_on, '%Y-%m') as month, SUM(hours) as hours, SUM(billable_amount) as amount")
            ->whereRaw("DATE_FORMAT(spent_on, '%Y-%m') IN (".implode(',', array_fill(0, count($months), '?')).')', $months)
            ->groupByRaw("DATE_FORMAT(spent_on, '%Y-%m')")
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[$row->month] = [
                'hours' => (float) $row->hours,
                'amount' => (float) $row->amount,
            ];
        }

        return $result;
    }
}
