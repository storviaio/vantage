<?php

use Storvia\Vantage\Http\Controllers\QueueMonitorController;
use Storvia\Vantage\Http\Middleware\AuthorizeVantage;
use Illuminate\Support\Facades\Route;

$prefix = trim(config('vantage.route_prefix', 'vantage'), '/');
$prefix = $prefix === '' ? 'vantage' : $prefix;

Route::prefix($prefix)->name('vantage.')->middleware(['web', AuthorizeVantage::class])->group(function () {
    Route::get('/', [QueueMonitorController::class, 'index'])->name('dashboard');
    Route::get('/jobs', [QueueMonitorController::class, 'jobs'])->name('jobs');
    Route::get('/jobs/{id}', [QueueMonitorController::class, 'show'])->name('jobs.show');
    Route::get('/tags', [QueueMonitorController::class, 'tags'])->name('tags');
    Route::get('/failed', [QueueMonitorController::class, 'failed'])->name('failed');
    Route::post('/jobs/{id}/retry', [QueueMonitorController::class, 'retry'])->name('jobs.retry');
});
