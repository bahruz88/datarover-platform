"""MySQL/MariaDB Database Connector"""
import mysql.connector
from typing import Optional, List, Dict, Any
from .base import BaseConnector

class MySQLConnector(BaseConnector):
    def connect(self):
        if self._connection is None:
            self._connection = mysql.connector.connect(host=self.host, port=self.port, user=self.username, password=self.password, database=self.database)
        return self._connection
    
    def disconnect(self):
        if self._connection: self._connection.close(); self._connection = None
    
    def test_connection(self) -> Dict[str, Any]:
        try:
            self.connect()
            cur = self._connection.cursor(dictionary=True)
            cur.execute("SELECT VERSION() as v, DATABASE() as d")
            r = cur.fetchone(); cur.close(); self.disconnect()
            return {'success': True, 'message': 'OK', 'server_info': {'version': r['v'], 'database': r['d']}}
        except Exception as e: self.disconnect(); return {'success': False, 'message': str(e)}
    
    def list_schemas(self) -> List[Dict[str, Any]]:
        self.connect()
        cur = self._connection.cursor(dictionary=True)
        cur.execute("SELECT SCHEMA_NAME as name FROM information_schema.SCHEMATA WHERE SCHEMA_NAME NOT IN ('information_schema','mysql','performance_schema','sys')")
        schemas = cur.fetchall()
        for s in schemas:
            cur.execute(f"SELECT COUNT(*) as c FROM information_schema.TABLES WHERE TABLE_SCHEMA='{s['name']}'")
            s['table_count'] = cur.fetchone()['c']
        cur.close(); self.disconnect(); return schemas
    
    def list_tables(self, schema: Optional[str] = None) -> List[Dict[str, Any]]:
        self.connect()
        cur = self._connection.cursor(dictionary=True)
        sf = f"='{schema}'" if schema else "NOT IN ('information_schema','mysql','performance_schema','sys')"
        cur.execute(f"SELECT TABLE_SCHEMA as schema_name,TABLE_NAME as table_name,TABLE_TYPE as table_type,TABLE_ROWS as row_count,ROUND((DATA_LENGTH+INDEX_LENGTH)/1024/1024,2) as size_mb,TABLE_COMMENT as comment FROM information_schema.TABLES WHERE TABLE_SCHEMA {sf}")
        t = cur.fetchall(); cur.close(); self.disconnect(); return t
    
    def get_columns(self, schema: str, table: str) -> List[Dict[str, Any]]:
        self.connect()
        cur = self._connection.cursor(dictionary=True)
        pks = self.get_primary_keys(schema, table)
        fks = {f['column_name']: f for f in self.get_foreign_keys(schema, table)}
        cur.execute(f"SELECT COLUMN_NAME as column_name,DATA_TYPE as data_type,COLUMN_TYPE as full_type,CHARACTER_MAXIMUM_LENGTH as max_length,NUMERIC_PRECISION as p,NUMERIC_SCALE as s,IS_NULLABLE as nullable,COLUMN_DEFAULT as def_val,COLUMN_COMMENT as comment,ORDINAL_POSITION as pos FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='{schema}' AND TABLE_NAME='{table}' ORDER BY ORDINAL_POSITION")
        result = []
        for c in cur.fetchall():
            fk = fks.get(c['column_name'], {})
            result.append({'column_name': c['column_name'], 'data_type': c['data_type'], 'full_type': c['full_type'], 'max_length': c['max_length'], 'precision': c['p'], 'scale': c['s'], 'is_nullable': c['nullable']=='YES', 'is_primary_key': c['column_name'] in pks, 'is_foreign_key': c['column_name'] in fks, 'default_value': c['def_val'], 'comment': c['comment'], 'position': c['pos'], 'referenced_schema': fk.get('referenced_schema'), 'referenced_table': fk.get('referenced_table'), 'referenced_column': fk.get('referenced_column')})
        cur.close(); self.disconnect(); return result
    
    def get_primary_keys(self, schema: str, table: str) -> List[str]:
        try:
            if not self._connection: self.connect()
            cur = self._connection.cursor(dictionary=True)
            cur.execute(f"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='{schema}' AND TABLE_NAME='{table}' AND CONSTRAINT_NAME='PRIMARY'")
            r = [x['COLUMN_NAME'] for x in cur.fetchall()]; cur.close(); return r
        except: return []
    
    def get_foreign_keys(self, schema: str, table: str) -> List[Dict[str, Any]]:
        try:
            if not self._connection: self.connect()
            cur = self._connection.cursor(dictionary=True)
            cur.execute(f"SELECT COLUMN_NAME as column_name,REFERENCED_TABLE_SCHEMA as referenced_schema,REFERENCED_TABLE_NAME as referenced_table,REFERENCED_COLUMN_NAME as referenced_column FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='{schema}' AND TABLE_NAME='{table}' AND REFERENCED_TABLE_NAME IS NOT NULL")
            r = cur.fetchall(); cur.close(); return r
        except: return []
