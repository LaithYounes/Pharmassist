<?php

namespace App\Http\Controllers;

use App\Http\Requests\PharmacistRequests\RegisterPharmacistRequest;
use App\Http\Requests\PharmacistRequests\UpdatePharmacistRequest;
use App\Http\Resources\GetPharmacistProfile;
use App\Http\Resources\GetPharmacistPurchase;
use App\Http\Resources\GetPharmacistSales;
use App\Models\Pharmacist;
use App\Models\SaleItem;
use App\Repositories\Interfaces\PharmacistRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PharmacistController extends Controller
{


    protected $pharmacistRepository;

    public function __construct(PharmacistRepositoryInterface $PharmacistRepository)
    {
        $this->pharmacistRepository = $PharmacistRepository;
    }

    public function store(RegisterPharmacistRequest $request)
    {
        Gate::authorize('manage-pharmacy');
        return $this->pharmacistRepository->register($request->validated());

    }

    public function show(int $id)
    {
       return $this->pharmacistRepository->find($id);
    }


   public function update(UpdatePharmacistRequest $request, int $id){
    Gate::authorize('manage-pharmacy');
    $pharmacist = $this->pharmacistRepository->update($id, $request->validated());

    return response()->json([
        'message'     => 'Pharmacist updated successfully',
        'pharmacist'  => $pharmacist->makeHidden('password'),
    ]);
}



    public function destroy(int $id)
{
    Gate::authorize('manage-pharmacy');
    try {
        $this->pharmacistRepository->delete($id);

        return response()->json([
            'message' => 'Pharmacist deleted successfully.'
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'message' => $e->getMessage()
        ], 403);
    }
}


    public function create(RegisterPharmacistRequest $request){
        Gate::authorize('manage-pharmacy');
        return $this->pharmacistRepository->register($request->validated());

    }
    public function login(Request $request){

        return $this->pharmacistRepository->login( $request->validate([
            'username' => 'required|string',
            'password' => 'required|string|min:8',
        ]));
    }

    public function GetPharmacistSales(){
       Gate::authorize('pharmacy-work');
       $saleItems= $this->pharmacistRepository->GetPharmacistSales();

        if (!$saleItems) {
        return response()->json(['message' => 'Not Found'], 404);
    }

    return  GetPharmacistSales::collection($saleItems);
    }

     public function GetPharmacistPurchase(){
       Gate::authorize('pharmacy-work');
       $PurchaseItems= $this->pharmacistRepository->GetPharmacistPurchases();

        if (!$PurchaseItems) {
        return response()->json(['message' => 'Not Found'], 404);
    }

        //return  GetPharmacistSales::collection($PurchaseItems);
        return GetPharmacistPurchase::collection($PurchaseItems);
    }

    public function GetPharmacistProfile(){
        Gate::authorize('pharmacy-work');
        $PharmacistProfile=$this->pharmacistRepository->GetPharmacistProfile();

       return response()->json(['data'=>new GetPharmacistProfile($PharmacistProfile)]);


    }
    public function GetAllPharmacists(){
        Gate::authorize('manage-pharmacy');
        $pharmacists=$this->pharmacistRepository->GetAllPharmacists();

        return GetPharmacistProfile::collection($pharmacists);

    }

    public function GetAllContacts(){
        Gate::authorize('pharmacy-work');
        $contacts= $this->pharmacistRepository->GetAllContacts();
        return response()->json(['data'=>$contacts]);
    }

    
}
