"""
DataRover Soda Core Scanner
"""

import os
import sys

# Set TNS_ADMIN for Oracle (required for Soda Core Oracle connector)
os.environ['TNS_ADMIN'] = r"C:\oracle\network\admin"

import json
from datetime import datetime
from typing import Dict, List, Optional
import yaml

# Import Soda Core
try:
    from soda.scan import Scan
    SODA_AVAILABLE = True
except ImportError:
    SODA_AVAILABLE = False


def fetch_failed_rows(config_file: str, sql: str, db_type: str, limit: int = 100) -> Dict:
    """Fetch actual failed rows from database using the SQL query"""
    
    if not sql:
        return {"rows": [], "total_count": 0, "error": "No SQL provided"}
    
    try:
        # Parse config file to get connection details
        with open(config_file, 'r') as f:
            config = yaml.safe_load(f)
        
        # Find data source config
        ds_config = None
        for key, value in config.items():
            if key.startswith('data_source'):
                ds_config = value
                break
        
        if not ds_config:
            return {"rows": [], "total_count": 0, "error": "No data source config found"}
        
        db_type = ds_config.get('type', 'mysql').lower()
        rows = []
        total_count = 0
        
        if db_type == 'oracle':
            try:
                import oracledb
                
                connect_string = ds_config.get('connect_string', 'localhost:1521/orcl')
                username = ds_config.get('username', '')
                password = ds_config.get('password', '')
                
                conn = oracledb.connect(user=username, password=password, dsn=connect_string)
                cursor = conn.cursor()
                
                # Get total count
                try:
                    count_sql = f"SELECT COUNT(*) FROM ({sql})"
                    cursor.execute(count_sql)
                    total_count = cursor.fetchone()[0]
                except:
                    pass
                
                # Get sample rows with limit
                limited_sql = f"SELECT * FROM ({sql}) WHERE ROWNUM <= {limit}"
                cursor.execute(limited_sql)
                
                # Get column names
                columns = [col[0] for col in cursor.description]
                
                # Fetch rows in batches using fetchmany
                batch_size = 100
                while True:
                    batch = cursor.fetchmany(batch_size)
                    if not batch:
                        break
                    for row in batch:
                        row_dict = {}
                        for i, col in enumerate(columns):
                            val = row[i]
                            # Convert to JSON-serializable
                            if val is None:
                                row_dict[col] = None
                            elif isinstance(val, (datetime,)):
                                row_dict[col] = val.isoformat()
                            elif isinstance(val, bytes):
                                row_dict[col] = val.decode('utf-8', errors='replace')
                            else:
                                row_dict[col] = str(val) if not isinstance(val, (int, float, bool)) else val
                        rows.append(row_dict)
                    if len(rows) >= limit:
                        break
                
                cursor.close()
                conn.close()
                
            except Exception as e:
                return {"rows": [], "total_count": 0, "error": f"Oracle error: {str(e)}"}
        
        elif db_type in ('mysql', 'postgresql', 'postgres'):
            try:
                if db_type == 'mysql':
                    import mysql.connector
                    conn = mysql.connector.connect(
                        host=ds_config.get('host', 'localhost'),
                        port=int(ds_config.get('port', 3306)),
                        user=ds_config.get('username', ''),
                        password=ds_config.get('password', ''),
                        database=ds_config.get('database', '')
                    )
                else:
                    import psycopg2
                    conn = psycopg2.connect(
                        host=ds_config.get('host', 'localhost'),
                        port=int(ds_config.get('port', 5432)),
                        user=ds_config.get('username', ''),
                        password=ds_config.get('password', ''),
                        dbname=ds_config.get('database', '')
                    )
                
                cursor = conn.cursor()
                
                # Get total count
                try:
                    count_sql = f"SELECT COUNT(*) FROM ({sql}) as subq"
                    cursor.execute(count_sql)
                    total_count = cursor.fetchone()[0]
                except:
                    pass
                
                # Get sample rows
                limited_sql = f"{sql} LIMIT {limit}"
                cursor.execute(limited_sql)
                
                columns = [col[0] for col in cursor.description]
                
                # Fetch rows in batches using fetchmany
                batch_size = 100
                while True:
                    batch = cursor.fetchmany(batch_size)
                    if not batch:
                        break
                    for row in batch:
                        row_dict = {}
                        for i, col in enumerate(columns):
                            val = row[i]
                            if val is None:
                                row_dict[col] = None
                            elif isinstance(val, (datetime,)):
                                row_dict[col] = val.isoformat()
                            elif isinstance(val, bytes):
                                row_dict[col] = val.decode('utf-8', errors='replace')
                            else:
                                row_dict[col] = str(val) if not isinstance(val, (int, float, bool)) else val
                        rows.append(row_dict)
                    if len(rows) >= limit:
                        break
                
                cursor.close()
                conn.close()
                
            except Exception as e:
                return {"rows": [], "total_count": 0, "error": f"DB error: {str(e)}"}
        
        return {
            "rows": rows,
            "total_count": total_count if total_count > 0 else len(rows),
            "sample_size": len(rows)
        }
        
    except Exception as e:
        return {"rows": [], "total_count": 0, "error": str(e)}


