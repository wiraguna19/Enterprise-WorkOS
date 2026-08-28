<?php

declare(strict_types=1);

use App\Modules\Files\Http\Controller\FileController;
use Illuminate\Support\Facades\Route;

/**
 * Direct-to-storage upload (docs/05 §6). The API issues signed URLs and records
 * metadata; the bytes never pass through a PHP worker.
 */
Route::post('files/upload-url', [FileController::class, 'reserve'])
    ->middleware(['permission:file.upload', 'throttle:writes']);
Route::post('files/{file}/complete', [FileController::class, 'complete'])
    ->middleware('permission:file.upload');
Route::get('files/{file}/download', [FileController::class, 'download'])
    ->middleware('permission:work_item.view');
Route::post('work-items/{reference}/attachments', [FileController::class, 'attach'])
    ->middleware(['permission:file.upload', 'throttle:writes']);
