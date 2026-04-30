# 🔍 Soda Core Integration Guide for DataRover

## 1. Soda Core Nədir?

**Soda Core** - açıq mənbə (open-source) data quality testing framework-üdür. SQL-based yoxlamalar yazaraq verilənlərin keyfiyyətini avtomatik monitorinq etməyə imkan verir.

### DataRover-də Necə İşləyəcək:

```
┌─────────────────┐     ┌──────────────┐     ┌─────────────────┐
│   DataRover     │────▶│  Soda Core   │────▶│   Database      │
│   (Frontend)    │     │  (Python)    │     │ (PG/MySQL/Snow) │
└─────────────────┘     └──────────────┘     └─────────────────┘
        │                      │
        │                      ▼
        │               ┌──────────────┐
        └──────────────▶│   Results    │
                        │   (JSON)     │
                        └──────────────┘
```

### Əsas Xüsusiyyətlər:
- ✅ SQL-based data quality checks
- ✅ Schema validation
- ✅ Freshness monitoring
- ✅ Anomaly detection
- ✅ Custom SQL checks
- ✅ JSON/YAML output
- ✅ CI/CD integration
- ✅ Multi-database support

---

## 2. Quraşdırma

```bash
# Core package
pip install soda-core

# Database-specific packages
pip install soda-core-postgres    # PostgreSQL
pip install soda-core-mysql       # MySQL/MariaDB
pip install soda-core-snowflake   # Snowflake
pip install soda-core-sqlserver   # SQL Server
pip install soda-core-bigquery    # BigQuery
pip install soda-core-redshift    # Redshift
pip install soda-core-spark       # Spark/Databricks

# All at once
pip install soda-core-postgres soda-core-mysql soda-core-snowflake
```

---

## 3. Directory Strukturu

```
datarover-soda/
├── config/
│   ├── postgres_connection.yml      # PostgreSQL connection
│   ├── mysql_connection.yml         # MySQL connection
│   ├── snowflake_connection.yml     # Snowflake connection
│   └── configuration.yml            # Main config (multi-source)
│
├── checks/
│   ├── common/
│   │   ├── schema_checks.yml        # Schema validation
│   │   ├── freshness_checks.yml     # Data freshness
│   │   └── volume_checks.yml        # Row count checks
│   │
│   ├── tables/
│   │   ├── customers_checks.yml     # Table-specific checks
│   │   ├── orders_checks.yml
│   │   └── products_checks.yml
│   │
│   └── custom/
│       ├── business_rules.yml       # Business logic checks
│       └── anomaly_detection.yml    # Statistical anomalies
│
├── results/
│   ├── latest.json                  # Latest scan results
│   ├── history/                     # Historical results
│   │   ├── 2024-01-15_10-30.json
│   │   └── 2024-01-16_10-30.json
│   └── reports/                     # Generated reports
│
├── scripts/
│   ├── run_scan.py                  # Python runner
│   ├── run_scan.sh                  # Shell script
│   └── send_to_api.py               # API integration
│
└── logs/
    └── soda.log                     # Execution logs
```

---

## 4. Connection Configurations

### 4.1 PostgreSQL Connection (`config/postgres_connection.yml`)

```yaml
data_source postgres_db:
  type: postgres
  host: localhost
  port: 5432
  username: ${POSTGRES_USER}
  password: ${POSTGRES_PASSWORD}
  database: datarover
  schema: public
  
  # Connection pool settings
  connection_timeout: 30
  
  # SSL (production)
  # sslmode: require
  # sslrootcert: /path/to/ca.crt
```

### 4.2 MySQL Connection (`config/mysql_connection.yml`)

```yaml
data_source mysql_db:
  type: mysql
  host: localhost
  port: 3306
  username: ${MYSQL_USER}
  password: ${MYSQL_PASSWORD}
  database: datarover
  
  # Optional settings
  connection_timeout: 30
  # ssl_ca: /path/to/ca.pem
  # ssl_cert: /path/to/client-cert.pem
  # ssl_key: /path/to/client-key.pem
```

### 4.3 Snowflake Connection (`config/snowflake_connection.yml`)

```yaml
data_source snowflake_db:
  type: snowflake
  account: ${SNOWFLAKE_ACCOUNT}
  username: ${SNOWFLAKE_USER}
  password: ${SNOWFLAKE_PASSWORD}
  database: DATAROVER
  warehouse: COMPUTE_WH
  schema: PUBLIC
  role: SYSADMIN
  
  # Session parameters
  session_parameters:
    QUERY_TAG: 'soda-scan'
    STATEMENT_TIMEOUT_IN_SECONDS: 300
```

### 4.4 Multi-Source Configuration (`config/configuration.yml`)

```yaml
# PostgreSQL Production
data_source prod_postgres:
  type: postgres
  host: ${PROD_PG_HOST}
  port: 5432
  username: ${PROD_PG_USER}
  password: ${PROD_PG_PASSWORD}
  database: production_db
  schema: public

# MySQL Analytics
data_source analytics_mysql:
  type: mysql
  host: ${ANALYTICS_MYSQL_HOST}
  port: 3306
  username: ${ANALYTICS_MYSQL_USER}
  password: ${ANALYTICS_MYSQL_PASSWORD}
  database: analytics

# Snowflake Data Warehouse
data_source dwh_snowflake:
  type: snowflake
  account: ${SNOWFLAKE_ACCOUNT}
  username: ${SNOWFLAKE_USER}
  password: ${SNOWFLAKE_PASSWORD}
  database: DWH
  warehouse: ETL_WH
  schema: PUBLIC
  role: DATA_ENGINEER

# Soda Cloud (optional - for UI dashboard)
# soda_cloud:
#   host: cloud.soda.io
#   api_key_id: ${SODA_CLOUD_API_KEY_ID}
#   api_key_secret: ${SODA_CLOUD_API_KEY_SECRET}
```

---

## 5. Data Quality Checks (20+ Hazır Check)

### 5.1 Schema Validation (`checks/common/schema_checks.yml`)

```yaml
# Schema Validation Checks
# Ensures table structure matches expectations

checks for customers:
  # Column existence check
  - schema:
      name: Customer table schema validation
      fail:
        when required column missing:
          - id
          - email
          - created_at
          - status
        when wrong column type:
          id: integer
          email: character varying
          created_at: timestamp
          status: character varying
      warn:
        when forbidden column present:
          - password_hash
          - ssn

checks for orders:
  - schema:
      name: Orders schema check
      fail:
        when required column missing:
          - order_id
          - customer_id
          - order_date
          - total_amount
          - status
```

### 5.2 Volume & Row Count (`checks/common/volume_checks.yml`)