class SodaScanner:
    def __init__(self, config_path: str = None):
        self.config_path = config_path
        
    def run_scan_with_files(self, data_source: str, config_file: str, check_file: str) -> Dict:
        """Run a scan using provided config and check files"""
        if not SODA_AVAILABLE:
            return {"success": False, "outcome": "error", "error": "soda-core not installed", "value": None}
        
        if not os.path.exists(config_file):
            return {"success": False, "outcome": "error", "error": f"Config file not found: {config_file}", "value": None}
        
        if not os.path.exists(check_file):
            return {"success": False, "outcome": "error", "error": f"Check file not found: {check_file}", "value": None}
        
        try:
            scan = Scan()
            scan.set_scan_definition_name(f"check_{datetime.now().strftime('%Y%m%d_%H%M%S')}")
            scan.set_data_source_name(data_source)
            scan.add_configuration_yaml_file(config_file)
            scan.add_sodacl_yaml_file(check_file)
            
            exit_code = scan.execute()
            
            result = {
                "success": True,
                "outcome": "pass" if exit_code == 0 else "fail",
                "executed_at": datetime.now().isoformat(),
                "value": None,
                "message": "",
                "exit_code": exit_code,
                "failed_rows": [],
                "failed_rows_count": 0
            }
            
            # Get check results
            try:
                checks_fail = scan.get_checks_fail() if hasattr(scan, 'get_checks_fail') else []
                checks_warn = scan.get_checks_warn() if hasattr(scan, 'get_checks_warn') else []
                checks_pass = scan.get_checks_pass() if hasattr(scan, 'get_checks_pass') else []
                
                if checks_fail:
                    result["outcome"] = "fail"
                    check_obj = checks_fail[0]
                    result["message"] = f"FAIL: {getattr(check_obj, 'name', str(check_obj))}"
                    
                    # Try multiple ways to get failed rows count
                    try:
                        # Method 1: Direct attribute
                        if hasattr(check_obj, 'fail_count') and check_obj.fail_count:
                            result["failed_rows_count"] = check_obj.fail_count
                            result["value"] = check_obj.fail_count
                        
                        # Method 2: Check result
                        if hasattr(check_obj, 'check_result') and check_obj.check_result:
                            cr = check_obj.check_result
                            if hasattr(cr, 'fail_count') and cr.fail_count:
                                result["failed_rows_count"] = cr.fail_count
                                result["value"] = cr.fail_count
                        
                        # Method 3: Diagnostics
                        if hasattr(check_obj, 'diagnostics') and check_obj.diagnostics:
                            diag = check_obj.diagnostics
                            for key in ['fail_count', 'failed_rows_count', 'value', 'rows_count']:
                                if hasattr(diag, key):
                                    val = getattr(diag, key)
                                    if val is not None:
                                        result["failed_rows_count"] = val
                                        result["value"] = val
                                        break
                        
                        # Method 4: Metric value
                        if hasattr(check_obj, 'metric') and check_obj.metric:
                            if hasattr(check_obj.metric, 'value') and check_obj.metric.value is not None:
                                result["value"] = check_obj.metric.value
                                result["failed_rows_count"] = check_obj.metric.value
                        
                        # Method 5: outcome_reasons
                        if hasattr(check_obj, 'outcome_reasons') and check_obj.outcome_reasons:
                            result["outcome_reasons"] = str(check_obj.outcome_reasons)
                            
                    except Exception as e:
                        result["diag_error"] = str(e)
                        
                elif checks_warn:
                    result["outcome"] = "warn"
                    result["message"] = f"WARN: {getattr(checks_warn[0], 'name', str(checks_warn[0]))}"
                elif checks_pass:
                    result["outcome"] = "pass"
                    result["message"] = f"PASS: {getattr(checks_pass[0], 'name', str(checks_pass[0]))}"
            except Exception as e:
                result["check_error"] = str(e)
            
            # Extract value and failed rows count from logs
            try:
                import re
                logs_text = scan.get_logs_text() or ""
                result["logs"] = logs_text[-3000:] if len(logs_text) > 3000 else logs_text  # Last 3000 chars
                
                for line in logs_text.split('\n'):
                    line_lower = line.lower()
                    line_stripped = line.strip()
                    
                    # Extract "value: N" format (Soda Core standard output)
                    # Example: "INFO  |         value: 7622"
                    if 'value:' in line_lower:
                        match = re.search(r'value:\s*(\d+)', line_lower)
                        if match:
                            val = int(match.group(1))
                            if val > 0:
                                result["value"] = val
                                result["failed_rows_count"] = val
                    
                    # Extract row_count value
                    if 'row_count' in line_lower and result["value"] is None:
                        numbers = re.findall(r'=\s*(\d+)', line)
                        if numbers:
                            result["value"] = int(numbers[-1])
                        else:
                            numbers = re.findall(r'\[(\d+)\]', line)
                            if numbers:
                                result["value"] = int(numbers[-1])
                    
                    # Extract failed rows count from various formats
                    # Format: "failed_rows = 5" or "fail_count = 5" or "5 failed rows"
                    if ('failed' in line_lower or 'fail_count' in line_lower) and result["failed_rows_count"] == 0:
                        # Try "= N" format
                        match = re.search(r'(?:failed_rows|fail_count|failed rows)\s*[=:]\s*(\d+)', line_lower)
                        if match:
                            count = int(match.group(1))
                            if count > 0:
                                result["failed_rows_count"] = count
                                if result["value"] is None:
                                    result["value"] = count
                        # Try "N failed" format
                        else:
                            match = re.search(r'(\d+)\s+(?:failed|rows?\s+failed)', line_lower)
                            if match:
                                count = int(match.group(1))
                                if count > 0:
                                    result["failed_rows_count"] = count
                                    if result["value"] is None:
                                        result["value"] = count
                    
                    # Also check for "Query returned N rows"
                    if 'query' in line_lower and 'rows' in line_lower and result["failed_rows_count"] == 0:
                        match = re.search(r'(\d+)\s*rows?', line_lower)
                        if match:
                            count = int(match.group(1))
                            if count > 0:
                                result["failed_rows_count"] = count
                                if result["value"] is None:
                                    result["value"] = count
                                    
            except Exception as e:
                result["log_error"] = str(e)
            
            return result
            
        except Exception as e:
            return {"success": False, "outcome": "error", "error": str(e), "value": None, "message": str(e)}


