"""PostgreSQL Database Connector"""
import psycopg2
import psycopg2.extras
from typing import Optional, List, Dict, Any
from .base import BaseConnector

class PostgreSQLConnector(BaseConnector):
    def connect(self):
        if self._connection is None:
            params = {'host': self.host, 'port': self.port, 'user': self.username, 'password': self.password, 'database': self.database or 'postgres'}
            if any(c in self.host for c in ['neon.tech', 'supabase', 'aws.', 'azure.', 'cloud', 'render']): params['sslmode'] = 'require'
            self._connection = psycopg2.connect(**params)
        return self._connection
    
    def disconnect(self):
        if self._connection: self._connection.close(); self._connection = None
    
    def test_connection(self) -> Dict[str, Any]:
        try:
            self.connect()
            cur = self._connection.cursor(cursor_factory=psycopg2.extras.RealDictCursor)
            cur.execute("SELECT version()"); v = cur.fetchone()['version'].split(',')[0]
            cur.execute("SELECT current_database()"); d = cur.fetchone()['current_database']
            cur.close(); self.disconnect()
            return {'success': True, 'message': 'OK', 'server_info': {'version': v, 'database': d}}
        except Exception as e: self.disconnect(); return {'success': False, 'message': str(e)}
    
    def list_schemas(self) -> List[Dict[str, Any]]:
        self.connect()
        cur = self._connection.cursor(cursor_factory=psycopg2.extras.RealDictCursor)
        cur.execute("SELECT schema_name as name FROM information_schema.schemata WHERE schema_name NOT IN ('pg_catalog','information_schema','pg_toast')")
        schemas = [dict(s) for s in cur.fetchall()]
        for s in schemas:
            cur.execute("SELECT COUNT(*) as c FROM information_schema.tables WHERE table_schema=%s", (s['name'],))
            s['table_count'] = cur.fetchone()['c']
        cur.close(); self.disconnect(); return schemas
    
    def list_tables(self, schema: Optional[str] = None) -> List[Dict[str, Any]]:
        self.connect()
        cur = self._connection.cursor(cursor_factory=psycopg2.extras.RealDictCursor)
        if schema:
            cur.execute("SELECT table_schema as schema_name,table_name,CASE WHEN table_type='BASE TABLE' THEN 'BASE TABLE' ELSE 'VIEW' END as table_type FROM information_schema.tables WHERE table_schema=%s", (schema,))
        else:
            cur.execute("SELECT table_schema as schema_name,table_name,CASE WHEN table_type='BASE TABLE' THEN 'BASE TABLE' ELSE 'VIEW' END as table_type FROM information_schema.tables WHERE table_schema NOT IN ('pg_catalog','information_schema')")
        tables = [{'schema_name': t['schema_name'], 'table_name': t['table_name'], 'table_type': t['table_type'], 'row_count': 0, 'size_mb': 0, 'comment': None} for t in cur.fetchall()]
        cur.close(); self.disconnect(); return tables
    
    def get_columns(self, schema: str, table: str) -> List[Dict[str, Any]]:
        self.connect()
        cur = self._connection.cursor(cursor_factory=psycopg2.extras.RealDictCursor)
        pks = self.get_primary_keys(schema, table)
        fks = {f['column_name']: f for f in self.get_foreign_keys(schema, table)}
        cur.execute("SELECT column_name,data_type,udt_name as full_type,character_maximum_length as max_length,numeric_precision,numeric_scale,is_nullable,column_default,ordinal_position as position FROM information_schema.columns WHERE table_schema=%s AND table_name=%s ORDER BY ordinal_position", (schema, table))
        result = []
        for c in cur.fetchall():
            fk = fks.get(c['column_name'], {})
            result.append({'column_name': c['column_name'], 'data_type': c['data_type'], 'full_type': c['full_type'], 'max_length': c['max_length'], 'precision': c['numeric_precision'], 'scale': c['numeric_scale'], 'is_nullable': c['is_nullable']=='YES', 'is_primary_key': c['column_name'] in pks, 'is_foreign_key': c['column_name'] in fks, 'default_value': c['column_default'], 'comment': None, 'position': c['position'], 'referenced_schema': fk.get('referenced_schema'), 'referenced_table': fk.get('referenced_table'), 'referenced_column': fk.get('referenced_column')})
        cur.close(); self.disconnect(); return result
    
    def get_primary_keys(self, schema: str, table: str) -> List[str]:
        try:
            if not self._connection: self.connect()
            cur = self._connection.cursor(cursor_factory=psycopg2.extras.RealDictCursor)
            cur.execute("SELECT a.attname FROM pg_index i JOIN pg_attribute a ON a.attrelid=i.indrelid AND a.attnum=ANY(i.indkey) JOIN pg_class c ON c.oid=i.indrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE i.indisprimary AND n.nspname=%s AND c.relname=%s", (schema, table))
            r = [x['attname'] for x in cur.fetchall()]; cur.close(); return r
        except: return []
    
    def get_foreign_keys(self, schema: str, table: str) -> List[Dict[str, Any]]:
        try:
            if not self._connection: self.connect()
            cur = self._connection.cursor(cursor_factory=psycopg2.extras.RealDictCursor)
            cur.execute("SELECT kcu.column_name,ccu.table_schema as referenced_schema,ccu.table_name as referenced_table,ccu.column_name as referenced_column FROM information_schema.table_constraints tc JOIN information_schema.key_column_usage kcu ON tc.constraint_name=kcu.constraint_name JOIN information_schema.constraint_column_usage ccu ON tc.constraint_name=ccu.constraint_name WHERE tc.constraint_type='FOREIGN KEY' AND tc.table_schema=%s AND tc.table_name=%s", (schema, table))
            r = [dict(x) for x in cur.fetchall()]; cur.close(); return r
        except: return []
