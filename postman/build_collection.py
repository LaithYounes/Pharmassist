"""Build the importable Postman collection and local environment (stdlib only)."""

import json
from pathlib import Path


HERE = Path(__file__).parent
BASE = "{{baseUrl}}"


def script(lines):
    return {"listen": "test", "script": {"type": "text/javascript", "exec": lines}}


def request(name, method, path, *, payload=None, tests=None, public=False, form=None, description=None):
    url = BASE + path
    req = {
        "name": name,
        "request": {
            "method": method,
            "header": [{"key": "Accept", "value": "application/json"}],
            "url": {"raw": url, "host": [BASE], "path": [p for p in path.lstrip("/").split("/") if p]},
        },
    }
    if description:
        req["request"]["description"] = description
    if public:
        req["request"]["auth"] = {"type": "noauth"}
    if payload is not None:
        req["request"]["header"].append({"key": "Content-Type", "value": "application/json"})
        req["request"]["body"] = {"mode": "raw", "raw": json.dumps(payload, ensure_ascii=False, indent=2), "options": {"raw": {"language": "json"}}}
    if form is not None:
        req["request"]["body"] = {"mode": "formdata", "formdata": form}
    if tests:
        req["event"] = [script(tests)]
    return req


def folder(name, items, description=None):
    result = {"name": name, "item": items}
    if description:
        result["description"] = description
    return result


ok = ["pm.test('نجح الطلب', () => pm.response.to.have.status(200));"]
created = ["pm.test('تم إنشاء السجل', () => pm.response.to.have.status(201));"]
login_tests = ok + [
    "const data = pm.response.json();",
    "pm.test('استُلم توكن', () => pm.expect(data.token).to.be.a('string').and.not.empty);",
    "if (data.token) { pm.environment.set('token', data.token); pm.environment.set('currentUserId', String(data.pharmacist.id)); }",
]
staff_login_tests = ok + [
    "const data = pm.response.json();",
    "pm.test('استُلم توكن الصيدلي', () => pm.expect(data.token).to.be.a('string').and.not.empty);",
    "if (data.token) pm.environment.set('pharmacistToken', data.token);",
]
catalog_tests = ok + [
    "const rows = pm.response.json().data || [];",
    "const preferred = pm.environment.get('preferredMedicineName');",
    "const medicine = rows.find(m => m.name === preferred && Number(m.sellable_quantity) > 0) || rows.find(m => Number(m.sellable_quantity) > 0);",
    "pm.test('يوجد دواء قابل للبيع', () => pm.expect(medicine).to.exist);",
    "if (medicine) { pm.environment.set('medicineId', String(medicine.id)); pm.environment.set('medicineName', medicine.name); if (medicine.categories?.[0]?.[0]) pm.environment.set('categoryName', medicine.categories[0][0]); }",
]
quick_catalog_tests = catalog_tests + [
    "if (medicine) pm.environment.set('stockBefore', String(medicine.sellable_quantity));",
]
final_stock_tests = ok + [
    "const rows = pm.response.json().data || [];",
    "const medicine = rows.find(m => String(m.id) === pm.environment.get('medicineId'));",
    "pm.test('الإرجاع أعاد كمية الدواء القابلة للبيع', () => { pm.expect(medicine).to.exist; pm.expect(Number(medicine.sellable_quantity)).to.eql(Number(pm.environment.get('stockBefore'))); });",
]
sell_tests = ok + [
    "const data = pm.response.json();",
    "pm.test('حُفظ رقم الفاتورة', () => pm.expect(data.sale_id).to.exist);",
    "if (data.sale_id) pm.environment.set('saleId', String(data.sale_id));",
]
sale_tests = ok + [
    "const data = pm.response.json().data || pm.response.json();",
    "const item = data.items?.[0]; const allocation = item?.batches?.[0];",
    "pm.test('الفاتورة تعرض توزيع الدفعة', () => { pm.expect(item).to.exist; pm.expect(allocation).to.exist; });",
    "if (item && allocation) { pm.environment.set('saleItemId', String(item.sale_item_id)); pm.environment.set('allocationId', String(allocation.sale_item_batch_allocation_id)); pm.environment.set('batchId', String(allocation.batch_id)); }",
]
return_tests = [
    "pm.test('تم الإرجاع', () => pm.expect(pm.response.code).to.be.oneOf([200, 201]));",
    "pm.test('حُفظ طلب الإرجاع', () => pm.expect(pm.response.json().data).to.exist);",
]
reps_tests = ok + [
    "const rows = pm.response.json().data || pm.response.json();",
    "if (Array.isArray(rows) && rows[0]?.id) pm.environment.set('saleRepresentativeId', String(rows[0].id));",
]
purchases_tests = ok + [
    "const rows = pm.response.json().data || [];",
    "const newest = rows.reduce((best, row) => !best || Number(row.purchase_id) > Number(best.purchase_id) ? row : best, null);",
    "if (newest) pm.environment.set('purchaseId', String(newest.purchase_id));",
]
lifecycle_tests = ok + [
    "const data = pm.response.json(); const item = data.items?.[0];",
    "if (item) { pm.environment.set('purchaseItemId', String(item.purchase_item_id)); pm.environment.set('purchaseMedicineId', String(item.medicine_id)); pm.environment.set('quantityRequested', String(item.quantity_requested)); }",
]

