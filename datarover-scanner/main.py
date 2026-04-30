"""
DataRover Scanner - Database Metadata Extraction Microservice
Supports: MySQL, MariaDB, PostgreSQL, Oracle, SQL Server, SQLite, Hive, MongoDB, Snowflake, ClickHouse, BigQuery, Cassandra, Redis, Elasticsearch
"""

from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
from typing import Optional, List, Dict, Any
import uvicorn

# Core connectors (always available)
from connectors.mysql import MySQLConnector

# Optional connectors - check availability
CONNECTORS = {
    'mysql': {'available': True, 'name': 'MySQL', 'port': 3306},
    'mariadb': {'available': True, 'name': 'MariaDB', 'port': 3306},
    'postgresql': {'available': False, 'name': 'PostgreSQL', 'port': 5432},
    'oracle': {'available': False, 'name': 'Oracle', 'port': 1521},
    'sqlserver': {'available': False, 'name': 'SQL Server', 'port': 1433},
    'sqlite': {'available': False, 'name': 'SQLite', 'port': 0},
    'hive': {'available': False, 'name': 'Apache Hive', 'port': 10000},
    'mongodb': {'available': False, 'name': 'MongoDB', 'port': 27017},
    'snowflake': {'available': False, 'name': 'Snowflake', 'port': 443},
    'clickhouse': {'available': False, 'name': 'ClickHouse', 'port': 9000},
    'bigquery': {'available': False, 'name': 'BigQuery', 'port': 0},
    'cassandra': {'available': False, 'name': 'Cassandra', 'port': 9042},
    'redis': {'available': False, 'name': 'Redis', 'port': 6379},
    'elasticsearch': {'available': False, 'name': 'Elasticsearch', 'port': 9200},
    'iomete': {'available': False, 'name': 'iomete Lakehouse', 'port': 443},
}

# Check and import optional connectors
try:
    import psycopg2
    from connectors.postgresql import PostgreSQLConnector
    CONNECTORS['postgresql']['available'] = True
    print("✅ PostgreSQL")
except: print("❌ PostgreSQL")

try:
    import oracledb
    from connectors.oracle import OracleConnector
    CONNECTORS['oracle']['available'] = True
    print("✅ Oracle")
except: print("❌ Oracle")

try:
    import pymssql
    from connectors.sqlserver import SQLServerConnector
    CONNECTORS['sqlserver']['available'] = True
    print("✅ SQL Server")
except: print("❌ SQL Server")

try:
    import sqlite3
    from connectors.sqlite import SQLiteConnector
    CONNECTORS['sqlite']['available'] = True
    print("✅ SQLite")
except: print("❌ SQLite")

try:
    from pyhive import hive
    from connectors.hive import HiveConnector
    CONNECTORS['hive']['available'] = True
    print("✅ Hive")
except: print("❌ Hive")

try:
    from pymongo import MongoClient
    from connectors.mongodb import MongoDBConnector
    CONNECTORS['mongodb']['available'] = True
    print("✅ MongoDB")
except: print("❌ MongoDB")

try:
    import snowflake.connector
    from connectors.snowflake import SnowflakeConnector
    CONNECTORS['snowflake']['available'] = True
    print("✅ Snowflake")
except: print("❌ Snowflake")

try:
    from clickhouse_driver import Client
    from connectors.clickhouse import ClickHouseConnector
    CONNECTORS['clickhouse']['available'] = True
    print("✅ ClickHouse")
except: print("❌ ClickHouse")

try:
    from google.cloud import bigquery
    from connectors.bigquery import BigQueryConnector
    CONNECTORS['bigquery']['available'] = True
    print("✅ BigQuery")
except: print("❌ BigQuery")

try:
    from cassandra.cluster import Cluster
    from connectors.cassandra import CassandraConnector
    CONNECTORS['cassandra']['available'] = True
    print("✅ Cassandra")
except: print("❌ Cassandra")

try:
    import redis
    from connectors.redis_connector import RedisConnector
    CONNECTORS['redis']['available'] = True
    print("✅ Redis")
except: print("❌ Redis")

try:
    from elasticsearch import Elasticsearch
    from connectors.elasticsearch_connector import ElasticsearchConnector
    CONNECTORS['elasticsearch']['available'] = True
    print("✅ Elasticsearch")
except: print("❌ Elasticsearch")

try:
    from pyhive import sqlalchemy_iomete  # noqa: F401  # registers iomete dialect
    from connectors.iomete import IometeConnector
    CONNECTORS['iomete']['available'] = True
    print("✅ iomete")
except: print("❌ iomete")


app = FastAPI(title="DataRover Scanner", description="Database metadata extraction for DataRover", version="3.0.0")

app.add_middleware(CORSMiddleware, allow_origins=["*"], allow_credentials=True, allow_methods=["*"], allow_headers=["*"])


