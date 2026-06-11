<?php

use App\Http\Controllers\InternalAppController;
use App\Http\Controllers\PurchaseController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [InternalAppController::class, 'login'])->name('internal.login');
Route::post('/login', [InternalAppController::class, 'authenticate'])->name('internal.login.post');
Route::post('/logout', [InternalAppController::class, 'logout'])->name('internal.logout');

Route::get('/', [InternalAppController::class, 'dashboard'])->name('internal.dashboard');
Route::get('/live-map', [InternalAppController::class, 'liveMap'])->name('internal.live-map');
Route::get('/deliveries/pdf', [InternalAppController::class, 'deliveriesPdf'])->name('internal.deliveries.pdf');
Route::get('/work-orders/{arbetsorderNr}', [InternalAppController::class, 'workOrderLookup'])
    ->whereNumber('arbetsorderNr')
    ->name('internal.work-orders.lookup');
Route::get('/work-orders/{arbetsorderNr}/articles', [InternalAppController::class, 'workOrderArticles'])
    ->whereNumber('arbetsorderNr')
    ->name('internal.work-orders.articles');
Route::post('/work-orders/bulk-delete', [InternalAppController::class, 'bulkDeleteWorkOrders'])->name('internal.work-orders.bulk-delete');
Route::patch('/visibility', [InternalAppController::class, 'updateVisibility'])->name('internal.visibility');
Route::post('/visibility/location', [InternalAppController::class, 'updateVisibilityLocation'])->name('internal.visibility.location');
Route::post('/orders', [InternalAppController::class, 'createOrder'])->name('internal.orders.create');
Route::put('/orders/{id}', [InternalAppController::class, 'updateOrder'])->name('internal.orders.update');
Route::post('/orders/{id}/location', [InternalAppController::class, 'updateOrderLocation'])->name('internal.orders.location');
Route::patch('/orders/{id}/status', [InternalAppController::class, 'updateOrderStatus'])->name('internal.orders.status');
Route::delete('/orders/{id}', [InternalAppController::class, 'deleteOrder'])->name('internal.orders.delete');
Route::patch('/order-items/{item}/deliver', [InternalAppController::class, 'deliverOrderItem'])->name('internal.order-items.deliver');

Route::get('/purchases', [PurchaseController::class, 'index'])->name('purchases.index');
Route::get('/api/purchase/search', [PurchaseController::class, 'search'])->name('purchases.search');
Route::get('/api/purchase/crawler-health', [PurchaseController::class, 'crawlerHealth'])->name('purchases.crawler-health');
Route::post('/purchases', [PurchaseController::class, 'store'])->name('purchases.store');
Route::put('/purchases/{purchase}', [PurchaseController::class, 'update'])->name('purchases.update');
Route::delete('/purchases/{purchase}', [PurchaseController::class, 'destroy'])->name('purchases.destroy');
Route::patch('/purchases/{purchase}/ordered', [PurchaseController::class, 'markAsOrdered'])->name('purchases.ordered');
Route::patch('/purchases/{purchase}/received', [PurchaseController::class, 'markAsReceived'])->name('purchases.received');
Route::patch('/purchases/{purchase}/status', [PurchaseController::class, 'updateStatus'])->name('purchases.status');

Route::post('/users', [InternalAppController::class, 'createUser'])->name('internal.users.create');
Route::put('/users/{id}', [InternalAppController::class, 'updateUser'])->name('internal.users.update');
Route::patch('/users/{id}/password', [InternalAppController::class, 'resetUserPassword'])->name('internal.users.password');
Route::delete('/users/{id}', [InternalAppController::class, 'deleteUser'])->name('internal.users.delete');
Route::post('/profile', [InternalAppController::class, 'updateProfile'])->name('internal.profile.update');
Route::delete('/profile/image', [InternalAppController::class, 'deleteProfileImage'])->name('internal.profile.image.delete');
Route::post('/messages', [InternalAppController::class, 'sendInternalMessage'])->name('internal.messages.send');
Route::patch('/messages/{message}/read', [InternalAppController::class, 'markInternalMessageRead'])->name('internal.messages.read');
Route::delete('/messages/{message}', [InternalAppController::class, 'deleteInternalMessage'])->name('internal.messages.delete');
Route::patch('/messages/{message}/restore', [InternalAppController::class, 'restoreInternalMessage'])->name('internal.messages.restore');

Route::put('/settings', [InternalAppController::class, 'updateSettings'])->name('internal.settings.update');

Route::post('/push/subscription', [InternalAppController::class, 'storePushSubscription'])->name('internal.push.subscription');
Route::delete('/push/subscription', [InternalAppController::class, 'deletePushSubscription'])->name('internal.push.subscription.delete');
Route::post('/push/test', [InternalAppController::class, 'pushTest'])->name('internal.push.test');

Route::get('/track/{token}', [InternalAppController::class, 'track'])->name('internal.track');
Route::get('/produkter/{folder}/{file}', [InternalAppController::class, 'productImage'])->where('file', '.*')->name('internal.products.image');
Route::redirect('/index.html', '/');
Route::redirect('/login.html', '/login');
