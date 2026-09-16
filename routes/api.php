<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\MedicineController;
use App\Http\Controllers\PharmacistController;
use App\Http\Controllers\PurchaseItemController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\SaleItemController;
use App\Http\Controllers\MedicineReturnController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\SaleRepresentativeController;
use Illuminate\Support\Facades\Route;

Route::post('Login', [PharmacistController::class, 'login']);
Route::apiResource('medicines', MedicineController::class)->only(['index', 'show']);
Route::get('Search/{name}', [MedicineController::class, 'ShowByName']);
Route::get('GetByCategoryName/{categoryName}', [MedicineController::class, 'getMedicinesByCategoryName']);
Route::get('GetAllCategories', [CategoryController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('medicines', MedicineController::class)
        ->only(['store', 'update', 'destroy'])->middleware('can:manage-pharmacy');

    Route::post('RegisterPharmasict', [PharmacistController::class, 'create'])->middleware('can:manage-pharmacy');
    Route::put('UpdatePharmacist/{id}', [PharmacistController::class, 'update'])->middleware('can:manage-pharmacy');
    Route::delete('DeletePharmacist/{id}', [PharmacistController::class, 'destroy'])->middleware('can:manage-pharmacy');
    Route::get('getAllPharmacists', [PharmacistController::class, 'GetAllPharmacists'])->middleware('can:manage-pharmacy');
    Route::get('GetAllContacts', [PharmacistController::class, 'GetAllContacts'])->middleware('can:pharmacy-work');
    Route::get('PharmacistProfile', [PharmacistController::class, 'GetPharmacistProfile'])->middleware('can:pharmacy-work');

    Route::post('SellMedicine', [SaleItemController::class, 'Sell'])->middleware('can:pharmacy-work');
    Route::get('sales/{saleId}', [SaleItemController::class, 'showSale'])->middleware('can:pharmacy-work');
    Route::get('GetPharmacistSales', [PharmacistController::class, 'GetPharmacistSales'])->middleware('can:pharmacy-work');
    Route::post('ReturnMedicine', [MedicineReturnController::class, 'store'])->middleware('can:pharmacy-work');
    Route::get('sales/{saleId}/returns', [MedicineReturnController::class, 'showBySale'])->middleware('can:pharmacy-work');

    Route::post('SupplyRequest', [PurchaseItemController::class, 'MakeSupplyOrder'])->middleware('can:pharmacy-work');
    Route::post('ImportPricedSuppOrder', [PurchaseItemController::class, 'importPricedOrder'])->middleware('can:manage-pharmacy');
    Route::post('purchases/{purchase}/price', [PurchaseItemController::class, 'importPricedOrder'])->middleware('can:manage-pharmacy');
    Route::post('purchases/{purchase}/receive', [PurchaseController::class, 'receive'])->middleware('can:pharmacy-work');
    Route::get('purchases/{purchase}/lifecycle', [PurchaseController::class, 'lifecycle'])->middleware('can:pharmacy-work');
    Route::post('purchases/{purchase}/approve', [PurchaseController::class, 'approve'])->middleware('can:manage-pharmacy');
    Route::post('purchases/{purchase}/reject', [PurchaseController::class, 'reject'])->middleware('can:manage-pharmacy');
    Route::post('purchases/{purchase}/cancel', [PurchaseController::class, 'cancel'])->middleware('can:pharmacy-work');
    Route::get('GetPharmacistPurchase', [PharmacistController::class, 'GetPharmacistPurchase'])->middleware('can:pharmacy-work');
    Route::get('SalesRep', [SaleRepresentativeController::class, 'index'])->middleware('can:pharmacy-work');

    Route::middleware('can:manage-pharmacy')->group(function () {
        Route::get('batches/{batch}/movements', [StockMovementController::class, 'index']);
        Route::post('batches/{batch}/adjustments', [StockMovementController::class, 'adjust']);
        Route::post('batches/{batch}/damage', [StockMovementController::class, 'damage']);
        Route::get('reports/net-sales', [ReportController::class, 'netSales']);
        Route::get('reports/daily/{date?}', [ReportController::class, 'dailyNetSales']);
        Route::get('reports/monthly/{year}/{month}', [ReportController::class, 'monthlyNetSales']);
    });
});
