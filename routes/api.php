<?php

use App\Http\Controllers\DeliveryApiController as Api;
use Illuminate\Support\Facades\Route;

Route::get('/health', [Api::class, 'health']);

Route::prefix('auth')->group(function () {
    Route::post('/login', [Api::class, 'login']);
    Route::post('/refresh', [Api::class, 'refresh']);
    Route::post('/logout', [Api::class, 'logout']);
    Route::post('/change-password', [Api::class, 'changePassword']);
    Route::post('/forgot-password', [Api::class, 'forgotPassword']);
    Route::post('/reset-password', [Api::class, 'resetPassword']);
});

Route::prefix('users')->group(function () {
    Route::get('/me', [Api::class, 'me']);
    Route::get('/', [Api::class, 'users']);
    Route::post('/', [Api::class, 'createUser']);
    Route::put('/{id}', [Api::class, 'updateUser']);
    Route::delete('/{id}', [Api::class, 'deleteUser']);
    Route::put('/{id}/role', [Api::class, 'updateUser']);
});

Route::prefix('admin')->group(function () {
    Route::get('/summary', [Api::class, 'adminSummary']);
    Route::get('/overview', [Api::class, 'adminOverview']);
    Route::get('/roles', [Api::class, 'roles']);
    Route::get('/logs', [Api::class, 'adminLogs']);
    Route::get('/users', [Api::class, 'users']);
    Route::patch('/users/{id}', [Api::class, 'updateUser']);
    Route::patch('/users/{id}/password', [Api::class, 'updateUserPassword']);
    Route::get('/notifications/subscriptions', [Api::class, 'allSubscriptions']);
    Route::post('/notifications/test-user/{id}', [Api::class, 'pushTest']);
    Route::post('/notifications/broadcast-test', [Api::class, 'pushTest']);
});

Route::get('/settings/system', [Api::class, 'settings']);
Route::put('/settings/system', [Api::class, 'updateSettings']);
Route::get('/roles', [Api::class, 'roles']);
Route::get('/logs', [Api::class, 'adminLogs']);

Route::get('/drivers', [Api::class, 'drivers']);
Route::post('/drivers/visibility', [Api::class, 'driverVisibility']);
Route::post('/drivers/location', [Api::class, 'driverLocation']);

Route::prefix('search')->group(function () {
    Route::get('/people', [Api::class, 'searchPeople']);
    Route::get('/drivers', [Api::class, 'searchDrivers']);
});
Route::get('/people/search', [Api::class, 'searchPeople']);

Route::prefix('recipients')->group(function () {
    Route::get('/suggestions', [Api::class, 'recipients']);
    Route::get('/phone', [Api::class, 'recipientPhone']);
});
Route::get('/customers/recipients', [Api::class, 'recipients']);
Route::get('/customers/suggest/{field}', [Api::class, 'suggest'])->whereIn('field', ['names', 'emails', 'phones']);

Route::get('/products', [Api::class, 'products']);
Route::get('/articles/products', [Api::class, 'products']);

Route::prefix('work-orders')->group(function () {
    Route::get('/suggestions', [Api::class, 'workOrderSuggestions']);
    Route::get('/{arbetsorderNr}/articles', [Api::class, 'workOrderArticles'])->whereNumber('arbetsorderNr');
    Route::get('/{arbetsorderNr}', [Api::class, 'internalWorkOrder'])->whereNumber('arbetsorderNr');
});
Route::post('/external/work-orders', [Api::class, 'externalWorkOrders']);

Route::get('/orders', [Api::class, 'orders']);
Route::post('/orders', [Api::class, 'createOrder']);
Route::put('/orders/{id}', [Api::class, 'updateOrder']);
Route::post('/orders/{id}/start', [Api::class, 'startOrder']);
Route::post('/orders/{id}/stop', [Api::class, 'stopOrder']);
Route::patch('/orders/{id}/delivered', [Api::class, 'delivered']);
Route::post('/orders/{id}/location', [Api::class, 'location']);
Route::post('/orders/{id}/resend-tracking-sms', [Api::class, 'resendSms']);
Route::delete('/orders/{id}', [Api::class, 'deleteOrder']);
Route::delete('/orders', [Api::class, 'clearOrders']);

Route::get('/deliveries', [Api::class, 'orders']);
Route::post('/deliveries', [Api::class, 'createOrder']);
Route::patch('/deliveries/{id}', [Api::class, 'updateOrder']);
Route::post('/deliveries/{id}/start', [Api::class, 'startOrder']);
Route::post('/deliveries/{id}/stop', [Api::class, 'stopOrder']);
Route::post('/deliveries/{id}/delivered', [Api::class, 'delivered']);
Route::post('/deliveries/{id}/location', [Api::class, 'location']);
Route::post('/deliveries/{id}/resend-tracking-sms', [Api::class, 'resendSms']);

Route::get('/track/{token}', [Api::class, 'track']);
Route::get('/track/{token}/stream', [Api::class, 'trackStream']);

Route::get('/notifications/config', [Api::class, 'notificationConfig']);
Route::get('/push/config', [Api::class, 'notificationConfig']);
Route::post('/push/subscribe', [Api::class, 'pushSubscribe']);
Route::post('/notifications/subscription', [Api::class, 'pushSubscribe']);
Route::delete('/notifications/subscription', [Api::class, 'pushDelete']);
Route::get('/notifications/subscriptions/me', [Api::class, 'mySubscriptions']);
Route::post('/push/test', [Api::class, 'pushTest']);
Route::post('/notifications/test-me', [Api::class, 'pushTest']);
