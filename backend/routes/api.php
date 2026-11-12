<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'me']);
    Route::get('/dashboard', [FinanceController::class, 'dashboard']);
    Route::patch('/transactions/{id}', [TransactionController::class, 'update'])->whereNumber('id');
    Route::delete('/transactions/{id}', [TransactionController::class, 'destroy'])->whereNumber('id');
    Route::post('/transactions/{id}/restore', [TransactionController::class, 'restore'])->whereNumber('id');
    Route::get('/transactions', [FinanceController::class, 'transactions']);
    Route::get('/imports', [ImportController::class, 'index']);
    Route::post('/imports', [ImportController::class, 'store'])->middleware('throttle:imports');
    Route::get('/imports/{id}', [ImportController::class, 'show'])->whereNumber('id');
    Route::post('/imports/{id}/retry', [ImportController::class, 'retry'])->middleware('throttle:imports')->whereNumber('id');
    Route::get('/documentation/{kind}', function (string $kind) {
        $files = ['readme' => 'README.md', 'api' => 'openapi.yaml'];
        abort_unless(isset($files[$kind]), 404);
        $path = resource_path('project-docs/'.$files[$kind]);
        if (! is_file($path)) {
            $path = base_path('../'.($kind === 'readme' ? 'README.md' : 'docs/openapi.yaml'));
        }
        abort_unless(is_file($path), 404);

        return response()->download($path, $files[$kind]);
    });
    Route::get('/sample', [ImportController::class, 'sample']);
});
