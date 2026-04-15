<?php

use Illuminate\Support\Facades\Route;
use Storvia\Vantage\Http\Controllers\VantageApiController;
use Storvia\Vantage\Http\Middleware\AuthorizeVantageApi;

$prefix = trim(config('vantage.api.prefix', 'api/vantage'), '/');
$prefix = $prefix === '' ? 'api/vantage' : $prefix;

Route::prefix($prefix)->name('vantage.api.')->middleware(AuthorizeVantageApi::class)->group(function () {
    Route::get('/', [VantageApiController::class, 'index'])->name('index');
    Route::get('/stats', [VantageApiController::class, 'stats'])->name('stats');
    Route::get('/jobs', [VantageApiController::class, 'jobs'])->name('jobs');
    Route::get('/jobs/{id}', [VantageApiController::class, 'show'])->name('jobs.show');
    Route::get('/tags', [VantageApiController::class, 'tags'])->name('tags');
    Route::get('/failed', [VantageApiController::class, 'failed'])->name('failed');
    Route::post('/jobs/{id}/retry', [VantageApiController::class, 'retry'])->name('jobs.retry');
    Route::get('/queue-depths', [VantageApiController::class, 'queueDepths'])->name('queue-depths');
    Route::get('/batches', [VantageApiController::class, 'batches'])->name('batches');
});