```yaml
# Volume Checks
# Monitor data volume and growth

checks for customers:
  # Basic row count
  - row_count > 0:
      name: Customers table not empty
  
  # Minimum expected rows
  - row_count >= 1000:
      name: Minimum customer count
  
  # Maximum rows (data explosion check)
  - row_count < 10000000:
      name: Customer count sanity check

checks for orders:
  - row_count > 0:
      name: Orders table not empty
  
  # Daily volume check
  - row_count:
      name: Daily order volume
      fail: when < 100
      warn: when < 500

checks for daily_transactions:
  # Change-based check
  - change for row_count:
      name: Transaction volume change
      fail: when > 50%   # More than 50% change is suspicious
      warn: when > 25%
```

### 5.3 Freshness Checks (`checks/common/freshness_checks.yml`)

```yaml
# Freshness Checks
# Ensure data is up-to-date

checks for orders:
  # Data must be updated within last 24 hours
  - freshness(order_date) < 24h:
      name: Orders data freshness (24h)
  
  # Warning if older than 12 hours
  - freshness(order_date):
      name: Orders freshness monitoring
      warn: when > 12h
      fail: when > 24h

checks for customers:
  - freshness(updated_at) < 48h:
      name: Customer data freshness

checks for daily_reports:
  - freshness(report_date) < 1d:
      name: Daily reports freshness
      
checks for real_time_events:
  - freshness(event_timestamp) < 1h:
      name: Real-time events freshness
```

### 5.4 Uniqueness & Duplicates (`checks/common/uniqueness_checks.yml`)

```yaml
# Uniqueness Checks
# Detect duplicate records

checks for customers:
  # Primary key uniqueness
  - duplicate_count(id) = 0:
      name: Customer ID uniqueness
  
  # Business key uniqueness
  - duplicate_count(email) = 0:
      name: Customer email uniqueness
  
  # Percentage-based
  - duplicate_percent(phone) < 1%:
      name: Phone number duplicates below 1%

checks for orders:
  - duplicate_count(order_id) = 0:
      name: Order ID uniqueness
  
  # Composite key uniqueness
  - duplicate_count(customer_id, order_date, product_id) = 0:
      name: Order line uniqueness

checks for products:
  - duplicate_count(sku) = 0:
      name: Product SKU uniqueness
  
  - duplicate_count(barcode) = 0:
      name: Barcode uniqueness
```

### 5.5 Null & Missing Values (`checks/common/null_checks.yml`)

```yaml
# Null Value Checks
# Detect missing data

checks for customers:
  # Zero nulls allowed
  - missing_count(id) = 0:
      name: Customer ID not null
  
  - missing_count(email) = 0:
      name: Customer email not null
  
  # Percentage threshold
  - missing_percent(phone) < 10%:
      name: Phone number completeness
  
  - missing_percent(address) < 20%:
      name: Address completeness

checks for orders:
  # Critical fields
  - missing_count(order_id) = 0
  - missing_count(customer_id) = 0
  - missing_count(order_date) = 0
  - missing_count(total_amount) = 0
  
  # Optional fields with threshold
  - missing_percent(shipping_address) < 5%:
      name: Shipping address completeness

checks for products:
  - missing_count(name) = 0
  - missing_count(price) = 0
  - missing_percent(description) < 30%
  - missing_percent(image_url) < 50%
```

### 5.6 Value Validity (`checks/common/validity_checks.yml`)

```yaml
# Value Validity Checks
# Ensure values are within expected ranges

checks for customers:
  # Format validation
  - invalid_count(email) = 0:
      name: Email format validation
      valid format: email
  
  # Regex validation
  - invalid_count(phone) = 0:
      name: Phone format validation
      valid regex: '^\+?[0-9]{10,15}$'
  
  # Enum validation
  - invalid_count(status) = 0:
      name: Customer status validation
      valid values: ['active', 'inactive', 'pending', 'suspended']
  
  # Length validation
  - invalid_count(country_code) = 0:
      name: Country code format
      valid length: 2

checks for orders:
  # Range validation
  - invalid_count(total_amount) = 0:
      name: Order amount positive
      valid min: 0
  
  - invalid_count(quantity) = 0:
      name: Quantity validation
      valid min: 1
      valid max: 10000
  
  # Status enum
  - invalid_count(status) = 0:
      valid values: ['pending', 'confirmed', 'shipped', 'delivered', 'cancelled']

checks for products:
  - invalid_count(price) = 0:
      valid min: 0.01
      valid max: 999999.99
  
  - invalid_count(sku) = 0:
      valid regex: '^[A-Z]{2,4}-[0-9]{4,8}$'
```

### 5.7 Referential Integrity (`checks/common/reference_checks.yml`)

```yaml
# Referential Integrity Checks
# Ensure foreign key relationships are valid

checks for orders:
  # Customer reference check
  - values in (customer_id) must exist in customers (id):
      name: Order customer reference
  
  # Product reference
  - values in (product_id) must exist in products (id):
      name: Order product reference

checks for order_items:
  - values in (order_id) must exist in orders (id):
      name: Order item reference
  
  - values in (product_id) must exist in products (id):
      name: Product reference in order items

checks for employees:
  # Self-referencing check
  - values in (manager_id) must exist in employees (id):
      name: Manager reference check
      filter: manager_id IS NOT NULL
```

### 5.8 Anomaly Detection (`checks/custom/anomaly_detection.yml`)

```yaml
# Anomaly Detection Checks
# Statistical anomaly detection

checks for daily_sales:
  # Automatic anomaly detection
  - anomaly detection for row_count:
      name: Daily sales volume anomaly
      
  - anomaly detection for sum(amount):
      name: Daily revenue anomaly
      
  - anomaly detection for avg(order_value):
      name: Average order value anomaly

checks for orders:
  # Standard deviation based
  - avg(total_amount):
      name: Average order amount check
      fail: when > 3 std_dev   # 3 sigma rule
      warn: when > 2 std_dev

checks for website_traffic:
  - anomaly detection for sum(page_views):
      name: Page views anomaly
      
  - anomaly detection for avg(session_duration):
      name: Session duration anomaly
```

### 5.9 Business Rules (`checks/custom/business_rules.yml`)

```yaml
# Business Rules Checks
# Custom business logic validation

checks for orders:
  # Order total must match items sum
  - failed rows:
      name: Order total consistency
      fail query: |
        SELECT order_id, total_amount, 
               (SELECT SUM(quantity * unit_price) FROM order_items WHERE order_id = orders.id) as calculated_total
        FROM orders
        WHERE total_amount != (SELECT SUM(quantity * unit_price) FROM order_items WHERE order_id = orders.id)
  
  # Discount validation
  - failed rows:
      name: Discount limit check
      fail query: |
        SELECT * FROM orders
        WHERE discount_percent > 50
  
  # Future date check
  - failed rows:
      name: No future orders
      fail query: |
        SELECT * FROM orders
        WHERE order_date > CURRENT_DATE

checks for customers:
  # Age validation
  - failed rows:
      name: Valid customer age
      fail query: |
        SELECT * FROM customers
        WHERE birth_date IS NOT NULL 
        AND (EXTRACT(YEAR FROM AGE(birth_date)) < 13 
             OR EXTRACT(YEAR FROM AGE(birth_date)) > 120)

checks for products:
  # Price consistency
  - failed rows:
      name: Sale price below regular price
      fail query: |
        SELECT * FROM products
        WHERE sale_price IS NOT NULL AND sale_price >= regular_price
```

