# DataRover Quality Results API

## Overview

DataRover Quality API allows external tools and systems to submit data quality check results. This enables integration with:

- Great Expectations
- dbt tests
- Soda Core
- Apache Griffin
- Custom quality scripts
- Any other quality tool

## Authentication

All API requests require authentication using an API key. You can pass the key in two ways:

```
Authorization: Bearer dqr_test_key_12345abcdef
```

or

```
X-Api-Key: dqr_test_key_12345abcdef
```

## Endpoints

### Submit Quality Results

**POST** `/backend.php?action=api/quality-results`

Submit quality check results for a specific rule.

#### Request Body

```json
{
  "rule_id": "rule_email_format",
  "pass_rate": 98.5,
  "total_records": 10000,
  "passed_records": 9850,
  "failed_records": 150,
  "run_date": "2024-12-03T15:30:00Z",
  "source_system": "Great Expectations",
  "table_name": "customers",
  "column_name": "email",
  "metadata": {
    "expectation_suite": "customer_quality",
    "batch_id": "batch_123"
  }
}
```

#### Required Fields

| Field | Type | Description |
|-------|------|-------------|
| rule_id | string | The ID of the quality rule |
| pass_rate | number | Pass rate percentage (0-100) |

#### Optional Fields

| Field | Type | Description |
|-------|------|-------------|
| total_records | integer | Total number of records checked |
| passed_records | integer | Number of records that passed |
| failed_records | integer | Number of records that failed |
| run_date | datetime | When the check was run (ISO 8601) |
| source_system | string | Name of the quality tool |
| table_name | string | Database table name |
| column_name | string | Column name |
| metadata | object | Any additional metadata |

#### Response

```json
{
  "success": true,
  "data": {
    "id": 123,
    "message": "Quality result recorded successfully",
    "rule_id": "rule_email_format",
    "pass_rate": 98.5
  }
}
```

### Get Quality Results

**GET** `/backend.php?action=api/quality-results`

Retrieve quality check results.

#### Query Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| rule_id | string | Filter by rule ID (optional) |
| limit | integer | Number of results (default: 10, max: 100) |

#### Response

```json
{
  "success": true,
  "data": [
    {
      "id": 123,
      "rule_id": "rule_email_format",
      "rule_name": "Email format yoxlaması",
      "rule_type": "validity",
      "pass_rate": 98.5,
      "total_records": 10000,
      "passed_records": 9850,
      "failed_records": 150,
      "run_date": "2024-12-03 15:30:00",
      "source_system": "Great Expectations"
    }
  ]
}
```

## Available Rule IDs

| Rule ID | Name | Type |
|---------|------|------|
| rule_email_format | Email format yoxlaması | validity |
| rule_null_check | Null dəyər yoxlaması | completeness |
| rule_unique_id | Unikal ID yoxlaması | uniqueness |
| rule_date_range | Tarix aralığı yoxlaması | validity |
| rule_positive_amount | Müsbət məbləğ yoxlaması | validity |

## Example Usage

### cURL

```bash
curl -X POST "http://localhost/dc/backend.php?action=api/quality-results" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer dqr_test_key_12345abcdef" \
  -d '{
    "rule_id": "rule_email_format",
    "pass_rate": 98.5,
    "total_records": 10000,
    "source_system": "My Quality Tool"
  }'
```

### Python

```python
import requests

url = "http://localhost/dc/backend.php?action=api/quality-results"
headers = {
    "Content-Type": "application/json",
    "Authorization": "Bearer dqr_test_key_12345abcdef"
}
data = {
    "rule_id": "rule_email_format",
    "pass_rate": 98.5,
    "total_records": 10000,
    "passed_records": 9850,
    "failed_records": 150,
    "source_system": "Python Script"
}

response = requests.post(url, json=data, headers=headers)
print(response.json())
```

### Great Expectations Integration

```python
from great_expectations.checkpoint import Checkpoint
import requests

def send_results_to_datarover(results):
    """Send Great Expectations results to DataRover"""
    
    url = "http://your-datarover-url/backend.php?action=api/quality-results"
    headers = {
        "Authorization": "Bearer your_api_key",
        "Content-Type": "application/json"
    }
    
    for result in results.results:
        expectation_config = result.expectation_config
        
        # Map GE expectation to DataRover rule
        rule_mapping = {
            "expect_column_values_to_not_be_null": "rule_null_check",
            "expect_column_values_to_match_regex": "rule_email_format",
            "expect_column_values_to_be_unique": "rule_unique_id"
        }
        
        rule_id = rule_mapping.get(expectation_config.expectation_type)
        if not rule_id:
            continue
            
        data = {
            "rule_id": rule_id,
            "pass_rate": result.success_percent or 0,
            "total_records": result.result.get("element_count", 0),
            "passed_records": result.result.get("unexpected_count", 0),
            "source_system": "Great Expectations",
            "table_name": result.expectation_config.kwargs.get("batch_id"),
            "column_name": result.expectation_config.kwargs.get("column"),
            "metadata": {
                "expectation_type": expectation_config.expectation_type,
                "batch_id": str(result.batch_id)
            }
        }
        
        requests.post(url, json=data, headers=headers)
```

### dbt Integration

```python
# In your dbt post-hook or custom script
import json
import requests

def send_dbt_test_results():
    """Parse dbt test results and send to DataRover"""
    
    with open("target/run_results.json") as f:
        results = json.load(f)
    
    url = "http://your-datarover-url/backend.php?action=api/quality-results"
    headers = {"Authorization": "Bearer your_api_key"}
    
    for result in results.get("results", []):
        if result.get("unique_id", "").startswith("test."):
            # Map dbt test to DataRover rule
            test_name = result.get("unique_id").split(".")[-1]
            
            data = {
                "rule_id": f"dbt_{test_name}",
                "pass_rate": 100 if result.get("status") == "pass" else 0,
                "source_system": "dbt",
                "metadata": {
                    "status": result.get("status"),
                    "execution_time": result.get("execution_time")
                }
            }
            
            requests.post(url, json=data, headers=headers)
```

## Error Responses

| Code | Description |
|------|-------------|
| 400 | Bad Request - Missing required fields |
| 401 | Unauthorized - Invalid or missing API key |
| 404 | Not Found - Rule ID not found |
| 405 | Method Not Allowed |
| 500 | Internal Server Error |

## API Key Management

Contact your DataRover administrator to:
- Request a new API key
- Revoke an existing key
- Set key expiration date

Default test key for development:
```
dqr_test_key_12345abcdef
```

⚠️ **Important**: Replace the test key with a secure key in production!
