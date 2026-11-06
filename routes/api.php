<?php

use App\Http\Controllers\Api\V1;
use Illuminate\Support\Facades\Route;

/*
| All routes are prefixed with /api/v1 (bootstrap/app.php).
| Every authenticated route declares the permission it needs; tests/Feature/Security/RouteAuthorizationTest
| fails if a route is added without one.
*/

Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('login', [V1\AuthController::class, 'login'])->middleware('throttle:login')->name('login');
    Route::post('tokens', [V1\AuthController::class, 'issueToken'])->middleware('throttle:login')->name('tokens.store');
});

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::get('me', [V1\AuthController::class, 'me'])->name('me');
        Route::post('logout', [V1\AuthController::class, 'logout'])->name('logout');
        Route::delete('tokens/current', [V1\AuthController::class, 'revokeToken'])->name('tokens.destroy');
    });

    // --- Requisitions & document processing --------------------------------------------------
    Route::controller(V1\RequisitionController::class)->prefix('requisitions')->name('requisitions.')->group(function () {
        Route::get('/', 'index')->middleware('permission:requisitions.view')->name('index');
        Route::post('/', 'store')->middleware('permission:requisitions.upload')->name('store');
        Route::post('uploads', 'upload')->middleware(['permission:requisitions.upload', 'throttle:uploads'])->name('upload');
        Route::get('{requisition}', 'show')->middleware('permission:requisitions.view')->name('show');
        Route::put('{requisition}', 'update')->middleware('permission:requisitions.update')->name('update');
        Route::post('{requisition}/process', 'process')->middleware('permission:requisitions.update')->name('process');
        Route::post('{requisition}/verify', 'verify')->middleware('permission:requisitions.verify')->name('verify');
        Route::post('{requisition}/reopen', 'reopen')->middleware('permission:requisitions.verify')->name('reopen');
        Route::post('{requisition}/approve', 'approve')->middleware('permission:requisitions.approve')->name('approve');
        Route::post('{requisition}/cancel', 'cancel')->middleware('permission:requisitions.cancel')->name('cancel');
        Route::get('{requisition}/document', 'document')->middleware('permission:requisitions.view')->name('document');
    });
    Route::get('requisition-items/{requisitionItem}/match-suggestions', [V1\RequisitionController::class, 'matchSuggestions'])
        ->middleware('permission:requisitions.update')->name('requisition-items.match-suggestions');

    // --- Analyzer -----------------------------------------------------------------------------
    Route::controller(V1\AnalyzerController::class)->prefix('analyzer')->name('analyzer.')->middleware('permission:analyzer.view')->group(function () {
        Route::get('summary', 'summary')->name('summary');
        Route::get('items', 'items')->name('items');
        Route::get('items/{item}/requisitions', 'sources')->name('items.requisitions');
        Route::get('status-summary', 'statusSummary')->name('status-summary');
    });

    // --- Purchase orders ------------------------------------------------------------------------
    Route::controller(V1\PurchaseOrderController::class)->prefix('purchase-orders')->name('purchase-orders.')->group(function () {
        Route::get('/', 'index')->middleware('permission:purchases.view')->name('index');
        Route::get('available-items', 'availableItems')->middleware('permission:purchases.create')->name('available-items');
        Route::get('available-items/{item}', 'availableLines')->middleware('permission:purchases.create')->name('available-lines');
        Route::post('/', 'store')->middleware(['permission:purchases.create', 'idempotent'])->name('store');
        Route::get('{purchaseOrder}', 'show')->middleware('permission:purchases.view')->name('show');
        Route::put('{purchaseOrder}', 'update')->middleware('permission:purchases.update')->name('update');
        Route::post('{purchaseOrder}/submit', 'submit')->middleware('permission:purchases.update')->name('submit');
        Route::post('{purchaseOrder}/approve', 'approve')->middleware('permission:purchases.approve')->name('approve');
        Route::post('{purchaseOrder}/mark-purchased', 'markPurchased')->middleware(['permission:purchases.update', 'idempotent'])->name('mark-purchased');
        Route::post('{purchaseOrder}/complete', 'complete')->middleware('permission:purchases.update')->name('complete');
        Route::post('{purchaseOrder}/cancel', 'cancel')->middleware('permission:purchases.cancel')->name('cancel');
    });

    // --- Deliveries -------------------------------------------------------------------------------
    Route::controller(V1\DeliveryController::class)->prefix('deliveries')->name('deliveries.')->group(function () {
        Route::get('/', 'index')->middleware('permission:deliveries.view')->name('index');
        Route::post('/', 'store')->middleware(['permission:deliveries.create', 'idempotent'])->name('store');
        Route::get('{delivery}', 'show')->middleware('permission:deliveries.view')->name('show');
        Route::post('{delivery}/confirm', 'confirm')->middleware(['permission:deliveries.confirm', 'idempotent'])->name('confirm');
        Route::post('{delivery}/reverse', 'reverse')->middleware(['permission:deliveries.reverse', 'idempotent'])->name('reverse');
    });
    Route::get('purchase-orders/{purchaseOrder}/deliverable', [V1\DeliveryController::class, 'deliverable'])
        ->middleware('permission:deliveries.create')->name('purchase-orders.deliverable');

    // --- Payments -----------------------------------------------------------------------------------
    Route::controller(V1\PaymentController::class)->prefix('payments')->name('payments.')->group(function () {
        Route::get('/', 'index')->middleware('permission:payments.view')->name('index');
        Route::post('/', 'store')->middleware(['permission:payments.create', 'idempotent'])->name('store');
        Route::get('{payment}', 'show')->middleware('permission:payments.view')->name('show');
        Route::post('{payment}/allocations', 'allocate')->middleware(['permission:payments.allocate', 'idempotent'])->name('allocate');
        Route::post('{payment}/reverse', 'reverse')->middleware(['permission:payments.reverse', 'idempotent'])->name('reverse');
    });

    // --- Dashboard & reports ---------------------------------------------------------------------
    Route::controller(V1\DashboardController::class)->prefix('dashboard')->name('dashboard.')->middleware('permission:dashboard.view')->group(function () {
        Route::get('summary', 'summary')->name('summary');
        Route::get('charts', 'charts')->name('charts');
        Route::get('recent-activities', 'recentActivities')->name('recent-activities');
    });
    Route::controller(V1\ReportController::class)->group(function () {
        Route::get('reports', 'index')->middleware('permission:reports.view')->name('reports.index');
        // Financial reports additionally require reports.financial (checked per report).
        Route::get('reports/{report}', 'show')->middleware('permission:reports.view')->name('reports.show');
        Route::post('reports/{report}/exports', 'export')->middleware(['permission:reports.export', 'throttle:exports'])->name('reports.export');
        Route::get('exports/{export}', 'exportStatus')->name('exports.show');
        Route::get('exports/{export}/download', 'download')->name('exports.download');
    });

    // --- Catalog & master data -------------------------------------------------------------
    $lookupView = 'permission:items.view|requisitions.view|analyzer.view|purchases.view|deliveries.view|reports.view';
    foreach (['departments', 'units', 'item-categories'] as $lookup) {
        Route::prefix($lookup)->name("{$lookup}.")->controller(V1\LookupController::class)->group(function () use ($lookup, $lookupView) {
            Route::get('/', 'index')->middleware($lookupView)->defaults('lookup', $lookup)->name('index');
            Route::post('/', 'store')->middleware('permission:master_data.manage')->defaults('lookup', $lookup)->name('store');
            Route::put('{id}', 'update')->middleware('permission:master_data.manage')->defaults('lookup', $lookup)->whereNumber('id')->name('update');
        });
    }

    Route::controller(V1\UnitConversionController::class)->prefix('unit-conversions')->name('unit-conversions.')->group(function () {
        Route::get('/', 'index')->middleware('permission:items.view')->name('index');
        Route::post('/', 'store')->middleware('permission:master_data.manage')->name('store');
        Route::put('{unitConversion}', 'update')->middleware('permission:master_data.manage')->name('update');
        Route::delete('{unitConversion}', 'destroy')->middleware('permission:master_data.manage')->name('destroy');
    });

    Route::controller(V1\ItemController::class)->prefix('items')->name('items.')->group(function () {
        Route::get('/', 'index')->middleware('permission:items.view')->name('index');
        Route::post('/', 'store')->middleware('permission:items.manage')->name('store');
        Route::get('{item}', 'show')->middleware('permission:items.view')->name('show');
        Route::get('{item}/purchase-history', 'purchaseHistory')->middleware('permission:purchases.view')->name('purchase-history');
        Route::put('{item}', 'update')->middleware('permission:items.manage')->name('update');
        Route::post('{item}/aliases', 'addAlias')->middleware('permission:items.manage')->name('aliases.store');
        Route::delete('{item}/aliases/{alias}', 'removeAlias')->middleware('permission:items.manage')->name('aliases.destroy');
    });

    Route::controller(V1\VendorController::class)->prefix('vendors')->name('vendors.')->group(function () {
        Route::get('/', 'index')->middleware('permission:vendors.view|purchases.view|payments.view')->name('index');
        Route::post('/', 'store')->middleware('permission:vendors.manage')->name('store');
        Route::get('{vendor}', 'show')->middleware('permission:vendors.view')->name('show');
        Route::put('{vendor}', 'update')->middleware('permission:vendors.manage')->name('update');
        Route::get('{vendor}/balance', 'balance')->middleware('permission:finance.view')->name('balance');
        Route::get('{vendor}/payables', 'payables')->middleware('permission:payments.allocate')->name('payables');
        Route::get('{vendor}/items', 'items')->middleware('permission:vendors.view')->name('items');
        Route::get('{vendor}/activities', 'activities')->middleware('permission:vendors.view')->name('activities');
    });

    // --- Administration ------------------------------------------------------------------------
    Route::controller(V1\UserController::class)->prefix('users')->name('users.')->middleware('permission:users.manage')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('{user}', 'show')->name('show');
        Route::put('{user}', 'update')->name('update');
    });
    Route::get('roles', [V1\RoleController::class, 'index'])->middleware('permission:users.manage')->name('roles.index');
    Route::get('permissions', [V1\RoleController::class, 'permissions'])->middleware('permission:users.manage')->name('permissions.index');
    Route::post('roles', [V1\RoleController::class, 'store'])->middleware('permission:roles.manage')->name('roles.store');
    Route::put('roles/{role}', [V1\RoleController::class, 'update'])->middleware('permission:roles.manage')->name('roles.update');
    Route::delete('roles/{role}', [V1\RoleController::class, 'destroy'])->middleware('permission:roles.manage')->name('roles.destroy');
    Route::get('activity-logs', [V1\ActivityLogController::class, 'index'])->middleware('permission:activity_logs.view')->name('activity-logs.index');

    // --- System ------------------------------------------------------------------------------
    Route::get('settings', [V1\SettingsController::class, 'show'])->name('settings.show');
    Route::put('settings', [V1\SettingsController::class, 'update'])->middleware('permission:settings.manage')->name('settings.update');
});