### 5.10 Cross-Table Checks (`checks/custom/cross_table_checks.yml`)

```yaml
# Cross-Table Validation Checks

checks for orders:
  # Row count comparison
  - row_count same as order_items grouped by order_id:
      name: Order items exist for all orders
  
  # Aggregate comparison
  - failed rows:
      name: Order status timeline consistency
      fail query: |
        SELECT o.id, o.status, o.shipped_at, o.delivered_at
        FROM orders o
        WHERE (o.status = 'shipped' AND o.shipped_at IS NULL)
           OR (o.status = 'delivered' AND o.delivered_at IS NULL)
           OR (o.delivered_at IS NOT NULL AND o.shipped_at IS NULL)

# Inventory reconciliation
checks for inventory:
  - failed rows:
      name: Inventory quantity consistency
      fail query: |
        SELECT p.id, p.name, 
               i.quantity as current_inventory,
               (SELECT SUM(quantity) FROM stock_movements WHERE product_id = p.id) as calculated
        FROM products p
        JOIN inventory i ON p.id = i.product_id
        WHERE i.quantity != (SELECT COALESCE(SUM(quantity), 0) FROM stock_movements WHERE product_id = p.id)
```

### 5.11 Time-Series Checks (`checks/custom/timeseries_checks.yml`)

```yaml
# Time-Series Data Quality Checks

checks for daily_metrics:
  # No gaps in time series
  - failed rows:
      name: No missing dates in daily metrics
      fail query: |
        WITH date_range AS (
          SELECT generate_series(
            (SELECT MIN(metric_date) FROM daily_metrics),
            (SELECT MAX(metric_date) FROM daily_metrics),
            '1 day'::interval
          )::date as expected_date
        )
        SELECT expected_date 
        FROM date_range
        WHERE expected_date NOT IN (SELECT metric_date FROM daily_metrics)
  
  # Monotonic increase check (e.g., cumulative metrics)
  - failed rows:
      name: Cumulative revenue monotonic
      fail query: |
        SELECT d1.metric_date, d1.cumulative_revenue, d2.cumulative_revenue as previous
        FROM daily_metrics d1
        JOIN daily_metrics d2 ON d1.metric_date = d2.metric_date + 1
        WHERE d1.cumulative_revenue < d2.cumulative_revenue

checks for hourly_logs:
  # Hour coverage
  - failed rows:
      name: All hours covered
      fail query: |
        SELECT DISTINCT DATE(log_timestamp) as log_date
        FROM hourly_logs
        GROUP BY DATE(log_timestamp)
        HAVING COUNT(DISTINCT EXTRACT(HOUR FROM log_timestamp)) < 24
```

---

## 6. Python Integration

### 6.1 Basic Scanner (`scripts/soda_scanner.py`)

```python
"""
Soda Core Scanner for DataRover
Runs data quality checks and returns JSON results
"""

import json
import os
from datetime import datetime
from pathlib import Path
from soda.scan import Scan

class SodaScanner:
    def __init__(self, config_path: str = "config/configuration.yml"):
        self.config_path = config_path
        self.results_dir = Path("results")
        self.results_dir.mkdir(exist_ok=True)
        
    def run_scan(
        self, 
        data_source: str,
        checks_paths: list[str],
        scan_name: str = None
    ) -> dict:
        """
        Run Soda scan and return results as dict
        
        Args:
            data_source: Name of data source from config
            checks_paths: List of check YAML file paths
            scan_name: Optional name for the scan
            
        Returns:
            dict with scan results
        """
        scan = Scan()
        
        # Set scan definition name
        scan_name = scan_name or f"scan_{datetime.now().strftime('%Y%m%d_%H%M%S')}"
        scan.set_scan_definition_name(scan_name)
        
        # Set data source name
        scan.set_data_source_name(data_source)
        
        # Add configuration
        scan.add_configuration_yaml_file(self.config_path)
        
        # Add check files
        for check_path in checks_paths:
            if os.path.isfile(check_path):
                scan.add_sodacl_yaml_file(check_path)
            elif os.path.isdir(check_path):
                scan.add_sodacl_yaml_files(check_path)
        
        # Execute scan
        scan.execute()
        
        # Build results
        results = {
            "scan_name": scan_name,
            "data_source": data_source,
            "executed_at": datetime.now().isoformat(),
            "has_errors": scan.has_error_logs(),
            "has_warnings": scan.has_check_warns(),
            "has_failures": scan.has_check_fails(),
            "metrics": {},
            "checks": [],
            "logs": []
        }
        
        # Get scan results
        scan_results = scan.get_scan_results()
        
        # Extract metrics
        if hasattr(scan_results, 'metrics'):
            for metric in scan_results.metrics:
                key = f"{metric.table}.{metric.column}.{metric.name}" if metric.column else f"{metric.table}.{metric.name}"
                results["metrics"][key] = metric.value
        
        # Extract check results
        for check in scan.get_checks_fail() + scan.get_checks_warn() + scan.get_checks_pass():
            check_result = {
                "name": check.name,
                "table": check.table_name if hasattr(check, 'table_name') else None,
                "column": check.column_name if hasattr(check, 'column_name') else None,
                "outcome": check.outcome.value if hasattr(check.outcome, 'value') else str(check.outcome),
                "diagnostics": {}
            }
            
            # Add diagnostics if available
            if hasattr(check, 'diagnostics') and check.diagnostics:
                check_result["diagnostics"] = {
                    "value": check.diagnostics.get('value'),
                    "fail_threshold": check.diagnostics.get('fail'),
                    "warn_threshold": check.diagnostics.get('warn')
                }
            
            results["checks"].append(check_result)
        
        # Extract logs
        for log in scan.get_logs_text().split('\n'):
            if log.strip():
                results["logs"].append(log)
        
        # Calculate summary
        results["summary"] = {
            "total_checks": len(results["checks"]),
            "passed": len([c for c in results["checks"] if c["outcome"] == "pass"]),
            "warnings": len([c for c in results["checks"] if c["outcome"] == "warn"]),
            "failures": len([c for c in results["checks"] if c["outcome"] == "fail"]),
            "errors": len([c for c in results["checks"] if c["outcome"] == "error"])
        }
        
        # Calculate score
        total = results["summary"]["total_checks"]
        if total > 0:
            results["summary"]["score"] = round(
                (results["summary"]["passed"] / total) * 100, 2
            )
        else:
            results["summary"]["score"] = 100.0
        
        return results
    
    def save_results(self, results: dict, filename: str = None) -> str:
        """Save results to JSON file"""
        if not filename:
            filename = f"{results['scan_name']}.json"
        
        filepath = self.results_dir / filename
        
        with open(filepath, 'w') as f:
            json.dump(results, f, indent=2, default=str)
        
        # Also save as latest
        with open(self.results_dir / "latest.json", 'w') as f:
            json.dump(results, f, indent=2, default=str)
        
        return str(filepath)
    
    def run_all_checks(self, data_source: str) -> dict:
        """Run all available checks for a data source"""
        checks_dir = Path("checks")
        check_files = list(checks_dir.rglob("*.yml"))
        
        return self.run_scan(
            data_source=data_source,
            checks_paths=[str(f) for f in check_files],
            scan_name=f"full_scan_{data_source}"
        )


# Usage example
if __name__ == "__main__":
    scanner = SodaScanner()
    
    # Run scan
    results = scanner.run_scan(
        data_source="postgres_db",
        checks_paths=["checks/"],
        scan_name="daily_quality_check"
    )
    
    # Save results
    filepath = scanner.save_results(results)
    
    # Print summary
    print(f"\n{'='*50}")
    print(f"Scan: {results['scan_name']}")
    print(f"{'='*50}")
    print(f"Total Checks: {results['summary']['total_checks']}")
    print(f"✅ Passed: {results['summary']['passed']}")
    print(f"⚠️  Warnings: {results['summary']['warnings']}")
    print(f"❌ Failures: {results['summary']['failures']}")
    print(f"💯 Score: {results['summary']['score']}%")
    print(f"\nResults saved to: {filepath}")
```

