"""
Google BigQuery Database Connector
"""

from google.cloud import bigquery
from typing import Optional, List, Dict, Any
from .base import BaseConnector


class BigQueryConnector(BaseConnector):
    """Google BigQuery database connector"""
    
    def __init__(self, host: str = None, port: int = None, username: str = None,
                 password: str = None, database: str = None, 
                 project: str = None, credentials_path: str = None, **kwargs):
        super().__init__(host, port, username, password, database)
        self.project = project or database or host
        self.credentials_path = credentials_path
    
    def connect(self):
        """Establish connection"""
        if self._connection is None:
            if self.credentials_path:
                self._connection = bigquery.Client.from_service_account_json(self.credentials_path)
            else:
                self._connection = bigquery.Client(project=self.project)
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
            # Simple query to test
            query = "SELECT 1"
            result = self._connection.query(query).result()
            self.disconnect()
            
            return {
                'success': True,
                'message': 'Connection successful',
                'server_info': {
                    'version': 'Google BigQuery',
                    'project': self.project
                }
            }
        except Exception as e:
            self.disconnect()
            return {'success': False, 'message': str(e)}
    
    def list_schemas(self) -> List[Dict[str, Any]]:
        """List all datasets"""
        try:
            self.connect()
            datasets = list(self._connection.list_datasets())
            
            result = []
            for dataset in datasets:
                tables = list(self._connection.list_tables(dataset.dataset_id))
                result.append({
                    'name': dataset.dataset_id,
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
            dataset_id = schema or self.database
            tables_list = list(self._connection.list_tables(dataset_id))
            
            tables = []
            for t in tables_list:
                table_ref = self._connection.get_table(t.reference)
                tables.append({
                    'schema_name': dataset_id,
                    'table_name': t.table_id,
                    'table_type': 'VIEW' if t.table_type == 'VIEW' else 'BASE TABLE',
                    'row_count': table_ref.num_rows or 0,
                    'size_mb': round((table_ref.num_bytes or 0) / 1024 / 1024, 2),
                    'comment': table_ref.description
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
            table_ref = self._connection.get_table(f"{self.project}.{schema}.{table}")
            
            result = []
            position = 0
            for field in table_ref.schema:
                position += 1
                result.append({
                    'column_name': field.name,
                    'data_type': field.field_type.lower(),
                    'full_type': field.field_type,
                    'max_length': field.max_length,
                    'precision': field.precision,
                    'scale': field.scale,
                    'is_nullable': field.mode != 'REQUIRED',
                    'is_primary_key': False,
                    'is_foreign_key': False,
                    'default_value': None,
                    'comment': field.description,
                    'position': position
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
