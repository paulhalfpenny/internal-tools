<?php

namespace Database\Seeders;

use App\Enums\JdwCategory;
use App\Models\Project;
use Illuminate\Database\Seeder;

/**
 * One-off seeder: populates jdw_* fields on existing projects using March 2026 workbook as source.
 * Run once after harvest:import for the initial data load. Ongoing maintenance is via the admin UI.
 *
 * Project names match what harvest:import creates from the Filter Harvest account.
 * Sort orders follow Olly's workbook row positions (rows 17–56 for Projects, rows 63+ for S&M).
 */
class JdwProjectMetadataSeeder extends Seeder
{
    public function run(): void
    {
        // ── Programme ──────────────────────────────────────────────────────────
        // All programme management hours are tracked against a single "Programme Activity" project.
        $this->set('Programme Activity', [
            'jdw_category' => JdwCategory::Programme,
        ]);

        // ── Projects ───────────────────────────────────────────────────────────
        // Ordered by jdw_sort_order, matching Olly's workbook rows 17–56.
        // Names are Harvest project names (as they appear after harvest:import).
        $projects = [
            // row 18
            ['name' => 'Authentication', 'sort' => 2,
                'status' => 'Live - with further phase Zonal Connect',
                'desc' => 'myAuth - standalone authentication service, to allow access to all JDW systems.'],
            // row 22
            ['name' => 'Customer Accounts - Customer App', 'sort' => 6, 'status' => 'Live',
                'desc' => 'Customer Order & Pay App - adding Customer Accounts to allow for personalised experiences.'],
            // row 23
            ['name' => 'Customer App - Ibersol Franchise Integration', 'sort' => 7,
                'status' => 'Planning / Development',
                'desc' => 'Customer Order & Pay App - Barcelona Airport Franchise Integration.'],
            // row 24
            ['name' => 'Customer App - Lagardere Franchise Integration', 'sort' => 8,
                'status' => 'Planning / Development',
                'desc' => 'Customer Order & Pay App - Alicante Airport Franchise Integration.'],
            // row 25
            ['name' => 'Customer App / PWA / App Manager', 'sort' => 9,
                'status' => 'Live - Continuous Improvements',
                'desc' => 'Customer Order & Pay App - ongoing development.'],
            // row 26
            ['name' => 'Customer App UX/UI Redesign', 'sort' => 10, 'status' => 'Development',
                'launch' => 'Phased Launches',
                'desc' => 'Customer Order & Pay App - application redesign.'],
            // row 29
            ['name' => 'Digital Vouchers', 'sort' => 13, 'status' => 'Development',
                'launch' => 'Jul-26',
                'desc' => 'Customer Order & Pay App - digitising the paper vouchers process.'],
            // row 30
            ['name' => 'Disaster Recovery & Business Continuity', 'sort' => 14, 'status' => 'Ongoing',
                'desc' => "Defining JDW's disaster recovery & business continuity principles, roles & responsibilities, service levels, costs, shared access requirements and system-specific plans"],
            // row 31
            ['name' => 'Employee Digital Records', 'sort' => 15, 'status' => 'Testing',
                'launch' => 'Jun-26',
                'desc' => 'Employee Digital Records - A new solution to centralise and manage employee data.'],
            // row 33
            ['name' => 'Franchises - Customer App+', 'sort' => 17,
                'status' => 'Live - Continuous Improvements',
                'desc' => 'Customer Order & Pay App - redevelopment of architecture to allow for different EPOS providers.'],
            // row 34
            ['name' => 'Franchises - myJDW', 'sort' => 18, 'status' => 'Development',
                'launch' => 'TBC',
                'desc' => "myJDW 'Lite' - for franchise employee access."],
            // row 35
            ['name' => 'Franchises - mySchedule', 'sort' => 19, 'status' => 'Planning',
                'launch' => 'TBC',
                'desc' => "mySchedule 'Lite' - for franchise employee access."],
            // row 36
            ['name' => 'Franchises - WP Websites', 'sort' => 20,
                'desc' => 'Corporate, Hotels, Investors, Franchising, Careers Websites.'],
            // row 37
            ['name' => 'Hotels HLS Integration', 'sort' => 21,
                'status' => 'Live - Rollout Happening',
                'launch' => 'Jan-26',
                'desc' => "Integration of Zonal's Hotel System (ZHS) with our existing systems and processes for managing hotel bookings and reservations."],
            // row 39
            ['name' => 'Intelligence/myLibrary Chatbot', 'sort' => 23,
                'status' => 'Live - Phase 2 in Development',
                'launch' => 'Nov-25 Pilot',
                'desc' => 'Daisy ChatBot - conversational AI Chatbot that can be used to access relevant information in myLibrary via myJDW app/websites and mySchedule'],
            // row 40
            ['name' => 'Microsoft Office Replacement', 'sort' => 24,
                'status' => 'Live - Phase 2 Planning',
                'desc' => 'Phase 1: Providing a solution to ensure pubs do not lose access to critical reports/processes with move to Office 365 F3 licences.'],
            // row 41
            ['name' => 'myETL', 'sort' => 25, 'status' => 'Development',
                'desc' => 'The new process for employee data imports (myETL).'],
            // row 42
            ['name' => 'myPub', 'sort' => 26, 'status' => 'On Hold', 'launch' => 'TBC',
                'desc' => 'myPub - New platform to manage the CQSMA process including a web based management and reporting tool and a new mobile app for callers.'],
            // row 44
            ['name' => 'myJDW', 'sort' => 28, 'status' => 'Live - Continuous Improvements',
                'desc' => 'myJDW - Employee application to clock-in and out of shifts, book holidays, chat etc.'],
            // row 45
            ['name' => 'mySchedule', 'sort' => 29, 'status' => 'Live - Continuous Improvements',
                'desc' => 'mySchedule - Scheduling and rota platform.'],
            // row 47
            ['name' => 'PCI DSS Compliance', 'sort' => 31, 'status' => 'Planning',
                'desc' => 'Customer Order & Pay App - Payment Card Industry Data Security Standard support as required.'],
            // row 48
            ['name' => 'QR Code Admin Replatform', 'sort' => 32, 'status' => 'Planning',
                'launch' => 'TBC',
                'desc' => 'Replatforming of QR Code generation platform.'],
            // row 53
            ['name' => 'Suggested Rotas', 'sort' => 37, 'status' => 'Development',
                'launch' => 'Apr-26',
                'desc' => 'mySchedule - Automation of rotas through the use of machine learning and AI technology.'],
            // row 55
            ['name' => 'Technical Planning', 'sort' => 39, 'status' => 'Ongoing',
                'desc' => 'Technical Activity Planning, which sits outside of current projects'],
            // row 56
            ['name' => 'WordPress Sites', 'sort' => 40, 'status' => 'Live - Continuous Improvements',
                'desc' => 'Corporate, Hotels, Investors, Franchising, Careers Websites.'],
        ];

        foreach ($projects as $data) {
            $this->set($data['name'], [
                'jdw_category' => JdwCategory::Project,
                'jdw_sort_order' => $data['sort'],
                'jdw_status' => $data['status'] ?? null,
                'jdw_estimated_launch' => $data['launch'] ?? null,
                'jdw_description' => $data['desc'] ?? null,
            ]);
        }

        // ── Support & Maintenance ───────────────────────────────────────────────
        // Names match Harvest S&M project names (harvest_time_report...(1).csv).
        $smProjects = [
            ['name' => 'CIS Support & Maintenance', 'sort' => 2],
            ['name' => 'Customer App / PWA / App Manager Support & Maintenance', 'sort' => 3],
            ['name' => 'myAuth Support', 'sort' => 4],
            ['name' => 'myJDW Support & Maintenance', 'sort' => 5],
            ['name' => 'mySchedule Support & Maintenance', 'sort' => 6],
            ['name' => 'Support for Franchises', 'sort' => 7],
            ['name' => 'WordPress Sites Support & Maintenance', 'sort' => 8],
        ];

        foreach ($smProjects as $data) {
            $this->set($data['name'], [
                'jdw_category' => JdwCategory::SupportMaintenance,
                'jdw_sort_order' => $data['sort'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function set(string $name, array $attrs): void
    {
        $rows = Project::whereRaw('LOWER(name) = ?', [strtolower($name)])->get();

        if ($rows->isEmpty()) {
            $this->command?->warn("Project not found: {$name} — run harvest:import first.");

            return;
        }

        foreach ($rows as $project) {
            $project->update($attrs);
        }
    }
}
