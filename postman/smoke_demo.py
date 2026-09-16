"""Run the same local sale/return flow as Postman's quick demo folder."""

import json
import uuid
from pathlib import Path
from urllib.error import HTTPError
from urllib.request import Request, urlopen


values = json.loads((Path(__file__).parent / "Pharmassist.local.postman_environment.json").read_text(encoding="utf-8"))
env = {entry["key"]: entry["value"] for entry in values["values"]}
base = env["baseUrl"].rstrip("/")


def call(method, path, body=None, token=None):
    headers = {"Accept": "application/json"}
    if token:
        headers["Authorization"] = f"Bearer {token}"
    data = None
    if body is not None:
        headers["Content-Type"] = "application/json"
        data = json.dumps(body).encode("utf-8")
    req = Request(base + path, data=data, headers=headers, method=method)
    try:
        with urlopen(req, timeout=10) as response:
            return json.load(response)
    except HTTPError as error:
        raise RuntimeError(f"{method} {path}: HTTP {error.code}: {error.read().decode('utf-8')[:500]}") from error


login = call("POST", "/api/Login", {"username": env["adminUsername"], "password": env["adminPassword"]})
assert login["Is_admin"] is True and login["token"], "Demo administrator login failed"
token = login["token"]

catalog = call("GET", "/api/medicines")["data"]
preferred = env["preferredMedicineName"]
medicine = next((row for row in catalog if row["name"] == preferred and int(row["sellable_quantity"]) > 0), None)
medicine = medicine or next(row for row in catalog if int(row["sellable_quantity"]) > 0)
before = int(medicine["sellable_quantity"])

sold = call("POST", "/api/SellMedicine", {
    "request_id": str(uuid.uuid4()), "items": [{"medicine_id": medicine["id"], "quantity": 1}],
}, token)
assert sold["status"] and sold["sale_id"], "Sale was not saved"
sale_id = sold["sale_id"]
invoice = call("GET", f"/api/sales/{sale_id}", token=token)["data"]
item = invoice["items"][0]
allocation = item["batches"][0]
assert allocation["batch_number"] and allocation["quantity"] == 1, "Batch allocation is missing"

returned = call("POST", "/api/ReturnMedicine", {
    "request_id": str(uuid.uuid4()), "sale_id": sale_id,
    "sale_item_id": item["sale_item_id"],
    "sale_item_batch_allocation_id": allocation["sale_item_batch_allocation_id"],
    "quantity_returned": 1, "reason": "Local Postman demo verification", "condition": "restockable",
}, token)
assert returned["data"] and not returned["replayed"], "Return was not saved"
assert call("GET", f"/api/sales/{sale_id}/returns", token=token), "Return is missing from invoice"

after = next(row for row in call("GET", "/api/medicines")["data"] if row["id"] == medicine["id"])
assert int(after["sellable_quantity"]) == before, "Sellable stock did not recover after return"
report = call("GET", "/api/reports/net-sales", token=token)
assert "net_sales" in report, "Net sales report is missing"

staff = call("POST", "/api/Login", {
    "username": env["pharmacistUsername"], "password": env["pharmacistPassword"],
})
assert staff["Is_admin"] is False and staff["token"], "Demo pharmacist login failed"
assert call("GET", "/api/PharmacistProfile", token=staff["token"])["data"], "Demo pharmacist profile failed"

print(f"OK: both demo logins, sale #{sale_id}, batch {allocation['batch_number']}, return, restored stock, net-sales report")