### 6.2 API Integration (`scripts/api_integration.py`)

```python
"""
DataRover API Integration for Soda Core
Sends scan results to DataRover backend
"""

import json
import requests
from datetime import datetime
from typing import Optional
from soda_scanner import SodaScanner

class DataRoverIntegration:
    def __init__(self, api_base_url: str, api_key: Optional[str] = None):
        self.api_base_url = api_base_url.rstrip('/')
        self.api_key = api_key
        self.scanner = SodaScanner()
        
    def _get_headers(self) -> dict:
        headers = {"Content-Type": "application/json"}
        if self.api_key:
            headers["Authorization"] = f"Bearer {self.api_key}"
        return headers
    
    def run_and_send(
        self,
        data_source: str,
        source_id: int,
        checks_paths: list[str]
    ) -> dict:
        """
        Run scan and send results to DataRover API
        """
        # Run scan
        results = self.scanner.run_scan(
            data_source=data_source,
            checks_paths=checks_paths
        )
        
        # Transform to DataRover format
        payload = self._transform_results(results, source_id)
        
        # Send to API
        response = self._send_to_api(payload)
        
        return {
            "scan_results": results,
            "api_response": response
        }
    
    def _transform_results(self, results: dict, source_id: int) -> dict:
        """Transform Soda results to DataRover format"""
        
        # Map checks to quality rules format
        quality_results = []
        
        for check in results["checks"]:
            quality_results.append({
                "rule_name": check["name"],
                "table_name": check["table"],
                "column_name": check["column"],
                "status": self._map_outcome(check["outcome"]),
                "score": 100 if check["outcome"] == "pass" else (50 if check["outcome"] == "warn" else 0),
                "details": check.get("diagnostics", {}),
                "executed_at": results["executed_at"]
            })
        
        return {
            "source_id": source_id,
            "scan_name": results["scan_name"],
            "executed_at": results["executed_at"],
            "summary": results["summary"],
            "quality_score": results["summary"]["score"],
            "results": quality_results,
            "metrics": results["metrics"]
        }
    
    def _map_outcome(self, outcome: str) -> str:
        """Map Soda outcome to DataRover status"""
        mapping = {
            "pass": "passed",
            "warn": "warning",
            "fail": "failed",
            "error": "error"
        }
        return mapping.get(outcome, "unknown")
    
    def _send_to_api(self, payload: dict) -> dict:
        """Send results to DataRover API"""
        try:
            response = requests.post(
                f"{self.api_base_url}/api/quality_results",
                headers=self._get_headers(),
                json=payload,
                timeout=30
            )
            response.raise_for_status()
            return {"success": True, "data": response.json()}
        except requests.exceptions.RequestException as e:
            return {"success": False, "error": str(e)}
    
    def sync_rules_from_datarover(self) -> list:
        """
        Get quality rules from DataRover and create check files
        """
        try:
            response = requests.get(
                f"{self.api_base_url}/api/quality_rules",
                headers=self._get_headers(),
                timeout=30
            )
            response.raise_for_status()
            rules = response.json()
            
            # Generate YAML checks from rules
            checks = self._generate_checks_from_rules(rules)
            return checks
            
        except requests.exceptions.RequestException as e:
            print(f"Error fetching rules: {e}")
            return []
    
    def _generate_checks_from_rules(self, rules: list) -> list:
        """Generate Soda checks from DataRover rules"""
        checks = []
        
        for rule in rules:
            check = self._rule_to_check(rule)
            if check:
                checks.append(check)
        
        return checks
    
    def _rule_to_check(self, rule: dict) -> Optional[dict]:
        """Convert DataRover rule to Soda check format"""
        rule_type = rule.get("rule_type", "").lower()
        table = rule.get("table_name")
        column = rule.get("column_name")
        params = rule.get("parameters", {})
        
        if rule_type == "not_null":
            return {
                "table": table,
                "check": f"missing_count({column}) = 0",
                "name": rule.get("name")
            }
        
        elif rule_type == "unique":
            return {
                "table": table,
                "check": f"duplicate_count({column}) = 0",
                "name": rule.get("name")
            }
        
        elif rule_type == "min_value":
            min_val = params.get("min", 0)
            return {
                "table": table,
                "check": f"invalid_count({column}) = 0",
                "config": f"valid min: {min_val}",
                "name": rule.get("name")
            }
        
        elif rule_type == "max_value":
            max_val = params.get("max", 0)
            return {
                "table": table,
                "check": f"invalid_count({column}) = 0",
                "config": f"valid max: {max_val}",
                "name": rule.get("name")
            }
        
        elif rule_type == "regex":
            pattern = params.get("pattern", "")
            return {
                "table": table,
                "check": f"invalid_count({column}) = 0",
                "config": f"valid regex: '{pattern}'",
                "name": rule.get("name")
            }
        
        elif rule_type == "row_count":
            min_rows = params.get("min", 0)
            return {
                "table": table,
                "check": f"row_count >= {min_rows}",
                "name": rule.get("name")
            }
        
        elif rule_type == "freshness":
            hours = params.get("hours", 24)
            return {
                "table": table,
                "check": f"freshness({column}) < {hours}h",
                "name": rule.get("name")
            }
        
        elif rule_type == "custom_sql":
            sql = params.get("sql", "")
            return {
                "table": table,
                "check": "failed rows",
                "config": f"fail query: |\n  {sql}",
                "name": rule.get("name")
            }
        
        return None


# Usage
if __name__ == "__main__":
    integration = DataRoverIntegration(
        api_base_url="http://localhost/datarover/backend.php",
        api_key=None  # Add if using authentication
    )
    
    # Run scan and send results
    result = integration.run_and_send(
        data_source="postgres_db",
        source_id=1,
        checks_paths=["checks/"]
    )
    
    print(json.dumps(result, indent=2))
```

