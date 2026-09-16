# Pharmassist

Pharmassist is a Laravel API for pharmacy operations. It manages a medicine catalog, sales, returns, supply orders, and basic reporting. The project is being developed into a pharmacy inventory system that can trace each medicine batch from supplier receipt through sale or return, enforce expiry and stock rules, and explain every stock change.

## Current state

The repository currently contains:

- A medicine catalog with manufacturers and categories.
- Pharmacist accounts and token-based login using Laravel Sanctum.
- Batch-allocated sales and returns that restore eligible units to their original batch.
- Supply requests exported as private spreadsheets, manager-only price import, approval, and separate batch receipt.
- Basic sales reports and a dashboard with low-stock and upcoming-expiry information.

The batch table, demo data, sale allocations, batch-linked returns, supply approval, receipt records, and an ordered stock ledger across implemented batch operations now exist.

## Local development demo data

The current local `.env` uses MySQL. Run the migrations and all seeders on that configured local database:

```bash
php artisan migrate
php artisan db:seed
php artisan serve
```

Repeating `php artisan db:seed` does not duplicate catalog records or restore stock sold after the first seed. The demo contains 3 Syrian manufacturer names, 12 documented products, 24 fictional batches, 2 fictional supplier contacts, 429 initial units, and two local demo accounts. The importable Postman files and run instructions are in [`postman/README.md`](postman/README.md).

The manufacturer identities and medicine names, dosage forms, and compositions are sourced from the companies' official product pages. See [the seed data sources](database/seeders/SEED_DATA.md) for the product-by-product links and the distinction between published facts and fictional demo values. Sale prices, purchase costs, batch numbers, quantities, production and expiry dates, warehouse and staffing values are hypothetical demo inputs, not market prices or real batches. No legacy medicine records are transformed into starter batches. The batch table has database checks for nonnegative quantity and cost, a status check, a medicine foreign key, and a unique `(medicine_id, batch_number)` index. At seed time the existing medicine-level stock field equals the sum of its demo batches. Live sales preserve their original batch allocations, and new returns refer to those saved allocations.

## Administrator setup and pharmacist accounts

For the local demo, `db:seed` creates administrator `demo_admin` / `DemoAdmin2026!` and regular pharmacist `demo_pharmacist` / `DemoPharmacist2026!`. The accounts are restricted to `local` and `testing`; these public demo passwords must not be used for real accounts. Re-running the seeder restores both demo passwords. On a database where demo accounts are not seeded, `php artisan pharmacists:create-first-admin` remains the separate interactive setup command for the first administrator.

Log in with `POST /api/Login` using `username` and `password`, then send the returned Sanctum token as `Authorization: Bearer <token>` to `POST /api/RegisterPharmasict`. Only an authenticated administrator can create pharmacists. Provide `first_name`, `last_name`, `username`, `password`, `phone`, and `salary`; the server always creates a regular pharmacist and ignores any submitted `is_admin` value.

## Target workflow

1. **Request supply:** A pharmacist selects medicines and quantities and creates a supply request linked to a representative.
2. **Price:** A priced supplier document is validated against that request and records the proposed purchase cost. Pricing does not change stock.
3. **Approve:** An administrator approves or rejects the priced request. The decision and actor are recorded.
4. **Receive:** A pharmacist records the quantities actually received, batch numbers, and expiry dates. Only accepted quantities enter sellable stock.

### Supply purchase lifecycle

The purchase status is resolved by the unique `statuses.code`, never by a numeric status ID. `purchases.status_id` remains a foreign key. The migration creates new coded rows without guessing the meaning of uncoded historical rows; old development purchases must be recreated if needed. Every successful change and the initial creation append one row to `purchase_status_transitions` with the previous and new status codes, actor ID and name at the time, timestamp, and any required reason. The history model and database triggers refuse updates and deletion. The purchase row is locked and the status change and history insert share a transaction, so a repeated or conflicting request cannot create another transition.

| From | Action and endpoint | To | Authorized actor |
| --- | --- | --- | --- |
| None | Create: `POST /api/SupplyRequest` | `Requested` | Authenticated pharmacist or manager |
| `Requested` | Import matching price file: `POST /api/ImportPricedSuppOrder` | `Priced` | Manager |
| `Priced` | `POST /api/purchases/{id}/approve` | `Approved` | Manager |
| `Priced` | `POST /api/purchases/{id}/reject` with `reason` | `Rejected` | Manager |
| `Approved` | `POST /api/purchases/{id}/receive` with actual batches and quantities | `Received` | Requesting pharmacist or manager |
| `Requested` | `POST /api/purchases/{id}/cancel` with `reason` | `Cancelled` | Requesting pharmacist or manager |
| `Priced` or `Approved` | `POST /api/purchases/{id}/cancel` with `reason` | `Cancelled` | Manager |

