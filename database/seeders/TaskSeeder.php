<?php

namespace Database\Seeders;

use App\Models\Task;
use Illuminate\Database\Seeder;

class TaskSeeder extends Seeder
{
    public function run(): void
    {
        $billable = [
            'Planning',
            'Project Management, Meetings & Reporting',
            'Development',
            'Design',
            'Testing',
            'Release',
            'Research',
            'Systems Admin',
            'Maintenance',
            'Customer Support',
            'Training',
            'Admin',
        ];

        $nonBillable = [
            'Holiday',
            'Bank Holiday',
            'Sick',
            'Other Absence',
            'Lunch',
            'Break',
            'Travel',
            'Finance',
            'HR',
            'Recruitment',
        ];

        $colours = [
            '#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6',
            '#06B6D4', '#84CC16', '#F97316', '#EC4899', '#6366F1',
            '#14B8A6', '#A855F7', '#EAB308', '#22C55E', '#0EA5E9',
            '#D946EF', '#FB923C', '#64748B', '#78716C', '#6B7280',
            '#1D4ED8', '#065F46',
        ];

        $order = 0;
        foreach ($billable as $name) {
            Task::firstOrCreate(['name' => $name], [
                'is_default_billable' => true,
                'is_jdw_default_billable' => true,
                'colour' => $colours[$order % count($colours)],
                'sort_order' => $order,
            ]);
            $order++;
        }

        foreach ($nonBillable as $name) {
            Task::firstOrCreate(['name' => $name], [
                'is_default_billable' => false,
                'is_jdw_default_billable' => false,
                'colour' => $colours[$order % count($colours)],
                'sort_order' => $order,
            ]);
            $order++;
        }
    }
}
