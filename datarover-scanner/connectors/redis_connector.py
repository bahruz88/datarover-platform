"""
Redis Database Connector
"""

import redis
from typing import Optional, List, Dict, Any
from .base import BaseConnector


class RedisConnector(BaseConnector):
    """Redis database connector"""
    
    def connect(self):
        """Establish connection"""
        if self._connection is None:
            self._connection = redis.Redis(
                host=self.host,
                port=self.port or 6379,
                password=self.password,
                db=int(self.database) if self.database else 0,
                decode_responses=True
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
            info = self._connection.info('server')
            self.disconnect()
            
            return {
                'success': True,
                'message': 'Connection successful',
                'server_info': {
                    'version': f"Redis {info.get('redis_version', 'Unknown')}",
                    'database': self.database or '0'
                }
            }
        except Exception as e:
            self.disconnect()
            return {'success': False, 'message': str(e)}
    
    def list_schemas(self) -> List[Dict[str, Any]]:
        """List all databases (0-15)"""
        try:
            self.connect()
            info = self._connection.info('keyspace')
            
            result = []
            for i in range(16):
                db_key = f'db{i}'
                if db_key in info:
                    result.append({
                        'name': str(i),
                        'table_count': info[db_key].get('keys', 0)
                    })
                else:
                    result.append({
                        'name': str(i),
                        'table_count': 0
                    })
            
            self.disconnect()
            return result
        except Exception as e:
            self.disconnect()
            raise e
    
    def list_tables(self, schema: Optional[str] = None) -> List[Dict[str, Any]]:
        """List keys by pattern (Redis has no tables)"""
        try:
            self.connect()
            
            # Get all keys
            keys = self._connection.keys('*')
            
            # Group by prefix
            prefixes = {}
            for key in keys[:1000]:  # Limit to 1000 keys
                prefix = key.split(':')[0] if ':' in key else key
                if prefix not in prefixes:
                    prefixes[prefix] = 0
                prefixes[prefix] += 1
            
            tables = []
            for prefix, count in prefixes.items():
                tables.append({
                    'schema_name': schema or self.database or '0',
                    'table_name': prefix,
                    'table_type': 'KEY_PREFIX',
                    'row_count': count,
                    'size_mb': 0,
                    'comment': f'{count} keys'
                })
            
            self.disconnect()
            return tables
        except Exception as e:
            self.disconnect()
            raise e
    
    def get_columns(self, schema: str, table: str) -> List[Dict[str, Any]]:
        """Get sample key structure"""
        try:
            self.connect()
            
            # Find sample keys with this prefix
            keys = self._connection.keys(f'{table}:*')[:10]
            if not keys:
                keys = self._connection.keys(f'{table}*')[:10]
            
            if not keys:
                return [{
                    'column_name': 'value',
                    'data_type': 'string',
                    'full_type': 'STRING',
                    'is_nullable': True,
                    'is_primary_key': False,
                    'position': 1
                }]
            
            # Check type of first key
            key = keys[0]
            key_type = self._connection.type(key)
            
            result = [{
                'column_name': 'key',
                'data_type': 'string',
                'full_type': 'STRING',
                'is_nullable': False,
                'is_primary_key': True,
                'position': 1
            }]
            
            if key_type == 'hash':
                fields = self._connection.hkeys(key)
                for i, field in enumerate(fields):
                    result.append({
                        'column_name': field,
                        'data_type': 'string',
                        'full_type': 'HASH_FIELD',
                        'is_nullable': True,
                        'is_primary_key': False,
                        'position': i + 2
                    })
            else:
                result.append({
                    'column_name': 'value',
                    'data_type': key_type,
                    'full_type': key_type.upper(),
                    'is_nullable': True,
                    'is_primary_key': False,
                    'position': 2
                })
            
            self.disconnect()
            return result
        except Exception as e:
            self.disconnect()
            raise e
    
    def get_primary_keys(self, schema: str, table: str) -> List[str]:
        return ['key']
    
    def get_foreign_keys(self, schema: str, table: str) -> List[Dict[str, Any]]:
        return []
