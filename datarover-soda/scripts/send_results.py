import requests
import json
from datetime import datetime

# Soda scan nəticəsi (nümunə)
scan_result = {
    "scan_id": "scan_" + datetime.now().strftime("%Y%m%d_%H%M%S"),
    "scan_name": "Daily Quality Check",
    "data_source": "mysql_db",
    "summary": {
        "total_checks": 5,
        "passed": 4,
        "warnings": 1,
        "failures": 0,
        "score": 90.0
    },
    "checks": [
        {"name": "row_count > 0", "table": "glossary_terms", "outcome": "pass", "value": 150},
        {"name": "duplicate_count(id) = 0", "table": "glossary_terms", "outcome": "pass", "value": 0},
        {"name": "missing_count(name) = 0", "table": "glossary_terms", "outcome": "pass", "value": 0},
        {"name": "row_count > 0", "table": "data_sources", "outcome": "pass", "value": 25},
        {"name": "freshness < 24h", "table": "data_sources", "outcome": "warn", "value": "26h"}
    ],
    "executed_at": datetime.now().isoformat()
}

# DataRover API-yə göndər
response = requests.post(
    "http://localhost/datarover/backend.php?action=soda_scans",
    json=scan_result
)

print("Status:", response.status_code)
print("Response:", response.json())