### 6.3 FastAPI Service (`scripts/soda_service.py`)

```python
"""
Soda Core Microservice for DataRover
REST API for running quality scans
"""

from fastapi import FastAPI, HTTPException, BackgroundTasks
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
from typing import Optional, List
import asyncio
from datetime import datetime
import json
from pathlib import Path

from soda_scanner import SodaScanner

app = FastAPI(
    title="DataRover Soda Service",
    description="Data Quality Scanning Service using Soda Core",
    version="1.0.0"
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

scanner = SodaScanner()

# Store for background scan status
scan_status = {}


class ScanRequest(BaseModel):
    data_source: str
    check_files: Optional[List[str]] = None
    tables: Optional[List[str]] = None
    scan_name: Optional[str] = None


class ScanResponse(BaseModel):
    scan_id: str
    status: str
    message: str


@app.get("/")
async def root():
    return {
        "service": "DataRover Soda Service",
        "version": "1.0.0",
        "status": "running"
    }


@app.get("/health")
async def health():
    return {"status": "healthy", "timestamp": datetime.now().isoformat()}


@app.post("/scan", response_model=ScanResponse)
async def run_scan(request: ScanRequest, background_tasks: BackgroundTasks):
    """
    Start a data quality scan
    """
    scan_id = f"scan_{datetime.now().strftime('%Y%m%d_%H%M%S')}"
    
    # Initialize scan status
    scan_status[scan_id] = {
        "status": "running",
        "started_at": datetime.now().isoformat(),
        "results": None
    }
    
    # Run scan in background
    background_tasks.add_task(
        execute_scan,
        scan_id,
        request.data_source,
        request.check_files or ["checks/"],
        request.scan_name
    )
    
    return ScanResponse(
        scan_id=scan_id,
        status="started",
        message="Scan started in background"
    )


async def execute_scan(scan_id: str, data_source: str, check_files: list, scan_name: str):
    """Execute scan in background"""
    try:
        results = scanner.run_scan(
            data_source=data_source,
            checks_paths=check_files,
            scan_name=scan_name or scan_id
        )
        
        scanner.save_results(results, f"{scan_id}.json")
        
        scan_status[scan_id] = {
            "status": "completed",
            "started_at": scan_status[scan_id]["started_at"],
            "completed_at": datetime.now().isoformat(),
            "results": results
        }
        
    except Exception as e:
        scan_status[scan_id] = {
            "status": "failed",
            "started_at": scan_status[scan_id]["started_at"],
            "error": str(e)
        }


@app.get("/scan/{scan_id}")
async def get_scan_status(scan_id: str):
    """Get status of a scan"""
    if scan_id not in scan_status:
        raise HTTPException(status_code=404, detail="Scan not found")
    
    return scan_status[scan_id]


@app.get("/scan/{scan_id}/results")
async def get_scan_results(scan_id: str):
    """Get full results of a completed scan"""
    if scan_id not in scan_status:
        raise HTTPException(status_code=404, detail="Scan not found")
    
    status = scan_status[scan_id]
    
    if status["status"] != "completed":
        raise HTTPException(
            status_code=400, 
            detail=f"Scan is {status['status']}, results not available"
        )
    
    return status["results"]


@app.get("/results/latest")
async def get_latest_results():
    """Get the latest scan results"""
    latest_file = Path("results/latest.json")
    
    if not latest_file.exists():
        raise HTTPException(status_code=404, detail="No results available")
    
    with open(latest_file) as f:
        return json.load(f)


@app.get("/results")
async def list_results(limit: int = 10):
    """List available scan results"""
    results_dir = Path("results/history")
    
    if not results_dir.exists():
        return {"results": []}
    
    files = sorted(results_dir.glob("*.json"), reverse=True)[:limit]
    
    results = []
    for f in files:
        with open(f) as file:
            data = json.load(file)
            results.append({
                "filename": f.name,
                "scan_name": data.get("scan_name"),
                "executed_at": data.get("executed_at"),
                "score": data.get("summary", {}).get("score")
            })
    
    return {"results": results}


@app.get("/checks")
async def list_available_checks():
    """List all available check files"""
    checks_dir = Path("checks")
    
    if not checks_dir.exists():
        return {"checks": []}
    
    checks = []
    for f in checks_dir.rglob("*.yml"):
        checks.append({
            "path": str(f.relative_to(checks_dir)),
            "name": f.stem
        })
    
    return {"checks": checks}


@app.post("/checks/validate")
async def validate_checks(check_content: dict):
    """Validate check YAML syntax"""
    try:
        # Basic validation
        import yaml
        yaml.safe_load(json.dumps(check_content))
        return {"valid": True}
    except Exception as e:
        return {"valid": False, "error": str(e)}


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=8001)
```

---

## 7. CLI Commands

### 7.1 Basic CLI Usage

```bash
# Run scan with specific data source and checks
soda scan -d postgres_db -c config/configuration.yml checks/common/

# Run specific check file
soda scan -d postgres_db -c config/configuration.yml checks/tables/customers_checks.yml

# Run with verbose output
soda scan -d postgres_db -c config/configuration.yml checks/ -V

# Run with specific variables
soda scan -d postgres_db -c config/configuration.yml checks/ \
  -v MIN_ROWS=1000 \
  -v MAX_AGE_HOURS=24

# Output to JSON (pipe to file)
soda scan -d postgres_db -c config/configuration.yml checks/ 2>&1 | tee results/scan_output.log
```

### 7.2 Shell Script Runner (`scripts/run_scan.sh`)

