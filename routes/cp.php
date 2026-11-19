<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use JustinHoltWeb\Caffeine\Http\Controllers\IndexesController;
use JustinHoltWeb\Caffeine\Plugin;

use function CraftCms\Cms\cp_url;

// The CP group already applies `auth`, `can:accessCp` and the cpTrigger prefix.

// Route::redirect() would drop the cpTrigger prefix, so redirect through cp_url().
Route::get('caffeine', fn () => redirect(cp_url('caffeine/indexes')))->name('caffeine');

Route::middleware('can:'.Plugin::PERMISSION_MANAGE_INDEXES)->group(function () {
    Route::get('caffeine/indexes', [IndexesController::class, 'index'])->name('caffeine.indexes');
    Route::get('caffeine/indexes/new', [IndexesController::class, 'create'])->name('caffeine.indexes.create');
    Route::post('caffeine/indexes', [IndexesController::class, 'save'])->name('caffeine.indexes.save');
    Route::get('caffeine/indexes/{handle}', [IndexesController::class, 'edit'])->name('caffeine.indexes.edit');
    Route::delete('caffeine/indexes/{handle}', [IndexesController::class, 'destroy'])->name('caffeine.indexes.destroy');
    Route::get('caffeine/indexes/{handle}/tools', [IndexesController::class, 'tools'])->name('caffeine.indexes.tools');
    Route::post('caffeine/indexes/{handle}/preview', [IndexesController::class, 'preview'])->name('caffeine.indexes.preview');
    Route::post('caffeine/indexes/{handle}/query', [IndexesController::class, 'query'])->name('caffeine.indexes.query');
});

Route::middleware('can:'.Plugin::PERMISSION_BUILD)->group(function () {
    Route::post('caffeine/indexes/{handle}/build', [IndexesController::class, 'build'])->name('caffeine.indexes.build');
    Route::post('caffeine/indexes/{handle}/publish', [IndexesController::class, 'publish'])->name('caffeine.indexes.publish');
});
