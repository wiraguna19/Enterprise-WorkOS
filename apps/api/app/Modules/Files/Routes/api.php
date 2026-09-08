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
// The READ path, which did not exist. `attach` has written attachment rows
// since Phase 2 and nothing has ever listed them: a write with no reader rots
// unnoticed, exactly as the activity log did (docs/11 §7). Behind
// `work_item.view` and not `file.upload`, because seeing what is attached to
// work you can see is not an uploading right.
Route::get('work-items/{reference}/attachments', [FileController::class, 'index'])
    ->middleware('permission:work_item.view');
Route::post('work-items/{reference}/attachments', [FileController::class, 'attach'])
    ->middleware(['permission:file.upload', 'throttle:writes']);