```bash
#!/bin/bash

# DataRover Soda Scanner Script
# Usage: ./run_scan.sh [data_source] [check_path]

set -e

# Configuration
CONFIG_FILE="config/configuration.yml"
RESULTS_DIR="results"
LOG_DIR="logs"
DATA_SOURCE="${1:-postgres_db}"
CHECK_PATH="${2:-checks/}"

# Create directories
mkdir -p "$RESULTS_DIR/history" "$LOG_DIR"

# Timestamp
TIMESTAMP=$(date +"%Y-%m-%d_%H-%M-%S")
LOG_FILE="$LOG_DIR/soda_${TIMESTAMP}.log"
RESULT_FILE="$RESULTS_DIR/history/${TIMESTAMP}.json"

echo "=============================================="
echo "DataRover Soda Scanner"
echo "=============================================="
echo "Data Source: $DATA_SOURCE"
echo "Check Path: $CHECK_PATH"
echo "Timestamp: $TIMESTAMP"
echo "=============================================="

# Run scan
echo "Starting scan..."
soda scan \
    -d "$DATA_SOURCE" \
    -c "$CONFIG_FILE" \
    "$CHECK_PATH" \
    -V 2>&1 | tee "$LOG_FILE"

# Check exit status
SCAN_STATUS=$?

if [ $SCAN_STATUS -eq 0 ]; then
    echo "✅ Scan completed successfully"
elif [ $SCAN_STATUS -eq 1 ]; then
    echo "⚠️  Scan completed with warnings"
elif [ $SCAN_STATUS -eq 2 ]; then
    echo "❌ Scan completed with failures"
else
    echo "💥 Scan failed with errors"
fi

# Run Python post-processor to generate JSON
python3 scripts/process_results.py "$LOG_FILE" "$RESULT_FILE"

echo ""
echo "Results saved to: $RESULT_FILE"
echo "Log saved to: $LOG_FILE"
echo ""

exit $SCAN_STATUS
```

---

## 8. Production Workflow

### 8.1 CRON Job Setup

```bash
# Edit crontab
crontab -e

# Add following entries:

# Daily full scan at 6:00 AM
0 6 * * * /opt/datarover/soda/scripts/run_scan.sh postgres_db checks/ >> /var/log/soda/daily.log 2>&1

# Hourly critical checks
0 * * * * /opt/datarover/soda/scripts/run_scan.sh postgres_db checks/critical/ >> /var/log/soda/hourly.log 2>&1

# Weekly comprehensive scan on Sunday at 2:00 AM
0 2 * * 0 /opt/datarover/soda/scripts/run_scan.sh postgres_db checks/ --full >> /var/log/soda/weekly.log 2>&1
```

### 8.2 Docker Setup (`Dockerfile`)

```dockerfile
FROM python:3.11-slim

WORKDIR /app

# Install dependencies
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Copy application
COPY . .

# Create directories
RUN mkdir -p results/history logs

# Environment variables
ENV PYTHONUNBUFFERED=1
ENV SODA_CONFIG=/app/config/configuration.yml

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD python -c "from soda.scan import Scan; print('healthy')" || exit 1

# Default command
CMD ["python", "scripts/soda_service.py"]
```

### 8.3 Docker Compose (`docker-compose.yml`)

```yaml
version: '3.8'

services:
  soda-scanner:
    build: .
    container_name: datarover-soda
    ports:
      - "8001:8001"
    environment:
      - POSTGRES_HOST=db
      - POSTGRES_USER=${POSTGRES_USER}
      - POSTGRES_PASSWORD=${POSTGRES_PASSWORD}
      - MYSQL_HOST=mysql
      - MYSQL_USER=${MYSQL_USER}
      - MYSQL_PASSWORD=${MYSQL_PASSWORD}
    volumes:
      - ./config:/app/config:ro
      - ./checks:/app/checks:ro
      - ./results:/app/results
      - ./logs:/app/logs
    networks:
      - datarover-network
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8001/health"]
      interval: 30s
      timeout: 10s
      retries: 3

  # Scheduler for periodic scans
  soda-scheduler:
    build: .
    container_name: datarover-soda-scheduler
    command: ["python", "scripts/scheduler.py"]
    environment:
      - POSTGRES_HOST=db
      - POSTGRES_USER=${POSTGRES_USER}
      - POSTGRES_PASSWORD=${POSTGRES_PASSWORD}
    volumes:
      - ./config:/app/config:ro
      - ./checks:/app/checks:ro
      - ./results:/app/results
      - ./logs:/app/logs
    networks:
      - datarover-network
    restart: unless-stopped

networks:
  datarover-network:
    external: true
```

### 8.4 GitHub Actions CI/CD (`.github/workflows/data-quality.yml`)

```yaml
name: Data Quality Checks

on:
  # Run on schedule
  schedule:
    - cron: '0 6 * * *'  # Daily at 6 AM UTC
  
  # Run on push to main
  push:
    branches: [main]
    paths:
      - 'checks/**'
      - 'config/**'
  
  # Manual trigger
  workflow_dispatch:
    inputs:
      data_source:
        description: 'Data source to scan'
        required: true
        default: 'postgres_db'
      check_path:
        description: 'Check files path'
        required: true
        default: 'checks/'

env:
  POSTGRES_USER: ${{ secrets.POSTGRES_USER }}
  POSTGRES_PASSWORD: ${{ secrets.POSTGRES_PASSWORD }}

jobs:
  data-quality-scan:
    runs-on: ubuntu-latest
    
    steps:
      - name: Checkout repository
        uses: actions/checkout@v4
      
      - name: Set up Python
        uses: actions/setup-python@v5
        with:
          python-version: '3.11'
      
      - name: Install dependencies
        run: |
          pip install soda-core-postgres soda-core-mysql
          pip install -r requirements.txt
      
      - name: Run Soda scan
        id: soda-scan
        run: |
          DATA_SOURCE="${{ github.event.inputs.data_source || 'postgres_db' }}"
          CHECK_PATH="${{ github.event.inputs.check_path || 'checks/' }}"
          
          soda scan \
            -d "$DATA_SOURCE" \
            -c config/configuration.yml \
            "$CHECK_PATH" \
            -V
        continue-on-error: true
      
      - name: Upload results
        uses: actions/upload-artifact@v4
        with:
          name: soda-results-${{ github.run_id }}
          path: results/
      
      - name: Send notification on failure
        if: steps.soda-scan.outcome == 'failure'
        uses: slackapi/slack-github-action@v1.24.0
        with:
          channel-id: ${{ secrets.SLACK_CHANNEL_ID }}
          slack-message: |
            ❌ Data Quality Check Failed!
            
            Repository: ${{ github.repository }}
            Workflow: ${{ github.workflow }}
            Run: ${{ github.run_number }}
            
            Check the results: ${{ github.server_url }}/${{ github.repository }}/actions/runs/${{ github.run_id }}
        env:
          SLACK_BOT_TOKEN: ${{ secrets.SLACK_BOT_TOKEN }}
      
      - name: Check for failures
        if: steps.soda-scan.outcome == 'failure'
        run: exit 1
```

### 8.5 Alerting Script (`scripts/alerting.py`)

