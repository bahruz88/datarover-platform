"""
Apache Hive Database Connector
"""

from pyhive import hive
from typing import Optional, List, Dict, Any
from .base import BaseConnector


class HiveConnector(BaseConnector):
    """Apache Hive database connector using PyHive"""
    
    def connect(self):
        """Establish connection"""
        if self._connection is None:
            self._connection = hive.connect(
                host=self.host,
                port=self.port or 10000,
                username=self.username,
                database=self.database or 'default',
                auth='CUSTOM' if self.password else 'NONE',
                password=self.password
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
            cursor.execute("SELECT current_database()")
            db_name = cursor.fetchone()[0]
            cursor.close()
            self.disconnect()
            
            return {
                'success': True,
                'message': 'Connection successful',
                'server_info': {
                    'version': 'Apache Hive',
                    'database': db_name
                }
            }
        except Exception as e:
            self.disconnect()
            return {'success': False, 'message': str(e)}
    
    def list_schemas(self) -> List[Dict[str, Any]]:
        """List all databases"""
        try:
            self.connect()
            cursor = self._connection.cursor()
            
            cursor.execute("SHOW DATABASES")
            databases = cursor.fetchall()
            
            result = []
            for db in databases:
                db_name = db[0]
                cursor.execute(f"SHOW TABLES IN {db_name}")
                tables = cursor.fetchall()
                result.append({
                    'name': db_name,
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
            
            db_name = schema or self.database or 'default'
            cursor.execute(f"SHOW TABLES IN {db_name}")
            tables_list = cursor.fetchall()
            
            tables = []
            for t in tables_list:
                table_name = t[0]
                try:
                    cursor.execute(f"DESCRIBE FORMATTED {db_name}.{table_name}")
                    desc = cursor.fetchall()
                    table_type = 'BASE TABLE'
                    for row in desc:
                        if row[0] and 'Table Type' in str(row[0]):
                            table_type = 'VIEW' if 'VIEW' in str(row[1]).upper() else 'BASE TABLE'
                            break
                except:
                    table_type = 'BASE TABLE'
                
                tables.append({
                    'schema_name': db_name,
                    'table_name': table_name,
                    'table_type': table_type,
                    'row_count': 0,
                    'size_mb': 0,
                    'comment': None
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
            
            cursor.execute(f"DESCRIBE {schema}.{table}")
            columns = cursor.fetchall()
            
            result = []
            position = 0
            for col in columns:
                if col[0] and not col[0].startswith('#') and col[0].strip():
                    position += 1
                    col_name = col[0].strip()
                    col_type = col[1].strip() if col[1] else 'string'
                    comment = col[2].strip() if len(col) > 2 and col[2] else None
                    
                    result.append({
                        'column_name': col_name,
                        'data_type': col_type.split('(')[0].lower(),
                        'full_type': col_type,
                        'max_length': None,
                        'precision': None,
                        'scale': None,
                        'is_nullable': True,
                        'is_primary_key': False,
                        'is_foreign_key': False,
                        'default_value': None,
                        'comment': comment,
                        'position': position
                    })
            
            cursor.close()
            self.disconnect()
            return result
        except Exception as e:
            self.disconnect()
            raise e
    
    def get_primary_keys(self, schema: str, table: str) -> List[str]:
        """Hive has no primary keys"""
        return []
    
    def get_foreign_keys(self, schema: str, table: str) -> List[Dict[str, Any]]:
        """Hive has no foreign keys"""
        return []
