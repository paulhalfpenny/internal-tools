<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Task;
use Illuminate\Database\Seeder;

class HarvestImportSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedClients();
        $this->seedTasks();
    }

    private function seedClients(): void
    {
        $clients = [
            'Z. JD Wetherspoon - OLD DO NOT USE',
            'Code Wizards',
            'JDW: Support & Maintenance',
            'JDW: myJDW Continual Releases',
            'Medivet',
            'Filter',
            'JDW: Enhancements',
            'Crystal Palace FC',
            'House of Garrard',
            'JDW: Order & Pay',
            'JDW: Advance Table Bookings',
            'TeamSport',
            'JDW: myJDW Training Materials',
            'JDW: Auth0',
            'Defiance Media Group',
            'New Business',
            'JDW: myJDW Commercialisation',
            'JDW: Security',
            'JDW: Careers website',
            'Autotech Recruit',
            'JDW: University',
            'JDW: Soft Clocks App and Microservice',
            'JDW: mySchedule',
            'Quod',
            'JDW: Property Maintenance Redevelopment',
            'Empower Up',
            'Ennismore',
            'Vita',
            'JDW: Other projects',
            'JDW: Customer App',
            'Tomorrows Guides',
            'Homeprotect',
            'AbleDocs > ADScan',
            'Seoul Bird',
            'Alter Domus',
            'Inside Out Community',
            'Trane',
            'Bright Interactive',
            'CEPA',
            'JDW Management',
            'JDW Projects',
            'JDW Support & Maintenance',
            'Fundraising Everywhere',
            'Laguna Tools',
            'Children with Cancer UK',
            'MCS Rental Software',
            'College of Optometrists',
            '123Dentist',
            'Oliver James',
            'Head for Points',
            'AAB',
            'GravityKit',
            'East Anglian Air Ambulance (EAAA)',
            'Filter - SEO & Paid Media Services',
            'CREST',
            'Criterion Hospitality',
            'Agile Business Consortium',
            'Record Power',
        ];

        foreach ($clients as $name) {
            Client::firstOrCreate(['name' => $name]);
        }
    }

    private function seedTasks(): void
    {
        // [name, is_default_billable]
        $tasks = [
            ['Design', true],
            ['Development', true],
            ['Project Management, Meetings, Reporting', true],
            ['Business Development', true],
            ['Holiday', true],
            ['Planning', true],
            ['UX', true],
            ['Testing', true],
            ['Release', true],
            ['Maintenance', true],
            ['Customer support', true],
            ['Client Management', true],
            ['Systems Admin', true],
            ['Meeting', true],
            ['Admin', true],
            ['Content build', true],
            ['Reporting', true],
            ['Finance', true],
            ['HR', true],
            ['Training', false],
            ['Content Creation', true],
            ['Sick', true],
            ['Event', true],
            ['Break', true],
            ['Lunch', true],
            ['Research', false],
            ['Recruitment', true],
            ['Scope Writing', true],
            ['Bank holiday', true],
            ['Travel', true],
            ['Programme Activity', true],
            ['Strategy', true],
            ['Other Absence', true],
            ['Tavel', true],
            ['CRO Planning', true],
            ['CRO Meeting', true],
            ['Reporting & Analytics Development', true],
            ['Client Strategy', true],
            ['SEO Service Development', true],
            ['Paid Media Service Development', true],
            ['CI Process Development', true],
            ['CI Product Development', true],
            ['GA4 Implementation Audit', true],
            ['Accessibility Audit', true],
            ['Web Performance Audit', true],
            ['Rework', false],
        ];

        $colours = [
            '#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6',
            '#06B6D4', '#84CC16', '#F97316', '#EC4899', '#6366F1',
            '#14B8A6', '#A855F7', '#EAB308', '#22C55E', '#0EA5E9',
            '#D946EF', '#FB923C', '#64748B', '#78716C', '#6B7280',
            '#1D4ED8', '#065F46', '#BE123C', '#0369A1', '#15803D',
            '#7C3AED', '#B45309', '#0F766E', '#9D174D', '#4338CA',
        ];

        $maxOrder = Task::max('sort_order') ?? -1;
        $order = $maxOrder + 1;

        foreach ($tasks as [$name, $billable]) {
            Task::firstOrCreate(['name' => $name], [
                'is_default_billable' => $billable,
                'is_jdw_default_billable' => $billable,
                'colour' => $colours[$order % count($colours)],
                'sort_order' => $order,
                'is_archived' => false,
            ]);
            $order++;
        }
    }
}
