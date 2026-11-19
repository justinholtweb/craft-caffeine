<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use JustinHoltWeb\Caffeine\Http\Controllers\FragmentController;
use JustinHoltWeb\Caffeine\Http\Controllers\SearchController;

// The tidy URLs that show up in a network panel and in a CDN's logs.
Route::get('caffeine/fragment', FragmentController::class)->name('caffeine.fragment');
Route::match(['get', 'post'], 'caffeine/search/{handle}', SearchController::class)->name('caffeine.search');
