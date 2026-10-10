<?php

use App\Http\Controllers\AddressController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\HomepageController;
use App\Http\Controllers\MediaThumbnailController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\WishlistController;
use Illuminate\Support\Facades\Route;

// Numeric ids only: a non-numeric id is a 404, not a TypeError (500) in the int-typed controllers.
// At most 18 digits: longer numbers overflow PHP's int and used to produce a 500.
Route::pattern('id', '[0-9]{1,18}');
Route::pattern('productId', '[0-9]{1,18}');

// API root (GET /api): a cheap, public "is the API up" answer. No database, no secrets, no version info.
Route::get('/', fn () => response()->json(['success' => true, 'message' => 'API is running']))
    ->name('api.root');

// Public
Route::prefix('auth')->group(function () {
    Route::post('/register',        [AuthController::class, 'register'])->middleware('throttle:api-auth-sensitive');
    Route::post('/login',           [AuthController::class, 'login'])->middleware('throttle:api-login');
    Route::post('/refresh',         [AuthController::class, 'refresh'])->middleware('throttle:api-token-refresh');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:api-auth-sensitive');
    Route::post('/reset-password',  [AuthController::class, 'resetPassword'])->middleware('throttle:api-auth-sensitive');
});

// Catalogue — publicly browsable; optional auth used to determine price visibility
Route::get('/homepage/catalogs/{item}/pdf', [HomepageController::class, 'catalogPdf'])
    ->whereNumber('item')
    ->name('homepage.catalog.pdf');

// WebP variants of product images (public; immutable — the hash changes when the source changes).
Route::get('/media/{id}/{hash}/{size}.webp', [MediaThumbnailController::class, 'show'])
    ->where(['hash' => '[a-f0-9]{20}', 'size' => '[0-9]{2,4}'])
    ->name('media.thumbnail');

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
    Route::patch('/users/me/password',  [UserController::class, 'changePassword'])->middleware('throttle:api-auth-sensitive');
    Route::delete('/users/me',          [UserController::class, 'destroyMe'])->middleware('throttle:api-auth-sensitive');

    // Orders — require approval in addition to authentication
    Route::middleware('require.approved')->group(function () {
        Route::post('/orders',     [OrderController::class, 'store']);
        Route::get('/orders/{id}', [OrderController::class, 'show']);
        Route::get('/orders',      [OrderController::class, 'index']);
    });

    // Cart, wishlist and address writes are rate limited per user (reads are not).
    Route::middleware('throttle:api-user-writes')->group(function () {
        Route::delete('/cart',                       [CartController::class, 'clear']);
        Route::post('/cart/items',                   [CartController::class, 'store']);
        Route::patch('/cart/items/{productId}',      [CartController::class, 'update'])->whereNumber('productId');
        Route::delete('/cart/items/{productId}',     [CartController::class, 'destroy'])->whereNumber('productId');
        Route::post('/cart/merge',                   [CartController::class, 'merge']);
        Route::post('/wishlist',                         [WishlistController::class, 'store']);
        Route::delete('/wishlist/{id}',                  [WishlistController::class, 'destroy']);
        Route::delete('/wishlist/product/{productId}',   [WishlistController::class, 'destroyByProduct']);
        Route::post('/addresses',       [AddressController::class, 'store']);
        Route::patch('/addresses/{id}', [AddressController::class, 'update']);
        Route::delete('/addresses/{id}',[AddressController::class, 'destroy']);
    });

    // Cart (account-level, shared across devices)
    Route::get('/cart',                          [CartController::class, 'index']);

    // Wishlist
    Route::get('/wishlist',                         [WishlistController::class, 'index']);

    // Addresses
    Route::get('/addresses',        [AddressController::class, 'index']);
    Route::get('/addresses/{id}',   [AddressController::class, 'show']);
});