login = request("تسجيل دخول المدير وحفظ التوكن", "POST", "/api/Login", payload={"username": "{{adminUsername}}", "password": "{{adminPassword}}"}, tests=login_tests, public=True)
staff_login = request("تسجيل دخول الصيدلي وحفظ توكنه", "POST", "/api/Login", payload={"username": "{{pharmacistUsername}}", "password": "{{pharmacistPassword}}"}, tests=staff_login_tests, public=True)
catalog = request("عرض الأدوية واختيار دواء متاح", "GET", "/api/medicines", tests=catalog_tests, public=True)
quick_catalog = request("عرض الأدوية وحفظ كمية المخزون", "GET", "/api/medicines", tests=quick_catalog_tests, public=True)
sell_body = {"request_id": "{{$guid}}", "items": [{"medicine_id": "{{medicineId}}", "quantity": 1}]}
sell = request("بيع وحدة واحدة وحفظ رقم الفاتورة", "POST", "/api/SellMedicine", payload=sell_body, tests=sell_tests)
sale = request("عرض الفاتورة وحفظ معرّفات الدفعة", "GET", "/api/sales/{{saleId}}", tests=sale_tests)
return_body = {"request_id": "{{$guid}}", "sale_id": "{{saleId}}", "sale_item_id": "{{saleItemId}}", "sale_item_batch_allocation_id": "{{allocationId}}", "quantity_returned": 1, "reason": "تجربة Postman: إعادة إلى المخزون", "condition": "restockable"}
ret = request("إرجاع الوحدة إلى دفعتها", "POST", "/api/ReturnMedicine", payload=return_body, tests=return_tests)

quick = folder("00 | تجربة جاهزة بنقرة Run", [
    login, quick_catalog, sell, sale, ret,
    request("عرض إرجاعات الفاتورة", "GET", "/api/sales/{{saleId}}/returns", tests=ok),
    request("التحقق من عودة كمية المخزون", "GET", "/api/medicines", tests=final_stock_tests, public=True),
    request("عرض تقرير صافي المبيعات", "GET", "/api/reports/net-sales", tests=ok),
], "شغّل هذا المجلد وحده عبر Collection Runner بعد تعبئة بيانات المدير وتشغيل API والـ seeders. يبيع وحدة واحدة ثم يعيدها إلى الدفعة نفسها. ينشئ سجلات بيع وإرجاع فعلية.")

auth = folder("01 | الدخول والحسابات", [
    login,
    staff_login,
    request("ملف المستخدم الحالي", "GET", "/api/PharmacistProfile", tests=ok),
    request("عرض الصيادلة | مدير", "GET", "/api/getAllPharmacists", tests=ok),
    request("عرض جهات الاتصال", "GET", "/api/GetAllContacts", tests=ok),
    request("إنشاء حساب صيدلي | مدير", "POST", "/api/RegisterPharmasict", payload={"first_name": "Demo", "last_name": "Pharmacist", "username": "demo_{{$timestamp}}", "password": "DemoPass123!", "phone": "09{{$timestamp}}", "salary": "2500.00"}, tests=["pm.test('تم إنشاء الصيدلي', () => pm.expect(pm.response.code).to.be.oneOf([200, 201]));", "const data = pm.response.json(); if (data.id) pm.environment.set('pharmacistId', String(data.id));"], description="إنشاء الصيدلي للمدير فقط. لا يقبل is_admin. غيّر الهاتف إلى رقم فريد عند الحاجة."),
    request("تحديث صيدلي | مدير", "PUT", "/api/UpdatePharmacist/{{pharmacistId}}", payload={"first_name": "Demo", "last_name": "Updated"}),
    request("حذف صيدلي | مدير ⚠️", "DELETE", "/api/DeletePharmacist/{{pharmacistId}}", description="عملية حذف فعلية؛ شغّلها على بيانات تجريبية فقط."),
])

