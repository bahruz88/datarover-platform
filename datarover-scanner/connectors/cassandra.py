"""
Apache Cassandra Database Connector
"""

from cassandra.cluster import Cluster
from cassandra.auth import PlainTextAuthProvider
from typing import Optional, List, Dict, Any
from .base import BaseConnector


class CassandraConnector(BaseConnector):
    """Apache Cassandra database connector"""
    
    def connect(self):
        """Establish connection"""
        if self._connection is None:
            auth_provider = None
            if self.username and self.password:
                auth_provider = PlainTextAuthProvider(
                    username=self.username,
                    password=self.password
                )
            
            cluster = Cluster(
                [self.host],
                port=self.port or 9042,
                auth_provider=auth_provider
            )
            self._connection = cluster.connect(self.database)
            self._cluster = cluster
        return self._connection
    
    def disconnect(self):
        """Close connection"""
        if self._connection:
            self._connection.shutdown()
            self._cluster.shutdown()
            self._connection = None
    
    def test_connection(self) -> Dict[str, Any]:
        """Test connection"""
        try:
            self.connect()
            row = self._connection.execute("SELECT release_version FROM system.local").one()
            version = row.release_version
            self.disconnect()
            
            return {
                'success': True,
                'message': 'Connection successful',
                'server_info': {
                    'version': f'Cassandra {version}',
                    'keyspace': self.database
                }
            }
        except Exception as e:
            self.disconnect()
            return {'success': False, 'message': str(e)}
    
    def list_schemas(self) -> List[Dict[str, Any]]:
        """List all keyspaces"""
        try:
            self.connect()
            rows = self._connection.execute(
                "SELECT keyspace_name FROM system_schema.keyspaces"
            )
            
            result = []
            for row in rows:
                ks_name = row.keyspace_name
                if not ks_name.startswith('system'):
                    tables = self._connection.execute(
                        f"SELECT table_name FROM system_schema.tables WHERE keyspace_name = '{ks_name}'"
                    )
                    result.append({
                        'name': ks_name,
                        'table_count': len(list(tables))
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
            keyspace = schema or self.database
            
            rows = self._connection.execute(f"""
                SELECT table_name, comment 
                FROM system_schema.tables 
                WHERE keyspace_name = '{keyspace}'
            """)
            
            tables = []
            for row in rows:
                tables.append({
                    'schema_name': keyspace,
                    'table_name': row.table_name,
                    'table_type': 'BASE TABLE',
                    'row_count': 0,
                    'size_mb': 0,
                    'comment': row.comment
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
            
            rows = self._connection.execute(f"""
                SELECT column_name, type, kind, position
                FROM system_schema.columns
                WHERE keyspace_name = '{schema}' AND table_name = '{table}'
            """)
            
            result = []
            for row in rows:
                result.append({
                    'column_name': row.column_name,
                    'data_type': row.type.lower(),
                    'full_type': row.type,
                    'max_length': None,
                    'precision': None,
                    'scale': None,
                    'is_nullable': True,
                    'is_primary_key': row.kind == 'partition_key' or row.kind == 'clustering',
                    'is_foreign_key': False,
                    'default_value': None,
                    'comment': row.kind,
                    'position': row.position
                })
            
            self.disconnect()
            return result
        except Exception as e:
            self.disconnect()
            raise e
    
    def get_primary_keys(self, schema: str, table: str) -> List[str]:
        try:
            self.connect()
            rows = self._connection.execute(f"""
                SELECT column_name FROM system_schema.columns
                WHERE keyspace_name = '{schema}' AND table_name = '{table}'
                AND kind IN ('partition_key', 'clustering')
            """)
            return [row.column_name for row in rows]
        except:
            return []
    
    def get_foreign_keys(self, schema: str, table: str) -> List[Dict[str, Any]]:
        return []
