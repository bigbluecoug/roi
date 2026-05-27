<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CaptureController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\SetupController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', function (Request $request) {
        return $request->session()->has('current_event_id')
            ? redirect()->route('captures.create')
            : redirect()->route('setup.state');
    });

    Route::get('/setup/state', [SetupController::class, 'state'])->name('setup.state');
    Route::post('/setup/state', [SetupController::class, 'storeState'])->name('setup.state.store');
    Route::get('/setup/events', [EventController::class, 'index'])->name('setup.events');
    Route::post('/setup/events/select', [SetupController::class, 'selectEvent'])->name('setup.events.select');

    Route::get('/events', [EventController::class, 'index'])->name('events.index');
    Route::get('/events/{event}', [EventController::class, 'show'])->name('events.show');
    Route::post('/events', [EventController::class, 'store'])->name('events.store');

    Route::get('/capture', [CaptureController::class, 'create'])->name('captures.create');
    Route::post('/captures', [CaptureController::class, 'store'])->name('captures.store');
    Route::get('/captures', [CaptureController::class, 'index'])->name('captures.index');
    Route::get('/captures/{capture}', [CaptureController::class, 'show'])->name('captures.show');
    Route::get('/captures/{capture}/review', [CaptureController::class, 'review'])->name('captures.review');
    Route::get('/captures/{capture}/image', [CaptureController::class, 'image'])->name('captures.image');
    Route::patch('/captures/{capture}', [CaptureController::class, 'update'])->name('captures.update');
    Route::post('/captures/{capture}/reprocess', [CaptureController::class, 'reprocess'])->name('captures.reprocess');
    Route::match(['post', 'patch'], '/captures/{capture}/web-enrich', [CaptureController::class, 'webEnrich'])->name('captures.web-enrich');
    Route::post('/captures/{capture}/hubspot-sync', [CaptureController::class, 'sync'])->name('captures.sync');
    Route::delete('/captures/{capture}', [CaptureController::class, 'destroy'])->name('captures.destroy');
    Route::delete('/captures/{capture}/image', [CaptureController::class, 'destroyImage'])->name('captures.image.destroy');
});
