<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Livewire\Admin\Clients\Index as AdminClients;
use App\Livewire\Admin\Projects\Create as AdminProjectCreate;
use App\Livewire\Admin\Projects\Edit as AdminProjectEdit;
use App\Livewire\Admin\Projects\Index as AdminProjects;
use App\Livewire\Admin\Rates\Index as AdminRates;
use App\Livewire\Admin\Tasks\Index as AdminTasks;
use App\Livewire\Admin\Users\Index as AdminUsers;
use App\Livewire\Reports\ClientsReport;
use App\Livewire\Reports\ProjectsReport;
use App\Livewire\Reports\TasksReport;
use App\Livewire\Reports\TeamOverviewReport;
use App\Livewire\Reports\TeamReport;
use App\Livewire\Reports\TimeReport;
use App\Livewire\Timesheet\DayView;
use Illuminate\Support\Facades\Route;

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

    // Report routes (manager + admin)
    Route::middleware('can:access-reports')->prefix('reports')->name('reports.')->group(function () {
        Route::get('/time', TimeReport::class)->name('time');
        Route::get('/clients', ClientsReport::class)->name('clients');
        Route::get('/projects', ProjectsReport::class)->name('projects');
        Route::get('/tasks', TasksReport::class)->name('tasks');
        Route::get('/team', TeamOverviewReport::class)->name('team');
        Route::get('/team/{user}', TeamReport::class)->name('team.member');
    });

    // Admin routes
    Route::middleware('can:access-admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/users', AdminUsers::class)->name('users');
        Route::get('/clients', AdminClients::class)->name('clients');
        Route::get('/tasks', AdminTasks::class)->name('tasks');
        Route::get('/projects', AdminProjects::class)->name('projects');
        Route::get('/projects/create', AdminProjectCreate::class)->name('projects.create');
        Route::get('/projects/{project}/edit', AdminProjectEdit::class)->name('projects.edit');
        Route::get('/rates', AdminRates::class)->name('rates');
    });
});