meds = folder("02 | الأدوية والفئات", [
    catalog,
    request("تفاصيل دواء", "GET", "/api/medicines/{{medicineId}}", tests=ok, public=True),
    request("بحث باسم دواء", "GET", "/api/Search/{{medicineName}}", public=True),
    request("عرض الأدوية حسب الفئة", "GET", "/api/GetByCategoryName/{{categoryName}}", public=True),
    request("عرض كل الفئات", "GET", "/api/GetAllCategories", tests=ok, public=True),
    request("إضافة دواء إلى الكتالوج | مدير", "POST", "/api/medicines", payload={"name": "Postman Demo Medicine {{$timestamp}}", "manufacturer": "UNIPHARMA", "categories": ["Analgesics & Antipyretics"], "prescription": "No", "production_Date": "2026-01-01", "expiration_Date": "2029-01-01", "quantity_in_stock": 0, "sci_name": "Paracetamol 500 mg", "price": "18.00", "minimum_quantity": 5}, tests=ok + ["const data = pm.response.json(); if (data.medicine?.id) pm.environment.set('medicineId', String(data.medicine.id));"], description="الإضافة تنشئ دواء بكمية صفر؛ تزداد الكمية عند استلام دفعة."),
    request("تعديل سعر دواء | مدير", "PUT", "/api/medicines/{{medicineId}}", payload={"price": "19.00"}),
    request("حذف دواء | مدير ⚠️", "DELETE", "/api/medicines/{{medicineId}}", description="عملية حذف فعلية؛ قد تفشل إن كان الدواء مرتبطاً بفواتير أو دفعات."),
])

sales = folder("03 | البيع والإرجاع", [
    sell, sale,
    request("فواتير المستخدم", "GET", "/api/GetPharmacistSales", tests=ok),
    ret,
    request("إرجاعات فاتورة", "GET", "/api/sales/{{saleId}}/returns", tests=ok),
])

expiry_script = ["const d = new Date(); d.setUTCFullYear(d.getUTCFullYear() + 2); pm.environment.set('futureExpiryDate', d.toISOString().slice(0, 10));"]
receive = request("استلام دفعة بعد الموافقة", "POST", "/api/purchases/{{purchaseId}}/receive", payload={"batches": [{"purchase_item_id": "{{purchaseItemId}}", "medicine_id": "{{purchaseMedicineId}}", "batch_number": "POSTMAN-{{$guid}}", "expiration_date": "{{futureExpiryDate}}", "quantity_received": "{{quantityRequested}}"}]}, description="شغّل تفاصيل دورة الطلب أولاً لالتقاط معرّف البند. عدّل batches بحيث تغطي جميع البنود وبالكميات المستلمة الصحيحة. الاستلام مرة واحدة فقط.")
receive["event"] = [{"listen": "prerequest", "script": {"type": "text/javascript", "exec": expiry_script}}, script(created + ["const data = pm.response.json(); if (data.receipt_id) pm.environment.set('receiptId', String(data.receipt_id));"])]

