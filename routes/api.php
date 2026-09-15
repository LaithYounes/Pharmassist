<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\MedicineController;
use App\Http\Controllers\PharmacistController;
use App\Http\Controllers\PurchaseItemController;
use App\Http\Controllers\SaleItemController;
use App\Http\Controllers\MedicineReturnController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ReportController;
use App\Models\SaleRepresentative;
use App\Http\Controllers\SaleRepresentativeController;

//---------------------------------Medicine----------------------------------
    Route::apiResource('medicines',MedicineController::class);
    Route::get('Search/{name}',[MedicineController::class,'ShowByName']);
    Route::get('GetByCategoryName/{categoryName}',[MedicineController::class,'getMedicinesByCategoryName']);
//--------------------------------End Medicine-------------------------------


//--------------------------------Pharmacist---------------------------------
    Route::post('RegisterPharmasict',[PharmacistController::class,'create']);
    Route::post('Login',[PharmacistController::class,'login']);
    Route::get('getAllPharmacists',[PharmacistController::class,'GetAllPharmacists']);
    Route::get('PharmacistProfile',[PharmacistController::class,'GetPharmacistProfile'])->middleware('auth:sanctum');
    Route::put('UpdatePharmacist/{id}',[PharmacistController::class,'update']);
    Route::get('GetAllContacts',[PharmacistController::class,'GetAllContacts']);
    Route::delete('DeletePharmacist/{id}',[PharmacistController::class,'destroy'])->middleware('auth:sanctum');;

    //-------------------------------End Pharmacist------------------------------


//--------------------------------SELL Medicine------------------------------
    Route::post('SellMedicine',[SaleItemController::class,'Sell'])->middleware('auth:sanctum');
    Route::get('GetPharmacistSales',[PharmacistController::class,'GetPharmacistSales']);
//--------------------------------END SELL-----------------------------------

//--------------------------------Return Medicine------------------------------
    Route::post('ReturnMedicine', [MedicineReturnController::class, 'store']);
//--------------------------------END Return-----------------------------------

//--------------------------------Request Supply-----------------------------
    Route::post('SupplyRequest',[PurchaseItemController::class,'MakeSupplyOrder'])->middleware('auth:sanctum');
    Route::post('ImportPricedSuppOrder', [PurchaseItemController::class, 'importPricedOrder']);
    Route::get('GetPharmacistPurchase',[PharmacistController::class,'GetPharmacistPurchase']);
//--------------------------------End     Supply-----------------------------


//--------------------------------Categories-----------------------------
Route::get('GetAllCategories',action: [CategoryController::class,'index']);
//--------------------------------End Categories-------------------------

//--------------------------------StatIncomes-------------------------
Route::get('/reports/net-sales', [ReportController::class, 'netSales']);
Route::get('/reports/daily/{date?}', [ReportController::class, 'dailyNetSales']);
Route::get('/reports/monthly/{year}/{month}', [ReportController::class, 'monthlyNetSales']);
//--------------------------------End StatIncomes-----------------------

Route::get('SalesRep',[SaleRepresentativeController::class,'index']);


