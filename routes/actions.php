<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use JustinHoltWeb\Caffeine\Http\Controllers\FragmentController;
use JustinHoltWeb\Caffeine\Http\Controllers\SearchController;

// The Caffeine 5 action paths: `actions/caffeine/fragment` and `actions/caffeine/search`.
// Public — they serve the site's front end.
Route::get('fragment', FragmentController::class)->name('caffeine.actions.fragment');
Route::match(['get', 'post'], 'search/{handle?}', SearchController::class)->name('caffeine.actions.search');