def get_connector(db_type: str):
    """Get connector class by database type"""
    if db_type in ('mysql', 'mariadb'): return MySQLConnector
    elif db_type == 'postgresql' and CONNECTORS['postgresql']['available']: return PostgreSQLConnector
    elif db_type == 'oracle' and CONNECTORS['oracle']['available']: return OracleConnector
    elif db_type == 'sqlserver' and CONNECTORS['sqlserver']['available']: return SQLServerConnector
    elif db_type == 'sqlite' and CONNECTORS['sqlite']['available']: return SQLiteConnector
    elif db_type == 'hive' and CONNECTORS['hive']['available']: return HiveConnector
    elif db_type == 'mongodb' and CONNECTORS['mongodb']['available']: return MongoDBConnector
    elif db_type == 'snowflake' and CONNECTORS['snowflake']['available']: return SnowflakeConnector
    elif db_type == 'clickhouse' and CONNECTORS['clickhouse']['available']: return ClickHouseConnector
    elif db_type == 'bigquery' and CONNECTORS['bigquery']['available']: return BigQueryConnector
    elif db_type == 'cassandra' and CONNECTORS['cassandra']['available']: return CassandraConnector
    elif db_type == 'redis' and CONNECTORS['redis']['available']: return RedisConnector
    elif db_type == 'elasticsearch' and CONNECTORS['elasticsearch']['available']: return ElasticsearchConnector
    elif db_type == 'iomete' and CONNECTORS['iomete']['available']: return IometeConnector
    else:
        raise HTTPException(status_code=400, detail=f"Database '{db_type}' not available. Install required driver.")


class ConnectionRequest(BaseModel):
    db_type: str
    host: str
    port: Optional[int] = None
    username: Optional[str] = None
    password: Optional[str] = None
    database: Optional[str] = None
    sid: Optional[str] = None
    schema_name: Optional[str] = None
    table_name: Optional[str] = None
    # iomete-specific
    data_plane: Optional[str] = None
    lakehouse: Optional[str] = None


def _build_connector(conn: "ConnectionRequest"):
    ConnectorClass = get_connector(conn.db_type)
    port = conn.port or CONNECTORS.get(conn.db_type, {}).get('port', 3306)
    if conn.db_type == 'iomete':
        return ConnectorClass(
            host=conn.host, port=port, username=conn.username,
            password=conn.password, database=conn.database,
            data_plane=conn.data_plane, lakehouse=conn.lakehouse,
        )
    return ConnectorClass(conn.host, port, conn.username, conn.password,
                          conn.database, conn.sid)


class ScanRequest(BaseModel):
    connection: ConnectionRequest
    schemas: Optional[List[str]] = None
    tables: Optional[List[str]] = None


@app.get("/")
async def root():
    available = [k for k, v in CONNECTORS.items() if v['available']]
    return {"service": "DataRover Scanner", "version": "3.0.0", "supported_databases": available}


@app.get("/databases")
async def list_databases():
    databases = [{"id": k, "name": v['name'], "default_port": v['port'], "available": v['available']} for k, v in CONNECTORS.items()]
    return {"databases": databases, "available": [d for d in databases if d['available']]}


@app.post("/test-connection")
async def test_connection(conn: ConnectionRequest):
    try:
        connector = _build_connector(conn)
        return connector.test_connection()
    except HTTPException: raise
    except Exception as e: return {"success": False, "message": str(e) or repr(e) or type(e).__name__ or 'Unknown error'}


@app.post("/list-schemas")
async def list_schemas(conn: ConnectionRequest):
    try:
        connector = _build_connector(conn)
        return {"success": True, "schemas": connector.list_schemas()}
    except HTTPException: raise
    except Exception as e: return {"success": False, "message": str(e) or repr(e) or type(e).__name__ or 'Unknown error'}


@app.post("/list-tables")
async def list_tables(conn: ConnectionRequest):
    try:
        connector = _build_connector(conn)
        return {"success": True, "tables": connector.list_tables(conn.schema_name)}
    except HTTPException: raise
    except Exception as e: return {"success": False, "message": str(e) or repr(e) or type(e).__name__ or 'Unknown error'}


@app.post("/get-columns")
async def get_columns(conn: ConnectionRequest):
    try:
        connector = _build_connector(conn)
        return {"success": True, "columns": connector.get_columns(conn.schema_name, conn.table_name)}
    except HTTPException: raise
    except Exception as e: return {"success": False, "message": str(e) or repr(e) or type(e).__name__ or 'Unknown error'}


@app.post("/scan")
async def scan_database(request: ScanRequest):
    try:
        conn = request.connection
        connector = _build_connector(conn)
        
        schemas = request.schemas
        if not schemas:
            schema_list = connector.list_schemas()
            schemas = [s['name'] for s in schema_list]
        
        all_tables = []
        total_columns = 0
        
        for schema in schemas:
            tables = connector.list_tables(schema)
            for table in tables:
                if request.tables and table['table_name'] not in request.tables:
                    continue
                try:
                    columns = connector.get_columns(schema, table['table_name'])
                    table['columns'] = columns
                    total_columns += len(columns)
                except:
                    table['columns'] = []
                all_tables.append(table)
        
        return {"success": True, "message": "Scan completed", "tables": all_tables,
                "total_tables": len(all_tables), "total_columns": total_columns}
    except HTTPException: raise
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e) or repr(e) or type(e).__name__ or 'Unknown error')


if __name__ == "__main__":
    print("\n🚀 DataRover Scanner v3.0")
    print("=" * 40)
    uvicorn.run(app, host="0.0.0.0", port=8000)
