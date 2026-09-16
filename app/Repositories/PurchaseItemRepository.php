<?php

namespace App\Repositories;

use App\Repositories\Interfaces\PurchaseItemsRepositoryInterface;
use App\Repositories\Interfaces\MedicineRepositoryInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use App\Models\SaleRepresentative;
use App\Mail\SendSupplyOrder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use Illuminate\Support\Facades\DB;
use App\Models\Medicine;
use App\Models\Status;
use App\Enums\PurchaseStatus;
use App\Services\PurchaseLifecycle;
use App\Services\Money;

class PurchaseItemRepository implements PurchaseItemsRepositoryInterface
{
    protected MedicineRepositoryInterface $medicines;
    protected PurchaseLifecycle $lifecycle;

    public function __construct(MedicineRepositoryInterface $medicines, PurchaseLifecycle $lifecycle)
    {
        $this->medicines = $medicines;
        $this->lifecycle = $lifecycle;
    }

    /**
     * Pick a single Medicine by name using the repository's findByName().
     * - Prefer exact (case-insensitive) match if present.
     * - If none exact:
     *     - if 1 fuzzy match -> use it
     *     - if 0 -> null
     *     - if >1 -> throw ambiguity
     */
    private function resolveMedicineByName(string $name): array
    {
        $candidates = $this->medicines->findByName($name); // Collection
        if ($candidates->isEmpty()) {
            return ['status' => false, 'error' => "Medicine '{$name}' not found."];
        }

        $lower = mb_strtolower(trim($name));
        $exact = $candidates->first(function ($m) use ($lower) {
            return mb_strtolower($m->name) === $lower;
        });

        if ($exact) {
            return ['status' => true, 'medicine' => $exact];
        }

        if ($candidates->count() === 1) {
            return ['status' => true, 'medicine' => $candidates->first()];
        }

        $names = $candidates->pluck('name')->take(5)->implode(', ');
        return [
            'status' => false,
            'error' => "Medicine name '{$name}' is ambiguous. Did you mean: {$names} ...?"
        ];
    }

    private function CheckQuantities(array $items): array
    {
        $PurchaseItems = [];

        foreach ($items as $idx => $item) {
            // Expect medicine_name instead of medicine_id
            if (!isset($item['medicine_name']) || trim($item['medicine_name']) === '') {
                return [
                    'status' => false,
                    'message' => "Item #".($idx+1).": 'medicine_name' is required."
                ];
            }
            if (!isset($item['quantity']) || !is_numeric($item['quantity']) || (int)$item['quantity'] < 1) {
                return [
                    'status' => false,
                    'message' => "Item #".($idx+1).": quantity must be >= 1."
                ];
            }

            $resolved = $this->resolveMedicineByName($item['medicine_name']);
            if (!$resolved['status']) {
                return ['status' => false, 'message' => $resolved['error']];
            }

            $PurchaseItems[] = [
                'medicine' => $resolved['medicine'],
                'quantity' => (int)$item['quantity'],
                'price'    => 0, // sales representative will fill later
            ];
        }

        return ['status' => true, 'data' => $PurchaseItems];
    }

    private function CreatePurchase(array $items)
    {
        $saleRep = SaleRepresentative::with('warehouse')->findOrFail($items['sale_representative_id']);

        return Purchase::create([
            'pharmacist_id'          => Auth::id(),
            'sale_representative_id' => $items['sale_representative_id'],
            'warehouse_id'           => $saleRep->warehouse_id,
            'purchase_date'          => now(),
            'status_id'              => Status::idFor(PurchaseStatus::Requested),
        ]);
    }

    private function CreatePurchaseItems(Purchase $purchase, array $items)
    {
        $purchaseItems = [];

        foreach ($items as $item) {
            PurchaseItem::create([
                'purchase_id' => $purchase->id,
                'medicine_id' => $item['medicine']->id,
                'quantity'    => $item['quantity'],
                'price'       => 0,
            ]);

            $purchaseItems[] = [
                'medicine' => $item['medicine'],
                'quantity' => $item['quantity'],
            ];
        }

        return $purchaseItems;
    }

