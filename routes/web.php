<?php

use App\Http\Controllers\TerraProofController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/', [TerraProofController::class, 'dashboard'])->name('dashboard');
Route::get('/containers/{code}/passport', [TerraProofController::class, 'passport'])->name('containers.passport');
Route::get('/api/containers/{code}/geojson', [TerraProofController::class, 'downloadGeoJson'])->name('containers.geojson');

Route::withoutMiddleware([ValidateCsrfToken::class])->group(function () {
    Route::post('/api/audit-polygon', [TerraProofController::class, 'auditPolygon'])->name('api.audit-polygon');
    Route::post('/api/weigh-in', [TerraProofController::class, 'weighIn'])->name('api.weigh-in');
    Route::post('/api/sync-offline-queue', [TerraProofController::class, 'syncOfflineQueue'])->name('api.sync-offline');
    Route::post('/api/compile-container', [TerraProofController::class, 'compileContainer'])->name('api.compile-container');
    Route::match(['get', 'post'], '/api/verify-ledger-chain', [TerraProofController::class, 'verifyLedgerChain'])->name('api.verify-ledger');
    Route::post('/api/simulate-tamper', [TerraProofController::class, 'simulateTamper'])->name('api.simulate-tamper');
});

