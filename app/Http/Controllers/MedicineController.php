<?php

namespace App\Http\Controllers;

use App\Http\Requests\MedicinesRequests\StoreMedicineRequest;
use App\Http\Requests\MedicinesRequests\UpdateMedicineRequest;
use App\Http\Resources\GetAllMedicines;
use App\Repositories\Interfaces\MedicineRepositoryInterface;
use App\Models\Manufacturer;
use App\Models\Category;
use Illuminate\Support\Facades\Gate;

class MedicineController extends Controller
{
    protected $medicineRepository;

    public function __construct(MedicineRepositoryInterface $medicineRepository)
    {
        $this->medicineRepository = $medicineRepository;
    }


//------------------------CRUD--------------------------
    public function index()
    {
        $medicines= $this->medicineRepository->all();
        return  GetAllMedicines::collection($medicines);
    }

    public function store(StoreMedicineRequest $request)
{
    Gate::authorize('manage-pharmacy');

    $manufacturer = Manufacturer::where('company_name', $request->manufacturer)->first();

    if (!$manufacturer) {
        return response()->json(['message' => 'Manufacturer not found'], 404);
    }


    $data = $request->validated();
    $data['manufacturer_id'] = $manufacturer->id;
    unset($data['manufacturer'], $data['categories']); // remove non-DB fields


    $medicine = $this->medicineRepository->create($data);


    $categoryIds = Category::whereIn('category_name', $request->categories)->pluck('id');
    $medicine->categories()->attach($categoryIds);

    return response()->json([
        'message' => 'Medicine added successfully',
        'medicine' => $medicine->load('categories') 
    ]);
}


    public function show(int $id)
    {
        return $medicine = $this->medicineRepository->find($id);
    }

    public function update(UpdateMedicineRequest $request, $id)
    {
        Gate::authorize('manage-pharmacy');
        $updatedMedicine = $this->medicineRepository->update($id, $request->validated());

        return response()->json([
            'message' => 'Medicine updated successfully.',
            'data' => $updatedMedicine
        ], 200);
    }

    public function destroy(int $id)
    {
        Gate::authorize('manage-pharmacy');
        $this->medicineRepository->delete($id);
        return response()->json(['message'=>'Medicine deleted successfully'],200);
    }
//-------------------------END CRUD--------------------------

    public function showByName(string $name)
    {
        $medicine = $this->medicineRepository->findByName($name);

       if ($medicine->isEmpty()) {
        return response()->json(['message' => 'Medicine Not found'], 404);
    }


        return  GetAllMedicines::collection($medicine);
    }


    public function getMedicinesByCategoryName($categoryName)
    {
        if($categoryName=='All'){
            return $this->index();
        }
        $medicines = $this->medicineRepository->getMedicinesByCategoryName($categoryName);

        if ($medicines->isEmpty()) {
            return response()->json(['message' => 'No medicines found or category does not exist.'], 404);
        }

        return GetAllMedicines::collection($medicines);
    }





}
