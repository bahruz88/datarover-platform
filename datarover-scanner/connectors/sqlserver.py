"""SQL Server Database Connector"""
import pymssql
from typing import Optional, List, Dict, Any
from .base import BaseConnector

class SQLServerConnector(BaseConnector):
    def connect(self):
        if self._connection is None:
            self._connection = pymssql.connect(server=self.host, port=str(self.port), user=self.username, password=self.password, database=self.database or 'master')
        return self._connection
    
    def disconnect(self):
        if self._connection: self._connection.close(); self._connection = None
    
    def test_connection(self) -> Dict[str, Any]:
        try:
            self.connect()
            cur = self._connection.cursor(as_dict=True)
            cur.execute("SELECT @@VERSION as v, DB_NAME() as d"); r = cur.fetchone()
            cur.close(); self.disconnect()
            return {'success': True, 'message': 'OK', 'server_info': {'version': r['v'].split('\n')[0], 'database': r['d']}}
        except Exception as e: self.disconnect(); return {'success': False, 'message': str(e)}
    
    def list_schemas(self) -> List[Dict[str, Any]]:
        self.connect()
        cur = self._connection.cursor(as_dict=True)
        cur.execute("SELECT s.name FROM sys.schemas s WHERE s.name NOT IN ('sys','INFORMATION_SCHEMA','guest') AND s.name NOT LIKE 'db_%'")
        schemas = []
        for s in cur.fetchall():
            cur.execute(f"SELECT COUNT(*) as c FROM sys.tables t JOIN sys.schemas sch ON t.schema_id=sch.schema_id WHERE sch.name='{s['name']}'")
            schemas.append({'name': s['name'], 'table_count': cur.fetchone()['c']})
        cur.close(); self.disconnect(); return schemas
    
    def list_tables(self, schema: Optional[str] = None) -> List[Dict[str, Any]]:
        self.connect()
        cur = self._connection.cursor(as_dict=True)
        if schema:
            cur.execute(f"SELECT s.name as schema_name,t.name as table_name,'BASE TABLE' as table_type,p.rows as row_count,0 as size_mb FROM sys.tables t JOIN sys.schemas s ON t.schema_id=s.schema_id JOIN sys.indexes i ON t.object_id=i.object_id JOIN sys.partitions p ON i.object_id=p.object_id AND i.index_id=p.index_id WHERE s.name='{schema}' AND i.index_id<=1")
        else:
            cur.execute("SELECT s.name as schema_name,t.name as table_name,'BASE TABLE' as table_type,p.rows as row_count,0 as size_mb FROM sys.tables t JOIN sys.schemas s ON t.schema_id=s.schema_id JOIN sys.indexes i ON t.object_id=i.object_id JOIN sys.partitions p ON i.object_id=p.object_id AND i.index_id=p.index_id WHERE s.name NOT IN ('sys','INFORMATION_SCHEMA') AND i.index_id<=1")
        tables = [{'schema_name': t['schema_name'], 'table_name': t['table_name'], 'table_type': t['table_type'], 'row_count': t['row_count'] or 0, 'size_mb': 0, 'comment': None} for t in cur.fetchall()]
        cur.close(); self.disconnect(); return tables
    
    def get_columns(self, schema: str, table: str) -> List[Dict[str, Any]]:
        self.connect()
        cur = self._connection.cursor(as_dict=True)
        pks = self.get_primary_keys(schema, table)
        fks = {f['column_name']: f for f in self.get_foreign_keys(schema, table)}
        cur.execute(f"SELECT c.name as column_name,t.name as data_type,c.max_length,c.precision,c.scale,c.is_nullable,dc.definition as default_value,c.column_id as position FROM sys.columns c JOIN sys.types t ON c.user_type_id=t.user_type_id JOIN sys.tables tbl ON c.object_id=tbl.object_id JOIN sys.schemas s ON tbl.schema_id=s.schema_id LEFT JOIN sys.default_constraints dc ON c.default_object_id=dc.object_id WHERE s.name='{schema}' AND tbl.name='{table}' ORDER BY c.column_id")
        result = []
        for c in cur.fetchall():
            fk = fks.get(c['column_name'], {})
            ft = c['data_type']
            if c['data_type'] in ('varchar','nvarchar','char','nchar'): ft = f"{c['data_type']}({c['max_length'] if c['max_length']>0 else 'MAX'})"
            result.append({'column_name': c['column_name'], 'data_type': c['data_type'], 'full_type': ft, 'max_length': c['max_length'], 'precision': c['precision'], 'scale': c['scale'], 'is_nullable': bool(c['is_nullable']), 'is_primary_key': c['column_name'] in pks, 'is_foreign_key': c['column_name'] in fks, 'default_value': c['default_value'], 'comment': None, 'position': c['position'], 'referenced_schema': fk.get('referenced_schema'), 'referenced_table': fk.get('referenced_table'), 'referenced_column': fk.get('referenced_column')})
        cur.close(); self.disconnect(); return result
    
    def get_primary_keys(self, schema: str, table: str) -> List[str]:
        try:
            if not self._connection: self.connect()
            cur = self._connection.cursor(as_dict=True)
            cur.execute(f"SELECT col.name FROM sys.indexes idx JOIN sys.index_columns ic ON idx.object_id=ic.object_id AND idx.index_id=ic.index_id JOIN sys.columns col ON ic.object_id=col.object_id AND ic.column_id=col.column_id JOIN sys.tables t ON idx.object_id=t.object_id JOIN sys.schemas s ON t.schema_id=s.schema_id WHERE idx.is_primary_key=1 AND s.name='{schema}' AND t.name='{table}'")
            r = [x['name'] for x in cur.fetchall()]; cur.close(); return r
        except: return []
    
    def get_foreign_keys(self, schema: str, table: str) -> List[Dict[str, Any]]:
        try:
            if not self._connection: self.connect()
            cur = self._connection.cursor(as_dict=True)
            cur.execute(f"SELECT col.name as column_name,rs.name as referenced_schema,rt.name as referenced_table,rc.name as referenced_column FROM sys.foreign_key_columns fkc JOIN sys.foreign_keys fk ON fkc.constraint_object_id=fk.object_id JOIN sys.tables t ON fkc.parent_object_id=t.object_id JOIN sys.schemas s ON t.schema_id=s.schema_id JOIN sys.columns col ON fkc.parent_object_id=col.object_id AND fkc.parent_column_id=col.column_id JOIN sys.tables rt ON fkc.referenced_object_id=rt.object_id JOIN sys.schemas rs ON rt.schema_id=rs.schema_id JOIN sys.columns rc ON fkc.referenced_object_id=rc.object_id AND fkc.referenced_column_id=rc.column_id WHERE s.name='{schema}' AND t.name='{table}'")
            r = [dict(x) for x in cur.fetchall()]; cur.close(); return r
        except: return []
