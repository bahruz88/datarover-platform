"""
SQLite Database Connector
"""

import sqlite3
from typing import Optional, List, Dict, Any
from .base import BaseConnector


class SQLiteConnector(BaseConnector):
    """SQLite database connector (file-based)"""
    
    def __init__(self, host: str = None, port: int = None, username: str = None, 
                 password: str = None, database: str = None, **kwargs):
        # SQLite only needs database path
        self.database = database or host  # host can be file path
        self._connection = None
    
    def connect(self):
        """Establish connection"""
        if self._connection is None:
            self._connection = sqlite3.connect(self.database)
            self._connection.row_factory = sqlite3.Row
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
            cursor.execute("SELECT sqlite_version()")
            version = cursor.fetchone()[0]
            cursor.close()
            self.disconnect()
            
            return {
                'success': True,
                'message': 'Connection successful',
                'server_info': {
                    'version': f'SQLite {version}',
                    'database': self.database
                }
            }
        except Exception as e:
            self.disconnect()
            return {'success': False, 'message': str(e)}
    
    def list_schemas(self) -> List[Dict[str, Any]]:
        """SQLite has no schemas, return main"""
        try:
            self.connect()
            cursor = self._connection.cursor()
            cursor.execute("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")
            count = cursor.fetchone()[0]
            cursor.close()
            self.disconnect()
            return [{'name': 'main', 'table_count': count}]
        except Exception as e:
            self.disconnect()
            raise e
    
    def list_tables(self, schema: Optional[str] = None) -> List[Dict[str, Any]]:
        """List all tables"""
        try:
            self.connect()
            cursor = self._connection.cursor()
            
            cursor.execute("""
                SELECT name, type 
                FROM sqlite_master 
                WHERE type IN ('table', 'view') AND name NOT LIKE 'sqlite_%'
                ORDER BY name
            """)
            
            tables = []
            for row in cursor.fetchall():
                cursor.execute(f"SELECT COUNT(*) FROM [{row['name']}]")
                row_count = cursor.fetchone()[0]
                tables.append({
                    'schema_name': 'main',
                    'table_name': row['name'],
                    'table_type': 'VIEW' if row['type'] == 'view' else 'BASE TABLE',
                    'row_count': row_count,
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
            
            cursor.execute(f"PRAGMA table_info([{table}])")
            columns = cursor.fetchall()
            
            result = []
            for col in columns:
                result.append({
                    'column_name': col['name'],
                    'data_type': col['type'].lower() if col['type'] else 'text',
                    'full_type': col['type'] or 'TEXT',
                    'max_length': None,
                    'precision': None,
                    'scale': None,
                    'is_nullable': col['notnull'] == 0,
                    'is_primary_key': col['pk'] == 1,
                    'is_foreign_key': False,
                    'default_value': col['dflt_value'],
                    'comment': None,
                    'position': col['cid'] + 1
                })
            
            cursor.close()
            self.disconnect()
            return result
        except Exception as e:
            self.disconnect()
            raise e
    
    def get_primary_keys(self, schema: str, table: str) -> List[str]:
        """Get primary keys"""
        try:
            self.connect()
            cursor = self._connection.cursor()
            cursor.execute(f"PRAGMA table_info([{table}])")
            pks = [col['name'] for col in cursor.fetchall() if col['pk'] > 0]
            cursor.close()
            return pks
        except:
            return []
    
    def get_foreign_keys(self, schema: str, table: str) -> List[Dict[str, Any]]:
        """Get foreign keys"""
        try:
            self.connect()
            cursor = self._connection.cursor()
            cursor.execute(f"PRAGMA foreign_key_list([{table}])")
            fks = []
            for row in cursor.fetchall():
                fks.append({
                    'column_name': row['from'],
                    'referenced_schema': 'main',
                    'referenced_table': row['table'],
                    'referenced_column': row['to']
                })
            cursor.close()
            return fks
        except:
            return []