supply = folder("04 | طلبات التوريد ودورة الحالات", [
    request("عرض مندوبي التوريد وحفظ أول معرّف", "GET", "/api/SalesRep", tests=reps_tests),
    request("إنشاء طلب توريد", "POST", "/api/SupplyRequest", payload={"sale_representative_id": "{{saleRepresentativeId}}", "items": [{"medicine_name": "{{medicineName}}", "quantity": 3}]}, description="استجابة الإنشاء لا تتضمن purchase_id حالياً؛ اطلب قائمة التوريد بعدها لحفظ أحدث معرّف."),
    request("عرض طلبات التوريد وحفظ أحدث معرّف", "GET", "/api/GetPharmacistPurchase", tests=purchases_tests),
    request("تفاصيل دورة الطلب وحفظ معرّف البند", "GET", "/api/purchases/{{purchaseId}}/lifecycle", tests=lifecycle_tests),
    request("رفع ملف السعر للطلب | مدير", "POST", "/api/purchases/{{purchaseId}}/price", form=[{"key": "file", "type": "file", "src": ""}], description="اختر ملف xlsx/xls المُصدَّر لهذا الطلب. يجب أن تتطابق البنود والكميات؛ الرفع لا يغيّر المخزون."),
    request("رفع ملف سعر بالمسار القديم | مدير", "POST", "/api/ImportPricedSuppOrder", form=[{"key": "purchase_id", "value": "{{purchaseId}}", "type": "text"}, {"key": "file", "type": "file", "src": ""}], description="بديل للمسار المرتبط بالطلب؛ لا تشغّل الاثنين للطلب نفسه."),
    request("الموافقة على طلب مُسعّر | مدير", "POST", "/api/purchases/{{purchaseId}}/approve", tests=ok),
    receive,
    request("رفض طلب | مدير", "POST", "/api/purchases/{{purchaseId}}/reject", payload={"reason": "سبب رفض تجريبي"}),
    request("إلغاء طلب", "POST", "/api/purchases/{{purchaseId}}/cancel", payload={"reason": "سبب إلغاء تجريبي"}),
], "التسلسل الصحيح: Requested → Priced → Approved → Received. اختر ملف الأسعار يدوياً؛ طلبات الرفض والإلغاء تغيّر الحالة.")

stock = folder("05 | الدفعات وحركات المخزون", [
    request("سجل حركات دفعة | مدير", "GET", "/api/batches/{{batchId}}/movements", tests=ok),
    request("تصحيح جرد دفعة | مدير ⚠️", "POST", "/api/batches/{{batchId}}/adjustments", payload={"request_id": "{{$guid}}", "quantity_delta": 1, "reason": "تصحيح جرد تجريبي"}),
    request("تسجيل تلف دفعة | مدير ⚠️", "POST", "/api/batches/{{batchId}}/damage", payload={"request_id": "{{$guid}}", "quantity_delta": -1, "reason": "تلف تجريبي"}),
])

reports = folder("06 | التقارير | مدير", [
    request("صافي المبيعات", "GET", "/api/reports/net-sales", tests=ok),
    request("صافي المبيعات اليومي", "GET", "/api/reports/daily/{{reportDate}}", tests=ok),
    request("صافي المبيعات الشهري", "GET", "/api/reports/monthly/{{reportYear}}/{{reportMonth}}", tests=ok),
])

collection = {
    "info": {
        "name": "Pharmassist | واجهة الصيدلية الكاملة",
        "description": "مجموعة API بحسب routes/api.php. شغّل المجلد 00 فقط للعرض التلقائي. تتطلب بقية عمليات الكتابة بيانات اختبار ومراجعة المدخلات قبل الإرسال.",
        "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json",
    },
    "auth": {"type": "bearer", "bearer": [{"key": "token", "value": "{{token}}", "type": "string"}]},
    "event": [{"listen": "prerequest", "script": {"type": "text/javascript", "exec": ["pm.request.headers.upsert({ key: 'Accept', value: 'application/json' });"]}}],
    "item": [quick, auth, meds, sales, supply, stock, reports],
}

variables = [
    ("baseUrl", "http://127.0.0.1:8000"),
    ("adminUsername", "demo_admin"), ("adminPassword", "DemoAdmin2026!"), ("token", ""),
    ("pharmacistUsername", "demo_pharmacist"), ("pharmacistPassword", "DemoPharmacist2026!"), ("pharmacistToken", ""),
    ("currentUserId", ""), ("preferredMedicineName", "Unadol 500 mg film-coated tablets"),
    ("medicineId", ""), ("medicineName", ""), ("categoryName", ""), ("stockBefore", ""), ("saleId", ""), ("saleItemId", ""),
    ("allocationId", ""), ("batchId", ""), ("pharmacistId", ""), ("saleRepresentativeId", ""),
    ("purchaseId", ""), ("purchaseItemId", ""), ("purchaseMedicineId", ""), ("quantityRequested", ""),
    ("futureExpiryDate", ""), ("receiptId", ""), ("reportDate", "2026-09-16"),
    ("reportYear", "2026"), ("reportMonth", "9"),
]
environment = {
    "name": "Pharmassist | محلي",
    "values": [{"key": key, "value": value, "type": "default", "enabled": True} for key, value in variables],
    "_postman_variable_scope": "environment",
}

for name, document in [
    ("Pharmassist.postman_collection.json", collection),
    ("Pharmassist.local.postman_environment.json", environment),
]:
    (HERE / name).write_text(json.dumps(document, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