    private function export(array $purchaseItems, Purchase $purchase)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Header row
        $sheet->fromArray(['Purchase_id', 'Medicine_id', 'Medicine Name', 'Quantity', 'Price'], null, 'A1');

        $row = 2;
        foreach ($purchaseItems as $item) {
            $sheet->setCellValue("A{$row}", $purchase->id);
            $sheet->setCellValue("B{$row}", $item['medicine']->id);
            $sheet->setCellValue("C{$row}", $item['medicine']->name);
            $sheet->setCellValue("D{$row}", $item['quantity']);
            $sheet->setCellValue("E{$row}", $item['price'] ?? 0);
            $row++;
        }

        // Price validation (>= 0)
        $priceValidation = new DataValidation();
        $priceValidation->setType(DataValidation::TYPE_DECIMAL);
        $priceValidation->setErrorStyle(DataValidation::STYLE_STOP);
        $priceValidation->setAllowBlank(true);
        $priceValidation->setShowInputMessage(true);
        $priceValidation->setShowErrorMessage(true);
        $priceValidation->setErrorTitle('Invalid Input');
        $priceValidation->setError('Only numeric values are allowed.');
        $priceValidation->setPromptTitle('Price');
        $priceValidation->setPrompt('Please enter a valid number.');
        $priceValidation->setOperator(DataValidation::OPERATOR_GREATERTHANOREQUAL);
        $priceValidation->setFormula1('0');

        for ($r = 2; $r <= 1000; $r++) {
            $cell = "E{$r}";
            $sheet->getCell($cell)->setDataValidation(clone $priceValidation);
            $sheet->getStyle($cell)->getProtection()->setLocked(false);
        }

        $sheet->getProtection()->setSheet(true);
        $sheet->getProtection()->setPassword('12345');
        $sheet->getProtection()->setInsertRows(false);
        $sheet->getProtection()->setInsertColumns(false);
        $sheet->getProtection()->setDeleteRows(false);
        $sheet->getProtection()->setDeleteColumns(false);

        $filename = "SupplyOrder_{$purchase->id}.xlsx";
        $directory = storage_path('app/private/supply-orders');
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $path = "{$directory}/{$filename}";

        $writer = new Xlsx($spreadsheet);
        $writer->save($path);