```python
"""
Alerting for Soda Scan Results
Supports Slack, Email, and Webhook notifications
"""

import json
import os
import smtplib
from email.mime.text import MIMEText
from email.mime.multipart import MIMEMultipart
from datetime import datetime
import requests
from typing import Optional


class AlertManager:
    def __init__(self):
        self.slack_webhook = os.getenv("SLACK_WEBHOOK_URL")
        self.email_config = {
            "smtp_host": os.getenv("SMTP_HOST", "smtp.gmail.com"),
            "smtp_port": int(os.getenv("SMTP_PORT", 587)),
            "smtp_user": os.getenv("SMTP_USER"),
            "smtp_password": os.getenv("SMTP_PASSWORD"),
            "from_email": os.getenv("ALERT_FROM_EMAIL"),
            "to_emails": os.getenv("ALERT_TO_EMAILS", "").split(",")
        }
        self.webhook_url = os.getenv("ALERT_WEBHOOK_URL")
    
    def process_results(self, results: dict):
        """Process scan results and send alerts if needed"""
        summary = results.get("summary", {})
        
        # Determine alert level
        if summary.get("failures", 0) > 0:
            self.send_alert("critical", results)
        elif summary.get("warnings", 0) > 0:
            self.send_alert("warning", results)
        elif summary.get("score", 100) < 90:
            self.send_alert("info", results)
    
    def send_alert(self, level: str, results: dict):
        """Send alerts to all configured channels"""
        
        # Send Slack notification
        if self.slack_webhook:
            self._send_slack(level, results)
        
        # Send email
        if self.email_config["smtp_user"]:
            self._send_email(level, results)
        
        # Send webhook
        if self.webhook_url:
            self._send_webhook(level, results)
    
    def _send_slack(self, level: str, results: dict):
        """Send Slack notification"""
        summary = results.get("summary", {})
        
        emoji = {"critical": "🚨", "warning": "⚠️", "info": "ℹ️"}.get(level, "📊")
        color = {"critical": "#ff0000", "warning": "#ffcc00", "info": "#0088ff"}.get(level, "#888888")
        
        # Build failed checks list
        failed_checks = [c for c in results.get("checks", []) if c["outcome"] in ["fail", "error"]]
        failed_list = "\n".join([f"• {c['name']}" for c in failed_checks[:5]])
        if len(failed_checks) > 5:
            failed_list += f"\n... and {len(failed_checks) - 5} more"
        
        payload = {
            "attachments": [{
                "color": color,
                "blocks": [
                    {
                        "type": "header",
                        "text": {
                            "type": "plain_text",
                            "text": f"{emoji} Data Quality Alert - {level.upper()}"
                        }
                    },
                    {
                        "type": "section",
                        "fields": [
                            {"type": "mrkdwn", "text": f"*Scan:*\n{results.get('scan_name', 'Unknown')}"},
                            {"type": "mrkdwn", "text": f"*Score:*\n{summary.get('score', 0)}%"},
                            {"type": "mrkdwn", "text": f"*Passed:*\n{summary.get('passed', 0)}"},
                            {"type": "mrkdwn", "text": f"*Failed:*\n{summary.get('failures', 0)}"}
                        ]
                    }
                ]
            }]
        }
        
        if failed_list:
            payload["attachments"][0]["blocks"].append({
                "type": "section",
                "text": {
                    "type": "mrkdwn",
                    "text": f"*Failed Checks:*\n{failed_list}"
                }
            })
        
        try:
            requests.post(self.slack_webhook, json=payload, timeout=10)
        except Exception as e:
            print(f"Failed to send Slack alert: {e}")
    
    def _send_email(self, level: str, results: dict):
        """Send email notification"""
        summary = results.get("summary", {})
        
        subject = f"[{level.upper()}] Data Quality Alert - Score: {summary.get('score', 0)}%"
        
        # Build HTML body
        failed_checks = [c for c in results.get("checks", []) if c["outcome"] in ["fail", "error"]]
        
        body = f"""
        <html>
        <body style="font-family: Arial, sans-serif;">
            <h2>Data Quality Scan Results</h2>
            
            <table style="border-collapse: collapse; margin: 20px 0;">
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd;"><strong>Scan Name:</strong></td>
                    <td style="padding: 10px; border: 1px solid #ddd;">{results.get('scan_name', 'Unknown')}</td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd;"><strong>Executed At:</strong></td>
                    <td style="padding: 10px; border: 1px solid #ddd;">{results.get('executed_at', '')}</td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd;"><strong>Score:</strong></td>
                    <td style="padding: 10px; border: 1px solid #ddd;">{summary.get('score', 0)}%</td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd;"><strong>Total Checks:</strong></td>
                    <td style="padding: 10px; border: 1px solid #ddd;">{summary.get('total_checks', 0)}</td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd;"><strong>Passed:</strong></td>
                    <td style="padding: 10px; border: 1px solid #ddd; color: green;">{summary.get('passed', 0)}</td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd;"><strong>Failed:</strong></td>
                    <td style="padding: 10px; border: 1px solid #ddd; color: red;">{summary.get('failures', 0)}</td>
                </tr>
            </table>
            
            {"<h3>Failed Checks:</h3><ul>" + "".join([f"<li>{c['name']}</li>" for c in failed_checks]) + "</ul>" if failed_checks else ""}
            
            <p style="color: #888; font-size: 12px;">
                This is an automated alert from DataRover Data Quality System.
            </p>
        </body>
        </html>
        """
        
        try:
            msg = MIMEMultipart('alternative')
            msg['Subject'] = subject
            msg['From'] = self.email_config["from_email"]
            msg['To'] = ", ".join(self.email_config["to_emails"])
            
            msg.attach(MIMEText(body, 'html'))
            
            with smtplib.SMTP(self.email_config["smtp_host"], self.email_config["smtp_port"]) as server:
                server.starttls()
                server.login(self.email_config["smtp_user"], self.email_config["smtp_password"])
                server.sendmail(
                    self.email_config["from_email"],
                    self.email_config["to_emails"],
                    msg.as_string()
                )
        except Exception as e:
            print(f"Failed to send email alert: {e}")
    
    def _send_webhook(self, level: str, results: dict):
        """Send webhook notification"""
        payload = {
            "level": level,
            "timestamp": datetime.now().isoformat(),
            "scan_name": results.get("scan_name"),
            "summary": results.get("summary"),
            "failed_checks": [c for c in results.get("checks", []) if c["outcome"] in ["fail", "error"]]
        }
        
        try:
            requests.post(self.webhook_url, json=payload, timeout=10)
        except Exception as e:
            print(f"Failed to send webhook alert: {e}")


# Usage
if __name__ == "__main__":
    with open("results/latest.json") as f:
        results = json.load(f)
    
    alert_manager = AlertManager()
    alert_manager.process_results(results)
```

---

