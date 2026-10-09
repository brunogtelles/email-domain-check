<?php

use App\Http\Controllers\CheckBatchController;
use App\Http\Controllers\SingleCheckController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/checks/{type}', SingleCheckController::class)
    ->middleware('throttle:single-check')
    ->name('checks.single');

Route::post('/batches', [CheckBatchController::class, 'store'])
    ->middleware('throttle:bulk-upload')
    ->name('batches.store');

Route::get('/batches/{batch}', [CheckBatchController::class, 'show'])
    ->middleware('throttle:batch-poll')
    ->name('batches.show');

Route::post('/batches/{batch}/cancel', [CheckBatchController::class, 'cancel'])
    ->middleware('throttle:bulk-upload')
    ->name('batches.cancel');
