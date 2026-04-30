"""
ClickHouse Database Connector
"""

from clickhouse_driver import Client
from typing import Optional, List, Dict, Any
from .base import BaseConnector


class ClickHouseConnector(BaseConnector):
    """ClickHouse database connector"""
    
    def connect(self):
        """Establish connection"""
        if self._connection is None:
            self._connection = Client(
                host=self.host,
                port=self.port or 9000,
                user=self.username or 'default',
                password=self.password or '',
                database=self.database or 'default'
            )
        return self._connection
    
    def disconnect(self):
        """Close connection"""
        if self._connection:
            self._connection.disconnect()
            self._connection = None
    
    def test_connection(self) -> Dict[str, Any]:
        """Test connection"""
        try:
            self.connect()
            result = self._connection.execute("SELECT version()")
            version = result[0][0]
            self.disconnect()
            
            return {
                'success': True,
                'message': 'Connection successful',
                'server_info': {
                    'version': f'ClickHouse {version}',
                    'database': self.database or 'default'
                }
            }
        except Exception as e:
            self.disconnect()
            return {'success': False, 'message': str(e)}
    
    def list_schemas(self) -> List[Dict[str, Any]]:
        """List all databases"""
        try:
            self.connect()
            databases = self._connection.execute("SHOW DATABASES")
            
            result = []
            for db in databases:
                db_name = db[0]
                if db_name not in ['system', 'INFORMATION_SCHEMA', 'information_schema']:
                    tables = self._connection.execute(f"SHOW TABLES FROM {db_name}")
                    result.append({
                        'name': db_name,
                        'table_count': len(tables)
                    })
            
            self.disconnect()
            return result
        except Exception as e:
            self.disconnect()
            raise e
    
    def list_tables(self, schema: Optional[str] = None) -> List[Dict[str, Any]]:
        """List all tables"""
        try:
            self.connect()
            db_name = schema or self.database or 'default'
            
            query = f"""
                SELECT 
                    database,
                    name,
                    engine,
                    total_rows,
                    total_bytes / 1024 / 1024 as size_mb
                FROM system.tables
                WHERE database = '{db_name}'
                ORDER BY name
            """
            rows = self._connection.execute(query)
            
            tables = []
            for row in rows:
                tables.append({
                    'schema_name': row[0],
                    'table_name': row[1],
                    'table_type': 'VIEW' if 'View' in row[2] else 'BASE TABLE',
                    'row_count': row[3] or 0,
                    'size_mb': round(row[4] or 0, 2),
                    'comment': row[2]  # Engine as comment
                })
            
            self.disconnect()
            return tables
        except Exception as e:
            self.disconnect()
            raise e
    
    def get_columns(self, schema: str, table: str) -> List[Dict[str, Any]]:
        """Get columns"""
        try:
            self.connect()
            
            query = f"""
                SELECT 
                    name,
                    type,
                    default_kind,
                    default_expression,
                    comment,
                    position
                FROM system.columns
                WHERE database = '{schema}' AND table = '{table}'
                ORDER BY position
            """
            rows = self._connection.execute(query)
            
            result = []
            for row in rows:
                result.append({
                    'column_name': row[0],
                    'data_type': row[1].lower().split('(')[0],
                    'full_type': row[1],
                    'max_length': None,
                    'precision': None,
                    'scale': None,
                    'is_nullable': 'Nullable' in row[1],
                    'is_primary_key': False,
                    'is_foreign_key': False,
                    'default_value': row[3] if row[2] else None,
                    'comment': row[4],
                    'position': row[5]
                })
            
            self.disconnect()
            return result
        except Exception as e:
            self.disconnect()
            raise e
    
    def get_primary_keys(self, schema: str, table: str) -> List[str]:
        return []
    
    def get_foreign_keys(self, schema: str, table: str) -> List[Dict[str, Any]]:
        return []
