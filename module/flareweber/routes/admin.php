<?php

use FlareWeber\Http\Controllers\Admin\ChangesController;
use FlareWeber\Http\Controllers\Admin\DashboardController;
use FlareWeber\Http\Controllers\Admin\MediaController;
use FlareWeber\Http\Controllers\Admin\OrdersController;
use FlareWeber\Http\Controllers\Admin\PagesController;
use FlareWeber\Http\Controllers\Admin\ProductsController;
use Illuminate\Support\Facades\Route;

// JSON API for the FlareWeber admin SPA. Mounted at /flareweber/api by the
// service provider (web middleware + admin gate). Every response is JSON.

Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
Route::get('changes', [ChangesController::class, 'index'])->name('changes');

Route::get('pages', [PagesController::class, 'pages'])->name('pages.index');
Route::post('pages', [PagesController::class, 'store'])->name('pages.store');
Route::patch('pages/{id}', [PagesController::class, 'update'])->whereNumber('id')->name('pages.update');
Route::delete('pages/{id}', [PagesController::class, 'destroy'])->whereNumber('id')->name('pages.destroy');

Route::get('posts', [PagesController::class, 'posts'])->name('posts.index');

Route::get('products', [ProductsController::class, 'index'])->name('products.index');
Route::post('products', [ProductsController::class, 'store'])->name('products.store');
Route::patch('products/{id}', [ProductsController::class, 'update'])->whereNumber('id')->name('products.update');

Route::get('media', [MediaController::class, 'index'])->name('media.index');
Route::post('media', [MediaController::class, 'store'])->name('media.store');
Route::delete('media/{id}', [MediaController::class, 'destroy'])->name('media.destroy');

Route::get('sites/{site}/orders', [OrdersController::class, 'index'])->name('sites.orders.index');
Route::patch('sites/{site}/orders/{id}', [OrdersController::class, 'update'])->whereNumber('id')->name('sites.orders.update');
