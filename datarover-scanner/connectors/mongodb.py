"""
MongoDB Database Connector
"""

from pymongo import MongoClient
from typing import Optional, List, Dict, Any
from .base import BaseConnector


class MongoDBConnector(BaseConnector):
    """MongoDB database connector using pymongo"""
    
    def connect(self):
        """Establish connection"""
        if self._connection is None:
            if self.username and self.password:
                uri = f"mongodb://{self.username}:{self.password}@{self.host}:{self.port or 27017}"
            else:
                uri = f"mongodb://{self.host}:{self.port or 27017}"
            self._connection = MongoClient(uri)
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
            server_info = self._connection.server_info()
            self.disconnect()
            
            return {
                'success': True,
                'message': 'Connection successful',
                'server_info': {
                    'version': server_info.get('version', 'Unknown'),
                    'database': self.database or 'admin'
                }
            }
        except Exception as e:
            self.disconnect()
            return {'success': False, 'message': str(e)}
    
    def list_schemas(self) -> List[Dict[str, Any]]:
        """List all databases"""
        try:
            self.connect()
            databases = self._connection.list_database_names()
            
            result = []
            for db_name in databases:
                if db_name not in ['admin', 'local', 'config']:
                    db = self._connection[db_name]
                    collections = db.list_collection_names()
                    result.append({
                        'name': db_name,
                        'table_count': len(collections)
                    })
            
            self.disconnect()
            return result
        except Exception as e:
            self.disconnect()
            raise e
    
    def list_tables(self, schema: Optional[str] = None) -> List[Dict[str, Any]]:
        """List all collections"""
        try:
            self.connect()
            db_name = schema or self.database or 'test'
            db = self._connection[db_name]
            collections = db.list_collection_names()
            
            tables = []
            for coll_name in collections:
                coll = db[coll_name]
                try:
                    count = coll.estimated_document_count()
                except:
                    count = 0
                
                tables.append({
                    'schema_name': db_name,
                    'table_name': coll_name,
                    'table_type': 'COLLECTION',
                    'row_count': count,
                    'size_mb': 0,
                    'comment': None
                })
            
            self.disconnect()
            return tables
        except Exception as e:
            self.disconnect()
            raise e
    
    def get_columns(self, schema: str, table: str) -> List[Dict[str, Any]]:
        """Infer columns from sample documents"""
        try:
            self.connect()
            db = self._connection[schema]
            coll = db[table]
            
            # Sample documents to infer schema
            sample = list(coll.find().limit(100))
            
            fields = {}
            for doc in sample:
                self._extract_fields(doc, fields)
            
            result = []
            position = 0
            for field_name, field_type in sorted(fields.items()):
                position += 1
                result.append({
                    'column_name': field_name,
                    'data_type': field_type,
                    'full_type': field_type,
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
    
    def _extract_fields(self, doc: dict, fields: dict, prefix: str = ''):
        """Extract fields from document"""
        for key, value in doc.items():
            field_name = f"{prefix}.{key}" if prefix else key
            field_type = type(value).__name__
            if field_type == 'dict':
                field_type = 'object'
                self._extract_fields(value, fields, field_name)
            elif field_type == 'list':
                field_type = 'array'
            elif field_type == 'ObjectId':
                field_type = 'objectid'
            fields[field_name] = field_type
    
    def get_primary_keys(self, schema: str, table: str) -> List[str]:
        return ['_id']
    
    def get_foreign_keys(self, schema: str, table: str) -> List[Dict[str, Any]]:
        return []
