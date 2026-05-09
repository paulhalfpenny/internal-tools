<?php

use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\ProjectsController;
use App\Http\Controllers\Api\TimeEntriesController;
use App\Http\Controllers\Api\TimersController;
use App\Http\Middleware\AuthenticatePersonalAccessToken;
use Illuminate\Support\Facades\Route;

Route::prefix('api')
    ->middleware(AuthenticatePersonalAccessToken::class)
    ->group(function () {
        Route::get('/me', [MeController::class, 'show']);
        Route::get('/projects', [ProjectsController::class, 'index']);
        Route::post('/time-entries', [TimeEntriesController::class, 'store']);
        Route::post('/timers/start', [TimersController::class, 'start']);
        Route::post('/timers/stop', [TimersController::class, 'stop']);
        Route::get('/timers/running', [TimersController::class, 'running']);
    });
