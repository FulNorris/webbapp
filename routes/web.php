<?php

use App\Http\Controllers\InternalAppController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [InternalAppController::class, 'login'])->name('internal.login');
Route::post('/login', [InternalAppController::class, 'authenticate'])->name('internal.login.post');
Route::post('/logout', [InternalAppController::class, 'logout'])->name('internal.logout');

Route::get('/', [InternalAppController::class, 'dashboard'])->name('internal.dashboard');
Route::get('/live-map', [InternalAppController::class, 'liveMap'])->name('internal.live-map');
Route::patch('/visibility', [InternalAppController::class, 'updateVisibility'])->name('internal.visibility');
Route::post('/orders', [InternalAppController::class, 'createOrder'])->name('internal.orders.create');
Route::put('/orders/{id}', [InternalAppController::class, 'updateOrder'])->name('internal.orders.update');
Route::post('/orders/{id}/location', [InternalAppController::class, 'updateOrderLocation'])->name('internal.orders.location');
Route::patch('/orders/{id}/status', [InternalAppController::class, 'updateOrderStatus'])->name('internal.orders.status');
Route::delete('/orders/{id}', [InternalAppController::class, 'deleteOrder'])->name('internal.orders.delete');

Route::post('/users', [InternalAppController::class, 'createUser'])->name('internal.users.create');
Route::put('/users/{id}', [InternalAppController::class, 'updateUser'])->name('internal.users.update');
Route::patch('/users/{id}/password', [InternalAppController::class, 'resetUserPassword'])->name('internal.users.password');
Route::delete('/users/{id}', [InternalAppController::class, 'deleteUser'])->name('internal.users.delete');

Route::put('/settings', [InternalAppController::class, 'updateSettings'])->name('internal.settings.update');

Route::post('/push/subscription', [InternalAppController::class, 'storePushSubscription'])->name('internal.push.subscription');
Route::delete('/push/subscription', [InternalAppController::class, 'deletePushSubscription'])->name('internal.push.subscription.delete');
Route::post('/push/test', [InternalAppController::class, 'pushTest'])->name('internal.push.test');

Route::get('/track/{token}', [InternalAppController::class, 'track'])->name('internal.track');
Route::redirect('/index.html', '/');
Route::redirect('/login.html', '/login');
