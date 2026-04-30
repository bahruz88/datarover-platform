"""
DataRover Failed Rows Fetcher
Fetches all rows matching a SQL query from various database types
"""

import os
import sys
import json
import argparse
from datetime import datetime
from typing import Dict, List, Any

# Set TNS_ADMIN for Oracle
os.environ['TNS_ADMIN'] = r"C:\oracle\network\admin"

import yaml


def fetch_rows(config_file: str, sql: str, limit: int = 10000) -> Dict:
    """Fetch rows from database using SQL query"""
    
    if not sql:
        return {"rows": [], "total_count": 0, "error": "No SQL provided"}
    
    if not os.path.exists(config_file):
        return {"rows": [], "total_count": 0, "error": f"Config file not found: {config_file}"}
    
    try:
        # Parse config file
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
            rows, total_count = fetch_from_oracle(ds_config, sql, limit)
        elif db_type in ('mysql',):
            rows, total_count = fetch_from_mysql(ds_config, sql, limit)
        elif db_type in ('postgres', 'postgresql'):
            rows, total_count = fetch_from_postgres(ds_config, sql, limit)
        else:
            return {"rows": [], "total_count": 0, "error": f"Unsupported database type: {db_type}"}
        
        return {
            "rows": rows,
            "total_count": total_count,
            "fetched_count": len(rows),
            "db_type": db_type
        }
        
    except Exception as e:
        return {"rows": [], "total_count": 0, "error": str(e)}


def fetch_from_oracle(config: Dict, sql: str, limit: int) -> tuple:
    """Fetch rows from Oracle database using batch fetching"""
    try:
        import oracledb
        
        connect_string = config.get('connect_string', 'localhost:1521/orcl')
        username = config.get('username', '')
        password = config.get('password', '')
        
        conn = oracledb.connect(user=username, password=password, dsn=connect_string)
        cursor = conn.cursor()
        
        # Get total count first
        total_count = 0
        try:
            count_sql = f"SELECT COUNT(*) FROM ({sql})"
            cursor.execute(count_sql)
            total_count = cursor.fetchone()[0]
        except Exception as e:
            pass
        
        # Fetch rows with limit using batch fetching
        if limit and limit > 0:
            limited_sql = f"SELECT * FROM ({sql}) WHERE ROWNUM <= {limit}"
        else:
            limited_sql = sql
        
        cursor.execute(limited_sql)
        
        # Get column names
        columns = [col[0] for col in cursor.description]
        
        rows = []
        batch_size = 500
        
        # Use fetchmany for batch processing
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
                    elif isinstance(val, datetime):
                        row_dict[col] = val.isoformat()
                    elif isinstance(val, bytes):
                        row_dict[col] = val.decode('utf-8', errors='replace')
                    else:
                        row_dict[col] = str(val) if not isinstance(val, (int, float, bool)) else val
                rows.append(row_dict)
            
            # Stop if we've reached the limit
            if limit and len(rows) >= limit:
                break
        
        cursor.close()
        conn.close()
        
        return rows, total_count if total_count > 0 else len(rows)
        
    except Exception as e:
        raise Exception(f"Oracle error: {str(e)}")


def fetch_from_mysql(config: Dict, sql: str, limit: int) -> tuple:
    """Fetch rows from MySQL database using batch fetching"""
    try:
        import mysql.connector
        
        conn = mysql.connector.connect(
            host=config.get('host', 'localhost'),
            port=int(config.get('port', 3306)),
            user=config.get('username', ''),
            password=config.get('password', ''),
            database=config.get('database', '')
        )
        cursor = conn.cursor()
        
        # Get total count
        total_count = 0
        try:
            count_sql = f"SELECT COUNT(*) FROM ({sql}) as subq"
            cursor.execute(count_sql)
            total_count = cursor.fetchone()[0]
        except:
            pass
        
        # Fetch rows with limit
        if limit and limit > 0:
            limited_sql = f"{sql} LIMIT {limit}"
        else:
            limited_sql = sql
        
        cursor.execute(limited_sql)
        columns = [col[0] for col in cursor.description]
        
        rows = []
        batch_size = 500
        
        # Use fetchmany for batch processing
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
                    elif isinstance(val, datetime):
                        row_dict[col] = val.isoformat()
                    elif isinstance(val, bytes):
                        row_dict[col] = val.decode('utf-8', errors='replace')
                    else:
                        row_dict[col] = str(val) if not isinstance(val, (int, float, bool)) else val
                rows.append(row_dict)
            
            # Stop if we've reached the limit
            if limit and len(rows) >= limit:
                break
        
        cursor.close()
        conn.close()
        
        return rows, total_count if total_count > 0 else len(rows)
        
    except Exception as e:
        raise Exception(f"MySQL error: {str(e)}")