`Received`, `Rejected`, and `Cancelled` are final. There is no general status update endpoint, and clients cannot submit `status` or `status_id` to the creation or decision endpoints. `Received` cannot be set through a status-only request. `GET /api/purchases/{id}/lifecycle` returns the current code and ordered history to the requesting pharmacist or any manager. A guest receives `401`; another pharmacist receives `403`. Invalid transitions return `409`, and missing reasons or direct status fields return `422`.

Price import writes unit purchase cost to `purchase_items.price` only. It neither increases medicine stock nor changes `medicines.price`, the catalog sale price. The exported `Price` column is the **total cost for the row**; the importer requires a positive amount with at most two decimal places and an exact cent division by the requested quantity. Every row must carry the matching purchase ID, medicine ID and name, and requested quantity. The set of medicines must match exactly, with no missing, extra, or repeated row. A rejected file changes no item price or status. Use `POST /api/purchases/{id}/price` with multipart `file` to bind the upload to an intended purchase. The legacy `POST /api/ImportPricedSuppOrder` also works and accepts optional `purchase_id`; the file ID selects the order when omitted. Only a manager can import. Pricing a `Priced`, `Approved`, or `Received` order again returns `422` without another change. Supplier order exports are kept under `storage/app/private/supply-orders`, outside the public disk; uploaded price files are checked from the request temporary file and are not retained.

Receipt is a separate `POST /api/purchases/{id}/receive` JSON request with `batches`, each containing `purchase_item_id`, optional matching `medicine_id`, `batch_number`, future `expiration_date` (`YYYY-MM-DD`), and positive `quantity_received`. An item may have no batch and therefore zero received units. The response and purchase read views report `quantity_requested`, `quantity_received`, and `quantity_short` for **every** item, making partial delivery explicit. For each item, the sum of received units cannot exceed its requested amount. A new `(medicine_id, batch_number)` creates an available batch at the item's purchase cost; an existing batch is increased only if its expiry, available status, and purchase cost agree. The receipt, per-batch before/after stock movement rows, stock totals, and `Approved` → `Received` history are saved in one transaction. `purchase_receipts.purchase_id` is unique, and the locked purchase row is checked for `Approved` and no previous receipt before any stock write. A retry after a lost response returns `409` and does not add stock twice. A validation or write failure rolls back all receipt changes.
5. **Sell:** A pharmacist sells from non-expired batches, using the earliest-expiring eligible batch first. The sale keeps its original price and batch allocations.

The implemented `POST /api/SellMedicine` accepts medicine IDs and quantities. It ignores submitted prices or batch IDs and uses only batches with `status = available`, positive `available_quantity`, and `expiration_date >=` the local sale date. A batch expiring **today** is sellable until the end of that date; one expiring yesterday is not. Batches are consumed by ascending expiry date and then ascending batch ID. Repeated medicine lines are summed before the stock check; the original lines and their individual batch allocations are kept on the invoice.

`sale_item_batch_allocations` stores `sale_item_id`, `batch_id`, the quantity taken, and the purchase cost snapshot. The batch foreign key restricts deletion of historically used batches. `GET /api/sales/{saleId}` and `GET /api/GetPharmacistSales` show the saved line price, each batch number, and its allocated quantity. A pharmacist sees only their own invoices; an administrator can see all invoices. The sale, lines, allocations, movements, and batch changes share one transaction. Medicine rows and eligible batch rows are locked in deterministic order; the ledger checks each batch's current balance before recording its decrement.

Catalog stock is read from the batch table and includes `sellable_quantity` separately. The legacy `medicines.quantity_in_stock` field is refreshed from batch sums after sales and returns; new catalog records must start at zero and API updates cannot set stock directly. Priced supplier files do not create stock because they contain no batch number or expiry date. Stock enters the sellable pool when a batch is recorded. Historical sales without allocations, and allocated lines with older unallocated returns, require reconciliation before batch returns can be processed.
6. **Return:** A return refers to the original sale item and its saved batch allocation. Eligible items return to that batch; damaged or expired items stay out of sellable stock.
7. **Review:** An administrator can inspect stock movements, net sales, low stock, approaching expiry, and supply orders requiring action.

Every implemented stock-changing action is atomic and leaves a movement record explaining the resulting quantity.

### Batch-linked returns API

