<?php

namespace Database\Seeders;

/**
 * Published manufacturer/product facts plus explicitly fictional demo values.
 * Source links and the distinction between facts and demo fields are documented
 * in SEED_DATA.md next to this file.
 */
final class SyrianMedicineCatalog
{
    public static function manufacturers(): array
    {
        return [
            'UNIPHARMA' => [
                'company_name' => 'UNIPHARMA',
                'location' => 'Damascus, Syria',
                'phone' => '00963116712508',
                'email' => 'info@unipharma-sy.com',
                'website' => 'https://www.unipharma-sy.com/',
            ],
            'ASIA Pharmaceutical Industries' => [
                'company_name' => 'ASIA Pharmaceutical Industries',
                'location' => 'Aleppo, Syria',
                'phone' => '+963-21-2213033',
                'email' => 'Not published on official contact page',
                'website' => 'https://asiapharms.com/',
            ],
            'PHARMASYR' => [
                'company_name' => 'PHARMASYR',
                'location' => 'Damascus, Syria',
                'phone' => '+963 11 447 2004',
                'email' => 'info@pharmasyr.com',
                'website' => 'https://www.pharmasyr.com/',
            ],
        ];
    }

    public static function medicines(): array
    {
        return [
            [
                'code' => 'UNADOL', 'name' => 'Unadol 500 mg film-coated tablets',
                'manufacturer' => 'UNIPHARMA', 'composition' => 'Paracetamol 500 mg',
                'categories' => ['Analgesics & Antipyretics'], 'price' => '18.00',
                'minimum_quantity' => 12, 'batches' => [[18, '8.00'], [35, '9.00']],
                'source' => 'https://www.unipharma-sy.com/Product/528',
            ],
            [
                'code' => 'UNEXTRA', 'name' => 'Unadol Extra 500/65 mg film-coated tablets',
                'manufacturer' => 'UNIPHARMA',
                'composition' => 'Paracetamol 500 mg + Caffeine 65 mg',
                'categories' => ['Analgesics & Antipyretics'], 'price' => '25.00',
                'minimum_quantity' => 12, 'batches' => [[15, '11.00'], [30, '12.00']],
                'source' => 'https://www.unipharma-sy.com/Product/530',
            ],
            [
                'code' => 'TENDOX', 'name' => 'Tendoxal 125/125 mg per 5 mL oral suspension',
                'manufacturer' => 'UNIPHARMA',
                'composition' => 'Amoxicillin 125 mg/5 mL + Flucloxacillin 125 mg/5 mL',
                'categories' => ['Anti-Infectives'], 'price' => '43.00',
                'minimum_quantity' => 10, 'batches' => [[8, '21.00'], [24, '22.00']],
                'source' => 'https://www.unipharma-sy.com/Product/578',
            ],
            [
                'code' => 'EMPA10', 'name' => 'Empa 10 mg film-coated tablets',
                'manufacturer' => 'UNIPHARMA', 'composition' => 'Empagliflozin 10 mg',
                'categories' => ['Antidiabetics'], 'price' => '76.00',
                'minimum_quantity' => 8, 'batches' => [[7, '39.00'], [20, '41.00']],
                'source' => 'https://www.unipharma-sy.com/Product/518',
            ],
            [
                'code' => 'ASITAMOL', 'name' => 'Asitamol 500 mg tablets',
                'manufacturer' => 'ASIA Pharmaceutical Industries',
                'composition' => 'Paracetamol 500 mg',
                'categories' => ['Analgesics & Antipyretics'], 'price' => '17.00',
                'minimum_quantity' => 12, 'batches' => [[20, '7.00'], [40, '8.00']],
                'source' => 'https://asiapharms.com/en/product',
            ],
            [
                'code' => 'ASIPRO400', 'name' => 'Asiaprofen 400 mg soft gelatin capsules',
                'manufacturer' => 'ASIA Pharmaceutical Industries',
                'composition' => 'Ibuprofen 400 mg',
                'categories' => ['Non-Steroidal Anti-Inflammatories'], 'price' => '31.00',
                'minimum_quantity' => 10, 'batches' => [[12, '15.00'], [28, '16.00']],
                'source' => 'https://asiapharms.com/en/product/by-category/Nonsteroidal-Anti_inflammatory-Drug-%28NSAID%29%2C-Oral',
            ],
            [
                'code' => 'ASIPIRIN81', 'name' => 'Asiapirin 81 mg enteric-coated tablets',
                'manufacturer' => 'ASIA Pharmaceutical Industries',
                'composition' => 'Aspirin 81 mg',
                'categories' => ['Antiplatelets', 'Cardiovascular'], 'price' => '27.00',
                'minimum_quantity' => 10, 'batches' => [[10, '12.00'], [25, '13.00']],
                'source' => 'https://asiapharms.com/en/product-details/44/Asiapirin-81',
            ],
            [
                'code' => 'SUPRAX200', 'name' => 'Supraxime 200 mg film-coated tablets',
                'manufacturer' => 'ASIA Pharmaceutical Industries',
                'composition' => 'Cefixime 200 mg',
                'categories' => ['Anti-Infectives'], 'price' => '59.00',
                'minimum_quantity' => 10, 'batches' => [[3, '30.00'], [4, '32.00']],
                'source' => 'https://asiapharms.com/en/product/by-category/Antibiotic%2C-Cephalosporin-%28Third-Generation%29',
            ],
            [
                'code' => 'PHARPARA', 'name' => 'Paracetamol Pharmasyr 1000 mg tablets',
                'manufacturer' => 'PHARMASYR', 'composition' => 'Paracetamol 1000 mg',
                'categories' => ['Analgesics & Antipyretics'], 'price' => '24.00',
                'minimum_quantity' => 10, 'batches' => [[10, '10.00'], [26, '11.00']],
                'source' => 'https://www.pharmasyr.com/en/product-details/paracetamol-pharmasyr-1000mg/',
            ],
            [
                'code' => 'ZILDEN90', 'name' => 'Zildensyr 90 mg tablets',
                'manufacturer' => 'PHARMASYR', 'composition' => 'Diltiazem HCl 90 mg',
                'categories' => ['Cardiovascular'], 'price' => '55.00',
                'minimum_quantity' => 8, 'batches' => [[9, '27.00'], [22, '29.00']],
                'source' => 'https://www.pharmasyr.com/en/product-details/zildensyr-90mg/',
            ],
            [
                'code' => 'LOTID100', 'name' => 'Lotid 100/12.5 mg film-coated tablets',
                'manufacturer' => 'PHARMASYR',
                'composition' => 'Losartan potassium 100 mg + Hydrochlorothiazide 12.5 mg',
                'categories' => ['Cardiovascular'], 'price' => '68.00',
                'minimum_quantity' => 8, 'batches' => [[8, '34.00'], [21, '36.00']],
                'source' => 'https://www.pharmasyr.com/en/product-details/lotid-100-12-5/',
            ],
            [
                'code' => 'ANTICH20', 'name' => 'Anti Cholesterol 20 mg film-coated tablets',
                'manufacturer' => 'PHARMASYR', 'composition' => 'Rosuvastatin 20 mg',
                'categories' => ['Lipid-Lowering', 'Cardiovascular'], 'price' => '64.00',
                'minimum_quantity' => 8, 'batches' => [[11, '31.00'], [23, '33.00']],
                'source' => 'https://www.pharmasyr.com/en/product-details/anti-cholesterol-20mg/',
            ],
        ];
    }
}
