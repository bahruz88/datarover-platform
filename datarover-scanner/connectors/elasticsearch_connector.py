"""
Elasticsearch Database Connector
"""

from elasticsearch import Elasticsearch
from typing import Optional, List, Dict, Any
from .base import BaseConnector


class ElasticsearchConnector(BaseConnector):
    """Elasticsearch database connector"""
    
    def connect(self):
        """Establish connection"""
        if self._connection is None:
            hosts = [f"http://{self.host}:{self.port or 9200}"]
            
            if self.username and self.password:
                self._connection = Elasticsearch(
                    hosts,
                    basic_auth=(self.username, self.password)
                )
            else:
                self._connection = Elasticsearch(hosts)
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
            info = self._connection.info()
            self.disconnect()
            
            return {
                'success': True,
                'message': 'Connection successful',
                'server_info': {
                    'version': f"Elasticsearch {info['version']['number']}",
                    'cluster_name': info['cluster_name']
                }
            }
        except Exception as e:
            self.disconnect()
            return {'success': False, 'message': str(e)}
    
    def list_schemas(self) -> List[Dict[str, Any]]:
        """List all indices as schemas"""
        try:
            self.connect()
            indices = self._connection.cat.indices(format='json')
            
            result = []
            for idx in indices:
                if not idx['index'].startswith('.'):
                    result.append({
                        'name': idx['index'],
                        'table_count': 1
                    })
            
            self.disconnect()
            return result
        except Exception as e:
            self.disconnect()
            raise e
    
    def list_tables(self, schema: Optional[str] = None) -> List[Dict[str, Any]]:
        """List indices"""
        try:
            self.connect()
            indices = self._connection.cat.indices(format='json')
            
            tables = []
            for idx in indices:
                if not idx['index'].startswith('.'):
                    if schema and idx['index'] != schema:
                        continue
                    tables.append({
                        'schema_name': idx['index'],
                        'table_name': idx['index'],
                        'table_type': 'INDEX',
                        'row_count': int(idx.get('docs.count', 0) or 0),
                        'size_mb': self._parse_size(idx.get('store.size', '0')),
                        'comment': f"Health: {idx.get('health', 'unknown')}"
                    })
            
            self.disconnect()
            return tables
        except Exception as e:
            self.disconnect()
            raise e
    
    def _parse_size(self, size_str: str) -> float:
        """Parse size string to MB"""
        try:
            if not size_str:
                return 0
            size_str = size_str.lower()
            if 'gb' in size_str:
                return float(size_str.replace('gb', '')) * 1024
            elif 'mb' in size_str:
                return float(size_str.replace('mb', ''))
            elif 'kb' in size_str:
                return float(size_str.replace('kb', '')) / 1024
            elif 'b' in size_str:
                return float(size_str.replace('b', '')) / 1024 / 1024
            return 0
        except:
            return 0
    
    def get_columns(self, schema: str, table: str) -> List[Dict[str, Any]]:
        """Get mapping fields"""
        try:
            self.connect()
            index_name = table or schema
            mapping = self._connection.indices.get_mapping(index=index_name)
            
            result = []
            position = 0
            
            if index_name in mapping:
                properties = mapping[index_name].get('mappings', {}).get('properties', {})
                for field_name, field_info in properties.items():
                    position += 1
                    result.append({
                        'column_name': field_name,
                        'data_type': field_info.get('type', 'object'),
                        'full_type': field_info.get('type', 'object').upper(),
                        'max_length': None,
                        'precision': None,
                        'scale': None,
                        'is_nullable': True,
                        'is_primary_key': field_name == '_id',
                        'is_foreign_key': False,
                        'default_value': None,
                        'comment': None,
                        'position': position
                    })
            
            self.disconnect()
            return result
        except Exception as e:
            self.disconnect()
            raise e
    
    def get_primary_keys(self, schema: str, table: str) -> List[str]:
        return ['_id']
    
    def get_foreign_keys(self, schema: str, table: str) -> List[Dict[str, Any]]:
        return []