`POST /api/ReturnMedicine` requires a pharmacist authorized for the invoice (its seller or an administrator). Send a fresh UUID in `request_id`, the `sale_id`, and either `items` or the old single-item fields. Each item requires `sale_item_id`, positive `quantity_returned`, `reason`, and `condition` (`restockable`, `damaged`, or `expired`). Supply `sale_item_batch_allocation_id` when the invoice item was sold from multiple batches. When it has exactly one saved allocation, the ID may be omitted. `GET /api/sales/{saleId}` exposes each allocation ID alongside its saved batch number and sold quantity. Missing allocation on a multi-batch item, foreign allocation, item from another invoice, or quantity beyond an allocation's unreturned sold units returns `422` without stock changes. Repeated allocation entries in one request are summed before checking the limit.

Each new `medicine_returns` row records the invoice, sale item, saved allocation, returned and actually restocked quantities, reason, condition, timestamp, and pharmacist. `GET /api/sales/{saleId}/returns` shows these rows with their original batch and actor, under the same invoice permission. Historical rows with no saved allocation remain readable with a null allocation; the API does not guess their original batch.

`restockable` units increase `available_quantity` in the original batch only while its status is `available` and its expiry date is today or later. A quarantined or expired original batch produces a return with `quantity_restocked = 0`. `damaged` and `expired` always have `quantity_restocked = 0`. The medicine-level stock total is refreshed from batch quantities in the same transaction. The invoice and allocation rows are locked while previous returns and repeated entries are checked; eligible batch updates also check current status and expiry.

Keep the same `request_id` and body when retrying after a lost response. A successful first submission returns `201` with `replayed: false`; an identical retry by the same pharmacist returns `200` with `replayed: true` and the original rows, without increasing stock again. Reusing an ID with changed details or a different actor returns `422`. A failed transaction saves neither return rows nor the request ID, so it can be retried. The UUID is unique across requests.

## Current endpoint permissions

API requests use Sanctum bearer tokens from `POST /api/Login`. Protected API routes return `401` without authentication and `403` when the signed-in pharmacist lacks permission. The server reads the pharmacist's `is_admin` value; clients cannot grant themselves administrator access.

| Routes | Guest | Pharmacist | Administrator |
| --- | --- | --- | --- |
| `POST /api/Login`; catalog `GET /api/medicines`, `/api/medicines/{id}`, `/api/Search/{name}`, `/api/GetByCategoryName/{categoryName}`, `/api/GetAllCategories` | Yes | Yes | Yes |
| `POST /api/SellMedicine`, `/api/SupplyRequest` | No | Yes | Yes |
| `POST /api/ReturnMedicine`; `GET /api/sales/{saleId}/returns` | No | Own sales | Any sale |
| `GET /api/PharmacistProfile`, `/api/GetPharmacistSales`, `/api/GetPharmacistPurchase` | No | Own data | Own profile; all sales and purchases |
| `GET /api/GetAllContacts`, `/api/SalesRep` | No | Yes | Yes |
| `POST /api/medicines`; `PUT/PATCH/DELETE /api/medicines/{id}` | No | No | Yes |
| `POST /api/RegisterPharmasict`; `PUT /api/UpdatePharmacist/{id}`; `DELETE /api/DeletePharmacist/{id}` | No | No | Yes (cannot delete own account) |
| `POST /api/ImportPricedSuppOrder`, `/api/purchases/{id}/price`; `GET /api/getAllPharmacists` | No | No | Yes |
| `GET /api/purchases/{id}/lifecycle` | No | Own purchase | Any purchase |
| `POST /api/purchases/{id}/approve`, `/reject` | No | No | Yes, from `Priced` |
| `POST /api/purchases/{id}/receive` | No | Own approved purchase | Any approved purchase |
| `POST /api/purchases/{id}/cancel` | No | Own `Requested` purchase | `Requested`, `Priced`, or `Approved` |
| `GET /api/reports/net-sales`, `/api/reports/daily/{date?}`, `/api/reports/monthly/{year}/{month}` | No | No | Yes |
| `GET /api/batches/{batch}/movements`; `POST /api/batches/{batch}/adjustments`, `/damage` | No | No | Yes |
| `GET /dashboard`, `/dashboard/top-manufacturers` | No | No | Yes, with Web session |
| Telescope developer dashboard and its data routes, when enabled | No | No | Yes, with Web session |

The dashboard uses a pharmacist-backed Web session: sign in at `GET/POST /dashboard/login` with the same username and password, and sign out with `POST /dashboard/logout`. A Sanctum bearer token alone does not create a Web session. Guests are redirected to the login page; signed-in regular pharmacists receive `403` on dashboard routes.

## Target roles

