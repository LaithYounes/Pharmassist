"""Check the generated collection against Laravel's registered API routes."""

import json
import re
import subprocess
from pathlib import Path


here = Path(__file__).parent
root = here.parent
collection = json.loads((here / "Pharmassist.postman_collection.json").read_text(encoding="utf-8"))
environment = json.loads((here / "Pharmassist.local.postman_environment.json").read_text(encoding="utf-8"))
routes = json.loads(subprocess.check_output(["php", "artisan", "route:list", "--path=api", "--json"], cwd=root, text=True))
api_routes = [(set(row["method"].split("|")), row["uri"]) for row in routes if row["uri"].startswith("api/")]
variables = {row["key"] for row in environment["values"]}
env_values = {row["key"]: row["value"] for row in environment["values"]}
errors = []
count = 0

for filename, username_key, password_key in [
    ("DemoAdministratorSeeder.php", "adminUsername", "adminPassword"),
    ("DemoPharmacistSeeder.php", "pharmacistUsername", "pharmacistPassword"),
]:
    source = (root / "database" / "seeders" / filename).read_text(encoding="utf-8")
    for constant, key in [("USERNAME", username_key), ("PASSWORD", password_key)]:
        match = re.search(rf"public const {constant} = '([^']+)';", source)
        if not match or match.group(1) != env_values[key]:
            errors.append(f"Postman credential {key} does not match {filename}")


def same_path(template, actual):
    parts_a, parts_b = template.split("/"), actual.split("/")
    return len(parts_a) == len(parts_b) and all(
        a == b or (a.startswith("{") and a.endswith("}")) for a, b in zip(parts_a, parts_b)
    )


def walk(items):
    global count
    for item in items:
        if "item" in item:
            walk(item["item"])
            continue
        count += 1
        req = item["request"]
        path = req["url"]["raw"].removeprefix("{{baseUrl}}/")
        if not any(req["method"] in methods and same_path(uri, path) for methods, uri in api_routes):
            errors.append(f"Unknown route: {req['method']} {path}")
        content = json.dumps(item, ensure_ascii=False)
        for key in re.findall(r"\{\{([A-Za-z][A-Za-z0-9]*)\}\}", content):
            if key not in variables:
                errors.append(f"Missing environment variable {key} in {item['name']}")
        if req.get("body", {}).get("mode") == "raw":
            json.loads(req["body"]["raw"])


walk(collection["item"])
if errors:
    raise SystemExit("\n".join(errors))
print(f"OK: {count} requests match Laravel API routes; JSON bodies and environment variables are valid.")
