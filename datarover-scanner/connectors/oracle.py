"""Oracle Database Connector"""
import oracledb
from typing import Optional, List, Dict, Any
from .base import BaseConnector

class OracleConnector(BaseConnector):
    def connect(self):
        if self._connection is None:
            dsn = oracledb.makedsn(self.host, self.port, sid=self.sid) if self.sid else oracledb.makedsn(self.host, self.port, service_name=self.database)
            if self.username.upper() == 'SYS':
                self._connection = oracledb.connect(user=self.username, password=self.password, dsn=dsn, mode=oracledb.SYSDBA)
            else:
                self._connection = oracledb.connect(user=self.username, password=self.password, dsn=dsn)
        return self._connection
    
    def disconnect(self):
        if self._connection: self._connection.close(); self._connection = None
    
    def test_connection(self) -> Dict[str, Any]:
        try:
            self.connect()
            cur = self._connection.cursor()
            cur.execute("SELECT USER FROM DUAL"); u = cur.fetchone()[0]
            cur.close(); self.disconnect()
            return {'success': True, 'message': 'OK', 'server_info': {'version': 'Oracle', 'current_user': u, 'database': self.database or self.sid}}
        except Exception as e: self.disconnect(); return {'success': False, 'message': str(e)}
    
    def list_schemas(self) -> List[Dict[str, Any]]:
        self.connect()
        cur = self._connection.cursor()
        cur.execute("SELECT OWNER as name, COUNT(*) as table_count FROM ALL_TABLES WHERE OWNER NOT IN ('SYS','SYSTEM','OUTLN','DIP','ORACLE_OCM','DBSNMP','APPQOSSYS','WMSYS','EXFSYS','CTXSYS','XDB','ANONYMOUS','ORDSYS','ORDDATA','MDSYS','OLAPSYS','LBACSYS','DVSYS','GSMADMIN_INTERNAL','OJVMSYS','AUDSYS','DBSFWUSER') GROUP BY OWNER ORDER BY OWNER")
        schemas = [{'name': r[0], 'table_count': r[1]} for r in cur.fetchall()]
        cur.close(); self.disconnect(); return schemas
    
    def list_tables(self, schema: Optional[str] = None) -> List[Dict[str, Any]]:
        self.connect()
        cur = self._connection.cursor()
        sf = f"='{schema}'" if schema else "NOT IN ('SYS','SYSTEM','OUTLN','XDB')"
        cur.execute(f"SELECT * FROM (SELECT t.OWNER,t.TABLE_NAME,'TABLE' as TABLE_TYPE,t.NUM_ROWS,0 as SIZE_MB,c.COMMENTS FROM ALL_TABLES t LEFT JOIN ALL_TAB_COMMENTS c ON t.OWNER=c.OWNER AND t.TABLE_NAME=c.TABLE_NAME WHERE t.OWNER {sf} UNION ALL SELECT v.OWNER,v.VIEW_NAME,'VIEW',0,0,c.COMMENTS FROM ALL_VIEWS v LEFT JOIN ALL_TAB_COMMENTS c ON v.OWNER=c.OWNER AND v.VIEW_NAME=c.TABLE_NAME WHERE v.OWNER {sf}) ORDER BY 1,2")
        tables = [{'schema_name': r[0], 'table_name': r[1], 'table_type': r[2], 'row_count': r[3] or 0, 'size_mb': r[4], 'comment': r[5]} for r in cur.fetchall()]
        cur.close(); self.disconnect(); return tables
    
    def get_columns(self, schema: str, table: str) -> List[Dict[str, Any]]:
        self.connect()
        cur = self._connection.cursor()
        pks = self.get_primary_keys(schema, table)
        fks = {f['column_name']: f for f in self.get_foreign_keys(schema, table)}
        cur.execute(f"SELECT c.COLUMN_NAME,c.DATA_TYPE,c.DATA_LENGTH,c.DATA_PRECISION,c.DATA_SCALE,c.NULLABLE,c.DATA_DEFAULT,cc.COMMENTS,c.COLUMN_ID FROM ALL_TAB_COLUMNS c LEFT JOIN ALL_COL_COMMENTS cc ON c.OWNER=cc.OWNER AND c.TABLE_NAME=cc.TABLE_NAME AND c.COLUMN_NAME=cc.COLUMN_NAME WHERE c.OWNER='{schema}' AND c.TABLE_NAME='{table}' ORDER BY c.COLUMN_ID")
        result = []
        for r in cur.fetchall():
            fk = fks.get(r[0], {})
            result.append({'column_name': r[0], 'data_type': r[1], 'full_type': f"{r[1]}({r[2]})" if r[2] else r[1], 'max_length': r[2], 'precision': r[3], 'scale': r[4], 'is_nullable': r[5]=='Y', 'is_primary_key': r[0] in pks, 'is_foreign_key': r[0] in fks, 'default_value': str(r[6]).strip() if r[6] else None, 'comment': r[7], 'position': r[8], 'referenced_schema': fk.get('referenced_schema'), 'referenced_table': fk.get('referenced_table'), 'referenced_column': fk.get('referenced_column')})
        cur.close(); self.disconnect(); return result
    
    def get_primary_keys(self, schema: str, table: str) -> List[str]:
        try:
            if not self._connection: self.connect()
            cur = self._connection.cursor()
            cur.execute(f"SELECT cc.COLUMN_NAME FROM ALL_CONSTRAINTS c JOIN ALL_CONS_COLUMNS cc ON c.OWNER=cc.OWNER AND c.CONSTRAINT_NAME=cc.CONSTRAINT_NAME WHERE c.OWNER='{schema}' AND c.TABLE_NAME='{table}' AND c.CONSTRAINT_TYPE='P'")
            r = [x[0] for x in cur.fetchall()]; cur.close(); return r
        except: return []
    
    def get_foreign_keys(self, schema: str, table: str) -> List[Dict[str, Any]]:
        try:
            if not self._connection: self.connect()
            cur = self._connection.cursor()
            cur.execute(f"SELECT cc.COLUMN_NAME,rc.OWNER,rc.TABLE_NAME,rcc.COLUMN_NAME FROM ALL_CONSTRAINTS c JOIN ALL_CONS_COLUMNS cc ON c.OWNER=cc.OWNER AND c.CONSTRAINT_NAME=cc.CONSTRAINT_NAME JOIN ALL_CONSTRAINTS rc ON c.R_OWNER=rc.OWNER AND c.R_CONSTRAINT_NAME=rc.CONSTRAINT_NAME JOIN ALL_CONS_COLUMNS rcc ON rc.OWNER=rcc.OWNER AND rc.CONSTRAINT_NAME=rcc.CONSTRAINT_NAME AND cc.POSITION=rcc.POSITION WHERE c.OWNER='{schema}' AND c.TABLE_NAME='{table}' AND c.CONSTRAINT_TYPE='R'")
            r = [{'column_name': x[0], 'referenced_schema': x[1], 'referenced_table': x[2], 'referenced_column': x[3]} for x in cur.fetchall()]; cur.close(); return r
        except: return []
