# Syrian pharmacy demo data

The manufacturer names, published contact details, product names, dosage forms,
and compositions below were checked against the companies' own sites on
2026-09-16. This is a catalog fixture for software testing, not a dispensing
reference. The application does not assert Syrian prescription status because
that status was not established from these product pages.

| Manufacturer | Official company/contact source |
| --- | --- |
| UNIPHARMA | [Company site](https://www.unipharma-sy.com/) · [Contact](https://www.unipharma-sy.com/Contact_Us) |
| ASIA Pharmaceutical Industries | [Company site](https://asiapharms.com/) · [Contact](https://asiapharms.com/en/contactus) |
| PHARMASYR | [Company site](https://www.pharmasyr.com/en/) · [Contact](https://www.pharmasyr.com/en/contact-us/) |

ASIA publishes a contact form and phone number but no email address on its
contact page. The `manufacturers.email` column is required by the existing
schema, so the seed value says `Not published on official contact page` rather
than inventing an address.

| Product in seed data | Official product source |
| --- | --- |
| Unadol 500 mg film-coated tablets | [UNIPHARMA](https://www.unipharma-sy.com/Product/528) |
| Unadol Extra 500/65 mg film-coated tablets | [UNIPHARMA](https://www.unipharma-sy.com/Product/530) |
| Tendoxal 125/125 mg per 5 mL oral suspension | [UNIPHARMA](https://www.unipharma-sy.com/Product/578) |
| Empa 10 mg film-coated tablets | [UNIPHARMA](https://www.unipharma-sy.com/Product/518) |
| Asitamol 500 mg tablets | [ASIA product catalog](https://asiapharms.com/en/product) |
| Asiaprofen 400 mg soft gelatin capsules | [ASIA NSAID catalog](https://asiapharms.com/en/product/by-category/Nonsteroidal-Anti_inflammatory-Drug-%28NSAID%29%2C-Oral) |
| Asiapirin 81 mg enteric-coated tablets | [ASIA](https://asiapharms.com/en/product-details/44/Asiapirin-81) |
| Supraxime 200 mg film-coated tablets | [ASIA cephalosporin catalog](https://asiapharms.com/en/product/by-category/Antibiotic%2C-Cephalosporin-%28Third-Generation%29) |
| Paracetamol Pharmasyr 1000 mg tablets | [PHARMASYR](https://www.pharmasyr.com/en/product-details/paracetamol-pharmasyr-1000mg/) |
| Zildensyr 90 mg tablets | [PHARMASYR](https://www.pharmasyr.com/en/product-details/zildensyr-90mg/) |
| Lotid 100/12.5 mg film-coated tablets | [PHARMASYR](https://www.pharmasyr.com/en/product-details/lotid-100-12-5/) |
| Anti Cholesterol 20 mg film-coated tablets | [PHARMASYR](https://www.pharmasyr.com/en/product-details/anti-cholesterol-20mg/) |

The following fields are **fictional demo values**, not verified manufacturer
or market records: catalog sale prices, purchase costs, batch numbers, quantities,
production dates, expiration dates, minimum-stock thresholds, warehouses,
supplier contacts, staff salary and phone numbers. Batch numbers all start with
`DEMO-`, and demo contacts use `example.test` or a `DEMO-` prefix. The seeded
supplier contacts are not representatives of the real companies. Currency is
not asserted; prices are sample application amounts.

Run `php artisan migrate` and `php artisan db:seed` on the configured local
development database. The seeders load 3 real Syrian manufacturer names,
12 documented products, 24 fictional batches, 2 fictional supplier contacts,
and an opening stock ledger balance for each batch. Re-running `db:seed` does
not restore stock sold after the initial seed.

In `local` or `testing`, the seeders also create two **fictional demo accounts**:
administrator `demo_admin` / `DemoAdmin2026!` and regular pharmacist
`demo_pharmacist` / `DemoPharmacist2026!`. These known credentials are supplied
in the local Postman environment for quick testing. The password hashes are
recreated on every seed run. No demo account is created in production.
