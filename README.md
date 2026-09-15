# Pharmassist

Pharmassist is a Laravel API for pharmacy operations. It manages a medicine catalog, sales, returns, supply orders, and basic reporting. The project is being developed into a pharmacy inventory system that can trace each medicine batch from supplier receipt through sale or return, enforce expiry and stock rules, and explain every stock change.

## Current state

The repository currently contains:

- A medicine catalog with manufacturers and categories.
- Pharmacist accounts and token-based login using Laravel Sanctum.
- Sales that reduce the quantity stored on a medicine record, and returns that increase it.
- Supply requests exported as spreadsheets and a priced-order import that currently updates prices and stock together.
- Basic sales reports and a dashboard with low-stock and upcoming-expiry information.

These features are the starting point. Batch-level inventory, the supply approval and receipt stages, consistent role authorization, and an auditable stock ledger are planned work; they are not implemented yet.

## Target workflow

1. **Request supply:** A pharmacist selects medicines and quantities and creates a supply request linked to a representative.
2. **Price:** A priced supplier document is validated against that request and records the proposed purchase cost. Pricing does not change stock.
3. **Approve:** An administrator approves or rejects the priced request. The decision and actor are recorded.
4. **Receive:** A pharmacist records the quantities actually received, batch numbers, and expiry dates. Only accepted quantities enter sellable stock.
5. **Sell:** A pharmacist sells from non-expired batches, using the earliest-expiring eligible batch first. The sale keeps its original price and batch allocations.
6. **Return:** A return refers to the original sale and batch. Sellable items return to that batch; damaged or expired items are kept out of sellable stock.
7. **Review:** An administrator can inspect stock movements, net sales, low stock, approaching expiry, and supply orders requiring action.

Every stock-changing action should be atomic, prevent duplicate processing, and leave a movement record explaining the resulting quantity.

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

This table describes the intended authorization rules, not the current API permissions. Public account creation and unprotected sensitive routes must be addressed before the workflow is considered complete.

## Portfolio milestone

A complete demonstration will show two batches of the same medicine with different expiry dates, a supply request moving through pricing, approval, and receipt, a sale drawn from the earliest-expiring valid batch, a return to its original batch, and the corresponding stock movement and report changes. Automated tests will cover authorization, insufficient stock, expiry, returns, and repeat imports or receipts.
