<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Livewire\Admin\Clients\Index as AdminClients;
use App\Livewire\Admin\Projects\Edit as AdminProjectEdit;
use App\Livewire\Admin\Projects\Index as AdminProjects;
use App\Livewire\Admin\Rates\Index as AdminRates;
use App\Livewire\Admin\Tasks\Index as AdminTasks;
use App\Livewire\Admin\Timesheets\Index as AdminTimesheets;
use App\Livewire\Admin\Users\Index as AdminUsers;
use App\Livewire\Reports\ClientsReport;
use App\Livewire\Reports\JdwReport;
use App\Livewire\Reports\ProjectBudget;
use App\Livewire\Reports\ProjectsReport;
use App\Livewire\Reports\TasksReport;
use App\Livewire\Reports\TeamOverviewReport;
use App\Livewire\Reports\TeamReport;
use App\Livewire\Reports\TimeReport;
use App\Livewire\Timesheet\DayView;
use App\Models\User;
use Illuminate\Support\Facades\Route;

// Local-only demo login — bypasses Google SSO for local tours.
if (app()->environment('local')) {
    Route::get('/demo-login', function () {
        $user = User::where('email', config('app.admin_email', env('ADMIN_EMAIL')))->firstOrFail();
        auth()->login($user);

        return redirect()->route('timesheet');
    })->name('demo.login');
}

// Auth routes (unauthenticated)
Route::get('/auth/google', [GoogleController::class, 'redirect'])->name('auth.google');
Route::get('/auth/google/callback', [GoogleController::class, 'callback'])->name('auth.google.callback')->middleware('throttle:google-oauth');
Route::get('/auth/error', fn () => view('auth.error'))->name('auth.error');
Route::get('/login', fn () => view('auth.login'))->name('auth.login');
Route::post('/auth/logout', function () {
    auth()->logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect('/');
})->name('auth.logout')->middleware('auth');

// Authenticated routes
Route::middleware('auth')->group(function () {
    Route::get('/', fn () => redirect()->route('timesheet'));
    Route::get('/timesheet', DayView::class)->name('timesheet');
    Route::get('/timesheet/song/{date}', function (string $date) {
        $path = base_path('sourcefiles/songs/depeche_mode_song_titles.csv');
        $handle = fopen($path, 'r');
        $songs = [];
        fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== false) {
            if (($row[3] ?? '') === 'album_track') {
                $songs[] = ['song_name' => $row[0], 'album' => $row[1], 'year' => $row[2]];
            }
        }
        fclose($handle);
        // Seed by date so same day always returns same song
        mt_srand((int) crc32($date));
        $song = $songs[mt_rand(0, count($songs) - 1)];

        return response()->json($song);
    })->name('timesheet.song');

    // Report routes (manager + admin)
    Route::middleware('can:access-reports')->prefix('reports')->name('reports.')->group(function () {
        Route::get('/time', TimeReport::class)->name('time');
        Route::get('/clients', ClientsReport::class)->name('clients');
        Route::get('/projects', ProjectsReport::class)->name('projects');
        Route::get('/projects/{project}/budget', ProjectBudget::class)->name('projects.budget');
        Route::get('/tasks', TasksReport::class)->name('tasks');
        Route::get('/team', TeamOverviewReport::class)->name('team');
        Route::get('/team/{user}', TeamReport::class)->name('team.member');
        Route::get('/jdw', JdwReport::class)->name('jdw');
    });

    // Admin routes
    Route::middleware('can:access-admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/users', AdminUsers::class)->name('users');
        Route::get('/clients', AdminClients::class)->name('clients');
        Route::get('/tasks', AdminTasks::class)->name('tasks');
        Route::get('/projects', AdminProjects::class)->name('projects');
        Route::get('/projects/{project}/edit', AdminProjectEdit::class)->name('projects.edit');
        Route::get('/rates', AdminRates::class)->name('rates');
        Route::get('/timesheets', AdminTimesheets::class)->name('timesheets');
        Route::get('/timesheets/{user}', DayView::class)->name('timesheets.user');
    });
});
