<?php

use App\Http\Controllers\AddressController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\HomepageController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\WishlistController;
use Illuminate\Support\Facades\Route;

// Public
Route::prefix('auth')->group(function () {
    Route::post('/register',        [AuthController::class, 'register']);
    Route::post('/login',           [AuthController::class, 'login']);
    Route::post('/refresh',         [AuthController::class, 'refresh']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password',  [AuthController::class, 'resetPassword']);
});

// Catalogue — publicly browsable; optional auth used to determine price visibility
Route::get('/homepage/catalogs/{item}/pdf', [HomepageController::class, 'catalogPdf'])
    ->whereNumber('item')
    ->name('homepage.catalog.pdf');

Route::middleware('auth.optional')->group(function () {
    Route::get('/homepage',        [HomepageController::class, 'show']);
    Route::get('/products',        [ProductController::class, 'index']);
    Route::get('/products/{id}',   [ProductController::class, 'show']);
    Route::get('/categories',      [CategoryController::class, 'index']);
    Route::get('/categories/{id}', [CategoryController::class, 'show']);
    Route::get('/brands',          [BrandController::class, 'index']);
    Route::get('/brands/{id}',     [BrandController::class, 'show']);
});

// Authenticated routes
Route::middleware('auth:api')->group(function () {

    // Auth
    Route::post('/auth/validate', [AuthController::class, 'validate']);
    Route::post('/auth/logout',   [AuthController::class, 'logout']);

    // User
    Route::get('/users/me',             [UserController::class, 'me']);
    Route::patch('/users/{id}',         [UserController::class, 'update']);
    Route::patch('/users/me/password',  [UserController::class, 'changePassword']);
    Route::delete('/users/me',          [UserController::class, 'destroyMe']);

    // Orders — require approval in addition to authentication
    Route::middleware('require.approved')->group(function () {
        Route::post('/orders',     [OrderController::class, 'store']);
        Route::get('/orders/{id}', [OrderController::class, 'show']);
        Route::get('/orders',      [OrderController::class, 'index']);
    });

    // Wishlist
    Route::get('/wishlist',                          [WishlistController::class, 'index']);
    Route::post('/wishlist',                         [WishlistController::class, 'store']);
    Route::delete('/wishlist/{id}',                  [WishlistController::class, 'destroy']);
    Route::delete('/wishlist/product/{productId}',   [WishlistController::class, 'destroyByProduct']);

    // Addresses
    Route::get('/addresses',        [AddressController::class, 'index']);
    Route::post('/addresses',       [AddressController::class, 'store']);
    Route::get('/addresses/{id}',   [AddressController::class, 'show']);
    Route::patch('/addresses/{id}', [AddressController::class, 'update']);
    Route::delete('/addresses/{id}',[AddressController::class, 'destroy']);
});
