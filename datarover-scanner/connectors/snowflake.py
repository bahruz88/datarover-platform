"""
Snowflake Database Connector
"""

import snowflake.connector
from typing import Optional, List, Dict, Any
from .base import BaseConnector


class SnowflakeConnector(BaseConnector):
    """Snowflake database connector"""
    
    def __init__(self, host: str, port: int = None, username: str = None, 
                 password: str = None, database: str = None, 
                 account: str = None, warehouse: str = None, schema: str = None, **kwargs):
        super().__init__(host, port, username, password, database)
        self.account = account or host  # account can be passed as host
        self.warehouse = warehouse
        self.schema = schema or 'PUBLIC'
    
    def connect(self):
        """Establish connection"""
        if self._connection is None:
            self._connection = snowflake.connector.connect(
                account=self.account,
                user=self.username,
                password=self.password,
                database=self.database,
                warehouse=self.warehouse,
                schema=self.schema
            )
        return self._connection
    
    def disconnect(self):
        """Close connection"""
        if self._connection:
            self._connection.close()
            self._connection = None
    
    def test_connection(self) -> Dict[str, Any]:
        """Test connection"""
        try:
            self.connect()
            cursor = self._connection.cursor()
            cursor.execute("SELECT CURRENT_VERSION(), CURRENT_DATABASE(), CURRENT_WAREHOUSE()")
            row = cursor.fetchone()
            cursor.close()
            self.disconnect()
            
            return {
                'success': True,
                'message': 'Connection successful',
                'server_info': {
                    'version': f'Snowflake {row[0]}',
                    'database': row[1],
                    'warehouse': row[2]
                }
            }
        except Exception as e:
            self.disconnect()
            return {'success': False, 'message': str(e)}
    
    def list_schemas(self) -> List[Dict[str, Any]]:
        """List all schemas"""
        try:
            self.connect()
            cursor = self._connection.cursor()
            
            cursor.execute("SHOW SCHEMAS")
            schemas = cursor.fetchall()
            
            result = []
            for schema in schemas:
                schema_name = schema[1]  # name is second column
                cursor.execute(f"SHOW TABLES IN SCHEMA {self.database}.{schema_name}")
                tables = cursor.fetchall()
                result.append({
                    'name': schema_name,
                    'table_count': len(tables)
                })
            
            cursor.close()
            self.disconnect()
            return result
        except Exception as e:
            self.disconnect()
            raise e
    
    def list_tables(self, schema: Optional[str] = None) -> List[Dict[str, Any]]:
        """List all tables"""
        try:
            self.connect()
            cursor = self._connection.cursor()
            
            schema_name = schema or self.schema or 'PUBLIC'
            cursor.execute(f"""
                SELECT 
                    TABLE_SCHEMA,
                    TABLE_NAME,
                    TABLE_TYPE,
                    ROW_COUNT,
                    BYTES / 1024 / 1024 as SIZE_MB,
                    COMMENT
                FROM {self.database}.INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = '{schema_name}'
                ORDER BY TABLE_NAME
            """)
            
            tables = []
            for row in cursor.fetchall():
                tables.append({
                    'schema_name': row[0],
                    'table_name': row[1],
                    'table_type': row[2],
                    'row_count': row[3] or 0,
                    'size_mb': round(row[4] or 0, 2),
                    'comment': row[5]
                })
            
            cursor.close()
            self.disconnect()
            return tables
        except Exception as e:
            self.disconnect()
            raise e
    
    def get_columns(self, schema: str, table: str) -> List[Dict[str, Any]]:
        """Get columns"""
        try:
            self.connect()
            cursor = self._connection.cursor()
            
            cursor.execute(f"""
                SELECT 
                    COLUMN_NAME,
                    DATA_TYPE,
                    CHARACTER_MAXIMUM_LENGTH,
                    NUMERIC_PRECISION,
                    NUMERIC_SCALE,
                    IS_NULLABLE,
                    COLUMN_DEFAULT,
                    COMMENT,
                    ORDINAL_POSITION
                FROM {self.database}.INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = '{schema}' AND TABLE_NAME = '{table}'
                ORDER BY ORDINAL_POSITION
            """)
            
            result = []
            for row in cursor.fetchall():
                result.append({
                    'column_name': row[0],
                    'data_type': row[1].lower(),
                    'full_type': row[1],
                    'max_length': row[2],
                    'precision': row[3],
                    'scale': row[4],
                    'is_nullable': row[5] == 'YES',
                    'is_primary_key': False,
                    'is_foreign_key': False,
                    'default_value': row[6],
                    'comment': row[7],
                    'position': row[8]
                })
            
            cursor.close()
            self.disconnect()
            return result
        except Exception as e:
            self.disconnect()
            raise e
    
    def get_primary_keys(self, schema: str, table: str) -> List[str]:
        return []
    
    def get_foreign_keys(self, schema: str, table: str) -> List[Dict[str, Any]]:
        return []