def main():
    import argparse
    
    parser = argparse.ArgumentParser(description='DataRover Soda Scanner')
    parser.add_argument('-d', '--data-source', required=True)
    parser.add_argument('-t', '--table')
    parser.add_argument('--single', action='store_true')
    parser.add_argument('--check-type', default='row_count')
    parser.add_argument('--column')
    parser.add_argument('--operator', default='>')
    parser.add_argument('--threshold', default='0')
    parser.add_argument('--config')
    parser.add_argument('--check-file')
    parser.add_argument('--custom-sql', help='Custom SQL for fetching failed rows')
    parser.add_argument('--fetch-rows', action='store_true', help='Fetch actual failed rows')
    parser.add_argument('--limit', type=int, default=100, help='Max rows to fetch')
    parser.add_argument('--total-sql', help='SQL to get total row count for percentage calculation')
    parser.add_argument('--json', action='store_true')
    
    args = parser.parse_args()
    scanner = SodaScanner()
    
    # Check if this is a custom SQL check (no check file needed)
    if args.single and args.config and args.fetch_rows and args.custom_sql:
        # Custom check mode - directly fetch rows and calculate percentage
        results = {
            'outcome': 'unknown',
            'message': 'Custom SQL check',
            'value': 0
        }
        
        # Fetch failed rows
        failed_data = fetch_failed_rows(
            config_file=args.config,
            sql=args.custom_sql,
            db_type=args.data_source.replace('_db', ''),
            limit=args.limit
        )
        
        results['failed_rows'] = failed_data.get('rows', [])
        results['failed_rows_count'] = failed_data.get('total_count', 0)
        results['value'] = failed_data.get('total_count', 0)
        
        if failed_data.get('error'):
            results['outcome'] = 'error'
            results['fetch_error'] = failed_data['error']
            results['message'] = failed_data['error']
        
        # Calculate percentage if we have total_sql
        if args.total_sql:
            total_data = get_total_row_count(args.config, args.total_sql)
            if total_data.get('total_count', 0) > 0:
                total_rows = total_data['total_count']
                failed_rows = results['value']
                valid_rows = total_rows - failed_rows
                percentage = (valid_rows / total_rows) * 100
                
                results['total_rows'] = total_rows
                results['valid_rows'] = valid_rows
                results['invalid_rows'] = failed_rows
                results['percentage'] = round(percentage, 2)
                
                # Determine outcome based on percentage (assuming 90% threshold)
                if percentage >= 90:
                    results['outcome'] = 'pass'
                else:
                    results['outcome'] = 'fail'
            elif not results.get('fetch_error'):
                results['outcome'] = 'error'
                results['message'] = 'Failed to get total row count'
        
        if args.json:
            print(json.dumps(results))
        else:
            print(f"Outcome: {results.get('outcome')}")
            print(f"Value: {results.get('value')}")
            if results.get('percentage') is not None:
                print(f"Percentage: {results['percentage']}%")
            if results.get('failed_rows'):
                print(f"Failed Rows: {len(results['failed_rows'])}")
        
        return 0 if results.get('outcome') == 'pass' else 1
    
    # Standard check mode with check file
    elif args.single and args.config and args.check_file:
        results = scanner.run_scan_with_files(args.data_source, args.config, args.check_file)
        
        # Always fetch failed rows if we have SQL (not just when outcome is 'fail')
        # This allows us to show invalid rows count even when check passes
        if args.fetch_rows and args.custom_sql:
            failed_data = fetch_failed_rows(
                config_file=args.config,
                sql=args.custom_sql,
                db_type=args.data_source.replace('_db', ''),
                limit=args.limit
            )
            results['failed_rows'] = failed_data.get('rows', [])
            results['failed_rows_count'] = failed_data.get('total_count', 0)
            # Only set value if there are actually failed rows
            if failed_data.get('total_count', 0) > 0:
                results['value'] = failed_data.get('total_count', 0)
            if failed_data.get('error'):
                results['fetch_error'] = failed_data['error']
        
        # Calculate percentage if we have total_sql
        if args.total_sql and args.config:
            total_data = get_total_row_count(args.config, args.total_sql)
            if total_data.get('total_count', 0) > 0:
                total_rows = total_data['total_count']
                failed_rows = results.get('failed_rows_count') or results.get('value') or 0
                valid_rows = total_rows - failed_rows
                percentage = (valid_rows / total_rows) * 100
                results['total_rows'] = total_rows
                results['valid_rows'] = valid_rows
                results['invalid_rows'] = failed_rows
                results['percentage'] = round(percentage, 2)
        
        if args.json:
            print(json.dumps(results))
        else:
            print(f"Outcome: {results.get('outcome')}")
            print(f"Value: {results.get('value')}")
            if results.get('percentage') is not None:
                print(f"Percentage: {results['percentage']}%")
            if results.get('failed_rows'):
                print(f"Failed Rows: {len(results['failed_rows'])}")
        return 0 if results.get('outcome') == 'pass' else 1
    
    # No valid mode detected
    print("Usage: python soda_scanner.py --single -d oracle_db --config config.yml --check-file check.yml --json")
    print("   OR: python soda_scanner.py --single -d mysql_db --config config.yml --fetch-rows --custom-sql 'SELECT...' --total-sql 'SELECT COUNT...' --json")
    return 1