        return $path;
    }

    public function MakeSupplyOrder(array $items)
    {
        $check = $this->CheckQuantities($items['items'] ?? []);

        if (!$check['status']) {
            return $check;
        }

        [$purchase, $purchaseItems] = DB::transaction(function () use ($items, $check) {
            $purchase = $this->CreatePurchase($items);
            $purchaseItems = $this->CreatePurchaseItems($purchase, $check['data']);
            $this->lifecycle->recordCreation($purchase, Auth::user());
            return [$purchase, $purchaseItems];
        }, 3);
        $filePath      = $this->export($purchaseItems, $purchase);

        $representative = SaleRepresentative::find($items['sale_representative_id']);
        $email = $representative?->email;

        if ($email) {
            Mail::to($email)->send(new SendSupplyOrder($filePath));
        }

        return ['message' => 'Request sent successfully'];
    }

 public function ImportPricedSupplyOrder($filePath, ?int $expectedPurchaseId = null)
 {
     try {
         $sheet = IOFactory::load($filePath)->getActiveSheet();
         $headers = ['Purchase_id', 'Medicine_id', 'Medicine Name', 'Quantity', 'Price'];
         foreach ($headers as $column => $header) {
             if (trim((string) $sheet->getCell(chr(65 + $column).'1')->getValue()) !== $header) {
                 return ['status' => false, 'message' => 'Invalid priced order headers. Price must be the line total.'];
             }
         }
         $purchaseId = trim((string) $sheet->getCell('A2')->getCalculatedValue());
         if (!ctype_digit($purchaseId)) {
             return ['status' => false, 'message' => 'Invalid purchase ID.'];
         }
         if ($expectedPurchaseId !== null && (int) $purchaseId !== $expectedPurchaseId) {
             return ['status' => false, 'message' => 'File belongs to another purchase.'];
         }
         $lastRow = (int) $sheet->getHighestDataRow();
         $lines = [];
         for ($row = 2; $row <= $lastRow; $row++) {
             $rowPurchase = trim((string) $sheet->getCell("A{$row}")->getCalculatedValue());
             $medicineId = trim((string) $sheet->getCell("B{$row}")->getCalculatedValue());
             $fileQuantity = trim((string) $sheet->getCell("D{$row}")->getCalculatedValue());
             $rawPrice = trim((string) $sheet->getCell("E{$row}")->getCalculatedValue());
             $name = trim((string) $sheet->getCell("C{$row}")->getCalculatedValue());
             if ($rowPurchase === '' && $medicineId === '' && $name === '' && $fileQuantity === '' && $rawPrice === '') {
                 continue;
             }
             if ($rowPurchase !== $purchaseId || !ctype_digit($medicineId)
                 || !ctype_digit($fileQuantity) || (int) $fileQuantity < 1
                 || !preg_match('/^\d+(?:\.\d{1,2})?$/', $rawPrice)
                 || Money::cents($rawPrice) <= 0 || isset($lines[(int) $medicineId])) {
                 return ['status' => false, 'message' => 'Invalid or duplicate item in priced order.'];
             }
             $lines[(int) $medicineId] = ['total_cents' => Money::cents($rawPrice), 'quantity' => (int) $fileQuantity, 'name' => $name];
         }
         if (!$lines) {
             return ['status' => false, 'message' => 'No items found in file.'];
         }
         ksort($lines, SORT_NUMERIC);

         return DB::transaction(function () use ($purchaseId, $lines) {
             $purchase = Purchase::whereKey((int) $purchaseId)->lockForUpdate()->first();
             if (!$purchase) {
                 return ['status' => false, 'message' => 'Purchase not found.'];
             }
             if ($purchase->statusCode() !== PurchaseStatus::Requested) {
                 return ['status' => false, 'message' => 'Purchase has already been processed.'];
             }
             $items = PurchaseItem::where('purchase_id', $purchase->id)->orderBy('id')->lockForUpdate()->get();
             $purchaseItems = [];
             foreach ($items as $item) {
                 if (isset($purchaseItems[(int) $item->medicine_id])) {
                     return ['status' => false, 'message' => 'Duplicate medicine in purchase.'];
                 }
                 $purchaseItems[(int) $item->medicine_id] = $item;
             }
             $expected = array_keys($purchaseItems);
             $provided = array_keys($lines);
             sort($expected, SORT_NUMERIC);
             sort($provided, SORT_NUMERIC);
             if ($expected !== $provided) {
                 return ['status' => false, 'message' => 'Priced file does not match purchase items.'];
             }
             foreach ($provided as $id) {
                 $medicine = Medicine::find($id);
                 if (!$medicine || $lines[$id]['name'] !== $medicine->name
                     || (int) $purchaseItems[$id]->quantity < 1
                     || (int) $purchaseItems[$id]->quantity !== $lines[$id]['quantity']
                     || $lines[$id]['total_cents'] % $lines[$id]['quantity'] !== 0) {
                     return ['status' => false, 'message' => 'Invalid purchase item or medicine.'];
                 }
             }
             foreach ($lines as $id => $line) {
                 $item = $purchaseItems[$id];
                 $unitPrice = Money::decimal(intdiv($line['total_cents'], $line['quantity']));
                 $item->price = $unitPrice;
                 if (!$item->save()) {
                     throw new \RuntimeException('Unable to update supply item price');
                 }
             }
             $this->lifecycle->transitionLocked($purchase, PurchaseStatus::Priced, Auth::user());
             return ['status' => true, 'message' => 'Prices imported; stock awaits batch receipt.'];
         }, 3);
     } catch (\Throwable $e) {
         report($e);
         return ['status' => false, 'message' => 'Priced order could not be imported.', 'http_status' => 500];
     }
 }
}