def fetch_from_postgres(config: Dict, sql: str, limit: int) -> tuple:
    """Fetch rows from PostgreSQL database using batch fetching"""
    try:
        import psycopg2
        
        conn = psycopg2.connect(
            host=config.get('host', 'localhost'),
            port=int(config.get('port', 5432)),
            user=config.get('username', ''),
            password=config.get('password', ''),
            dbname=config.get('database', '')
        )
        cursor = conn.cursor()
        
        # Get total count
        total_count = 0
        try:
            count_sql = f"SELECT COUNT(*) FROM ({sql}) as subq"
            cursor.execute(count_sql)
            total_count = cursor.fetchone()[0]
        except:
            pass
        
        # Fetch rows with limit
        if limit and limit > 0:
            limited_sql = f"{sql} LIMIT {limit}"
        else:
            limited_sql = sql
        
        cursor.execute(limited_sql)
        columns = [col[0] for col in cursor.description]
        
        rows = []
        batch_size = 500
        
        # Use fetchmany for batch processing
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
                    elif isinstance(val, datetime):
                        row_dict[col] = val.isoformat()
                    elif isinstance(val, bytes):
                        row_dict[col] = val.decode('utf-8', errors='replace')
                    else:
                        row_dict[col] = str(val) if not isinstance(val, (int, float, bool)) else val
                rows.append(row_dict)
            
            # Stop if we've reached the limit
            if limit and len(rows) >= limit:
                break
        
        cursor.close()
        conn.close()
        
        return rows, total_count if total_count > 0 else len(rows)
        
    except Exception as e:
        raise Exception(f"PostgreSQL error: {str(e)}")


def main():
    parser = argparse.ArgumentParser(description='DataRover Failed Rows Fetcher')
    parser.add_argument('--config', required=True, help='Path to YAML config file')
    parser.add_argument('--sql', required=True, help='SQL query to execute')
    parser.add_argument('--limit', type=int, default=10000, help='Max rows to fetch (default: 10000)')
    parser.add_argument('--json', action='store_true', help='Output as JSON')
    parser.add_argument('--csv', help='Output to CSV file path')
    
    args = parser.parse_args()
    
    result = fetch_rows(args.config, args.sql, args.limit)
    
    if args.csv:
        # Write directly to CSV file
        try:
            import csv
            rows = result.get('rows', [])
            if rows:
                with open(args.csv, 'w', newline='', encoding='utf-8') as f:
                    writer = csv.DictWriter(f, fieldnames=rows[0].keys())
                    writer.writeheader()
                    for row in rows:
                        writer.writerow(row)
                print(f"CSV written: {len(rows)} rows")
            else:
                # Write empty file with error message
                with open(args.csv, 'w', encoding='utf-8') as f:
                    f.write(f"Error: {result.get('error', 'No rows found')}\n")
                print(f"Error: {result.get('error', 'No rows found')}")
        except Exception as e:
            print(f"CSV Error: {str(e)}")
            return 1
    elif args.json:
        print(json.dumps(result))
    else:
        if result.get('error'):
            print(f"Error: {result['error']}")
        else:
            print(f"Fetched {len(result['rows'])} rows out of {result['total_count']} total")
            for row in result['rows'][:5]:
                print(row)
    
    return 0 if not result.get('error') else 1


if __name__ == "__main__":
    sys.exit(main())