def get_total_row_count(config_file: str, sql: str) -> dict:
    """Get total row count using SQL query"""
    if not sql or not os.path.exists(config_file):
        return {"total_count": 0}
    
    try:
        with open(config_file, 'r') as f:
            config = yaml.safe_load(f)
        
        ds_config = None
        for key, value in config.items():
            if key.startswith('data_source'):
                ds_config = value
                break
        
        if not ds_config:
            return {"total_count": 0}
        
        db_type = ds_config.get('type', 'mysql').lower()
        
        if db_type == 'oracle':
            import oracledb
            connect_string = ds_config.get('connect_string', 'localhost:1521/orcl')
            conn = oracledb.connect(
                user=ds_config.get('username', ''),
                password=ds_config.get('password', ''),
                dsn=connect_string
            )
            cursor = conn.cursor()
            cursor.execute(sql)
            total = cursor.fetchone()[0]
            cursor.close()
            conn.close()
            return {"total_count": total}
            
        elif db_type == 'mysql':
            import mysql.connector
            conn = mysql.connector.connect(
                host=ds_config.get('host', 'localhost'),
                port=int(ds_config.get('port', 3306)),
                user=ds_config.get('username', ''),
                password=ds_config.get('password', ''),
                database=ds_config.get('database', '')
            )
            cursor = conn.cursor()
            cursor.execute(sql)
            total = cursor.fetchone()[0]
            cursor.close()
            conn.close()
            return {"total_count": total}
            
        elif db_type in ('postgres', 'postgresql'):
            import psycopg2
            conn = psycopg2.connect(
                host=ds_config.get('host', 'localhost'),
                port=int(ds_config.get('port', 5432)),
                user=ds_config.get('username', ''),
                password=ds_config.get('password', ''),
                dbname=ds_config.get('database', '')
            )
            cursor = conn.cursor()
            cursor.execute(sql)
            total = cursor.fetchone()[0]
            cursor.close()
            conn.close()
            return {"total_count": total}
            
    except Exception as e:
        return {"total_count": 0, "error": str(e)}
    
    return {"total_count": 0}


if __name__ == "__main__":
    sys.exit(main())