## 9. Performance Optimization

### 9.1 Best Practices

```yaml
# 1. Sampling for large tables
checks for huge_table:
  - row_count > 0:
      samples limit: 10000  # Sample 10K rows

# 2. Partitioned scans
checks for partitioned_table:
  - row_count > 0:
      filter: partition_date >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY)

# 3. Parallel execution (via multiple scan calls)
# Split checks into separate files and run in parallel

# 4. Targeted columns (don't scan entire table)
checks for wide_table:
  - missing_count(critical_column_1) = 0
  - missing_count(critical_column_2) = 0
  # Instead of full schema check
```

### 9.2 Configuration Optimization (`config/performance.yml`)

```yaml
# Performance tuning options
data_source optimized_postgres:
  type: postgres
  host: localhost
  database: datarover
  
  # Connection pooling
  connection_timeout: 60
  
  # Query optimization
  query_timeout: 300  # 5 minutes max per query
  
  # Sampling configuration
  sampler:
    samples_limit: 100000  # Max rows to sample
    
# Scan-level settings  
scan:
  # Parallel check execution
  max_workers: 4
  
  # Memory management
  batch_size: 10000
  
  # Result limits
  failed_rows_limit: 100
```

### 9.3 Parallel Scan Script (`scripts/parallel_scan.py`)

```python
"""
Parallel Soda Scanner
Runs multiple scans concurrently for better performance
"""

import asyncio
import json
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path
from datetime import datetime
from soda_scanner import SodaScanner


class ParallelScanner:
    def __init__(self, max_workers: int = 4):
        self.max_workers = max_workers
        self.scanner = SodaScanner()
    
    def run_parallel_scans(
        self,
        data_source: str,
        check_groups: list[list[str]]
    ) -> dict:
        """
        Run multiple check groups in parallel
        
        Args:
            data_source: Data source name
            check_groups: List of check file groups to run in parallel
        
        Returns:
            Combined results from all scans
        """
        all_results = {
            "scan_name": f"parallel_scan_{datetime.now().strftime('%Y%m%d_%H%M%S')}",
            "data_source": data_source,
            "executed_at": datetime.now().isoformat(),
            "checks": [],
            "metrics": {},
            "summary": {
                "total_checks": 0,
                "passed": 0,
                "warnings": 0,
                "failures": 0,
                "errors": 0
            }
        }
        
        with ThreadPoolExecutor(max_workers=self.max_workers) as executor:
            futures = {
                executor.submit(
                    self.scanner.run_scan,
                    data_source,
                    checks,
                    f"group_{i}"
                ): i for i, checks in enumerate(check_groups)
            }
            
            for future in as_completed(futures):
                try:
                    result = future.result()
                    self._merge_results(all_results, result)
                except Exception as e:
                    print(f"Scan failed: {e}")
        
        # Recalculate score
        total = all_results["summary"]["total_checks"]
        if total > 0:
            all_results["summary"]["score"] = round(
                (all_results["summary"]["passed"] / total) * 100, 2
            )
        else:
            all_results["summary"]["score"] = 100.0
        
        return all_results
    
    def _merge_results(self, all_results: dict, new_results: dict):
        """Merge new results into combined results"""
        all_results["checks"].extend(new_results.get("checks", []))
        all_results["metrics"].update(new_results.get("metrics", {}))
        
        for key in ["total_checks", "passed", "warnings", "failures", "errors"]:
            all_results["summary"][key] += new_results.get("summary", {}).get(key, 0)


# Usage
if __name__ == "__main__":
    scanner = ParallelScanner(max_workers=4)
    
    # Split checks into groups
    check_groups = [
        ["checks/common/schema_checks.yml"],
        ["checks/common/null_checks.yml"],
        ["checks/common/uniqueness_checks.yml"],
        ["checks/custom/business_rules.yml"]
    ]
    
    results = scanner.run_parallel_scans("postgres_db", check_groups)
    
    # Save results
    with open("results/parallel_results.json", "w") as f:
        json.dump(results, f, indent=2)
    
    print(f"Score: {results['summary']['score']}%")
```

---

## 10. Troubleshooting

### Common Issues

```bash
# 1. Connection errors
# Check connectivity
soda test-connection -d postgres_db -c config/configuration.yml

# 2. YAML syntax errors
# Validate YAML
python -c "import yaml; yaml.safe_load(open('checks/my_checks.yml'))"

# 3. Permission errors
# Ensure database user has SELECT permissions
GRANT SELECT ON ALL TABLES IN SCHEMA public TO soda_user;

# 4. Memory issues with large tables
# Use sampling
checks for large_table:
  - row_count > 0:
      samples limit: 10000

# 5. Timeout errors
# Increase timeout in configuration
data_source my_db:
  type: postgres
  connection_timeout: 120
```

---

## 11. Quick Reference Card

```
╔════════════════════════════════════════════════════════════════╗
║                    SODA CORE QUICK REFERENCE                    ║
╠════════════════════════════════════════════════════════════════╣
║                                                                  ║
║  INSTALLATION:                                                   ║
║    pip install soda-core-postgres soda-core-mysql               ║
║                                                                  ║
║  RUN SCAN:                                                       ║
║    soda scan -d <datasource> -c config.yml checks/              ║
║                                                                  ║
║  TEST CONNECTION:                                                ║
║    soda test-connection -d <datasource> -c config.yml           ║
║                                                                  ║
║  COMMON CHECKS:                                                  ║
║    - row_count > 0                                              ║
║    - missing_count(col) = 0                                     ║
║    - duplicate_count(col) = 0                                   ║
║    - invalid_count(col) = 0                                     ║
║    - freshness(date_col) < 24h                                  ║
║    - schema (column existence)                                  ║
║                                                                  ║
║  CHECK OUTCOMES:                                                 ║
║    ✅ pass    - Check succeeded                                 ║
║    ⚠️  warn    - Warning threshold exceeded                     ║
║    ❌ fail    - Failure threshold exceeded                      ║
║    💥 error   - Execution error                                 ║
║                                                                  ║
║  EXIT CODES:                                                     ║
║    0 - All checks passed                                        ║
║    1 - Warnings present                                         ║
║    2 - Failures present                                         ║
║    3 - Errors occurred                                          ║
║                                                                  ║
╚════════════════════════════════════════════════════════════════╝
```

---

## 12. Integration with DataRover UI

DataRover frontend-də Quality modulunda Soda Core nəticələrini göstərmək üçün:

1. **Soda Service** (port 8001) işə salın
2. **Backend API**-yə endpoint əlavə edin:
   - `GET /api/quality/scan/{source_id}` - Scan başlat
   - `GET /api/quality/results` - Nəticələri al
3. **Frontend**-də nəticələri göstərin

Bu sənəddə olan bütün faylları layihə strukturuna görə yerləşdirin.