| Action | Pharmacist | Administrator |
| --- | --- | --- |
| View catalog and available stock | Yes | Yes |
| Create supply requests | Yes | Yes |
| Record supplier prices and approve requests | No | Yes |
| Receive approved orders | Yes | Yes |
| Sell medicine and process returns | Yes | Yes |
| Manage medicines and pharmacist accounts | No | Yes |
| View audit records and management reports | No | Yes |

The batch movement read endpoint and manager-only count correction and disposal endpoints are implemented below.

## Batch stock ledger and monetary reporting

`stock_movements` contains one ordered row per batch event: `medicine_id`, `batch_id`, `type`, `quantity`, signed `quantity_delta`, sellable `quantity_before` and `quantity_after`, `source_type` and `source_id`, `pharmacist_id`, `occurred_at`, optional `reason`, and a nullable movement-time purchase cost. A unique `(source_type, source_id)` key prevents processing one source twice. Source types point to a seed batch, initial batch insert, receipt line, sale batch allocation, medicine return, or manager stock adjustment. Earlier batch balances present when the ledger migration runs are anchored as `migration_batch_baseline` opening rows; the migration does not invent their older transactions. Seeded batches start at zero, then append an `opening` movement from `0` to the demo quantity. On SQLite, a positive batch inserted directly also receives a database-generated opening row.

Movement types are `opening`, `receipt`, `sale`, `return_restock`, `return_unsellable`, `damage`, `adjustment_positive`, and `adjustment_negative`. A restockable return adds only the quantity still eligible for sale. Damaged, expired, or otherwise unsellable returns append a zero-delta event with the returned quantity; they never enter sellable stock. Damage and both count adjustments require a written reason. The ledger is append-only: a mistaken event is resolved by a new documented correction, never by editing or deleting an older movement. An identical sale `request_id` UUID replays its invoice, and return and adjustment UUIDs replay their original event. A reused UUID with different details is rejected.

Read `GET /api/batches/{batch}/movements` as a manager. Start at zero and add each `quantity_delta` in ascending movement ID order. Every row's `quantity_before` must equal the running balance, and `quantity_after` must equal that balance plus its delta. The response shows `ledger_balance`, the current `available_quantity`, and `consistent`. Managers can submit `POST /api/batches/{batch}/adjustments` with `request_id`, nonzero signed `quantity_delta`, and `reason`; a negative result is rejected. `POST /api/batches/{batch}/damage` takes the same fields with a negative delta. SQLite applies each inserted movement to its batch inside database triggers and blocks direct batch quantity edits; other supported databases use the ledger service's enclosing transaction and row locks. Each application movement also refreshes `medicines.quantity_in_stock` from batch balances.

Catalog `medicines.price` is the current selling price. `purchase_items.price` and nullable `medicine_batches.unit_purchase_cost` are purchase costs, and a requested order's zero placeholder is **not** a trusted batch cost. `sale_items.price` snapshots the catalog selling price at checkout; `sale_item_batch_allocations.unit_purchase_cost_at_sale` snapshots the actual batch cost at exit. Invoice totals, line prices, costs, and pharmacist salary are decimal columns with two places and 14 total digits. Input amounts permit at most two decimal places; extra fractional digits are rejected rather than rounded. Sums and products use integer cents, with cent-exact totals; supplier row totals must divide into an exact unit-cent cost. API monetary values are strings such as `"0.30"` to preserve decimal precision.

`GET /api/reports/net-sales` reports gross sales, refunds at saved invoice prices, and **net sales** as gross sales minus refunds. A restockable return reverses its saved sold batch cost. A damaged or expired return is refunded but its saved cost remains a loss; it appears separately as `unsellable_return_loss_known`. Disposed in-stock units appear as `disposal_loss_known`; negative count corrections appear as `count_shortage_loss_known`. Both are deducted from `gross_margin_after_losses`. Margin is net sales minus sold batch costs plus restocked cost reversals minus separate disposal and count-shortage losses. This is a gross merchandise margin, not final accounting profit: overhead, tax, shipping, supplier credits, and later cost-layer changes are outside this report. If any sold, returned, or disposed unit lacks a trustworthy saved cost, `gross_margin_after_losses` is `null`, `margin_complete` is `false`, and `unknown_cost_units` identifies the coverage gap. Historical sales without saved batch allocations are treated as unknown-cost sales; their costs are never assumed to be zero.

Daily and monthly reports attribute sales to their sale date and refunds to their return date. A period can therefore include a cost reversal for a sale booked in an earlier period.

## Portfolio milestone

A complete demonstration will show two batches of the same medicine with different expiry dates, a supply request moving through pricing, approval, and receipt, a sale drawn from the earliest-expiring valid batch, a return to its original batch, and the corresponding stock movement and report changes. Automated tests will cover authorization, insufficient stock, expiry, returns, and repeat imports or receipts.
