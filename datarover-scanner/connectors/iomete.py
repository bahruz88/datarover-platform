"""
iomete Lakehouse Connector (SQLAlchemy driver)

Uses the `py-hive-iomete[sqlalchemy]` package.
Docs: https://iomete.com/resources/user-guide/driver/sql-alchemy-driver
"""

import re
from urllib.parse import quote_plus
from typing import Optional, List, Dict, Any, Tuple

from sqlalchemy import create_engine, text

from .base import BaseConnector


_ROWS_RE = re.compile(r'(\d[\d,]*)\s*rows', re.IGNORECASE)
_BYTES_RE = re.compile(r'(\d[\d,]*)\s*bytes', re.IGNORECASE)


def _parse_statistics(stats: str) -> Tuple[int, float]:
    """Parse Spark `Statistics` string like '12345 bytes, 678 rows'. Returns (rows, size_mb)."""
    if not stats:
        return 0, 0.0
    rows = 0
    size_mb = 0.0
    m = _ROWS_RE.search(stats)
    if m:
        rows = int(m.group(1).replace(',', ''))
    m = _BYTES_RE.search(stats)
    if m:
        size_mb = round(int(m.group(1).replace(',', '')) / 1024 / 1024, 2)
    return rows, size_mb


class IometeConnector(BaseConnector):
    """iomete lakehouse connector using SQLAlchemy + py-hive-iomete"""

    def __init__(self, host: str, port: int = 443, username: str = None,
                 password: str = None, database: str = None,
                 data_plane: str = None, lakehouse: str = None, **kwargs):
        super().__init__(host, port or 443, username, password, database or 'default')
        self.data_plane = data_plane
        self.lakehouse = lakehouse
        self._engine = None

    def _build_url(self) -> str:
        user = quote_plus(self.username or '')
        token = quote_plus(self.password or '')
        db = self.database or 'default'
        params = []
        if self.lakehouse:
            params.append(f"lakehouse={quote_plus(self.lakehouse)}")
        if self.data_plane:
            params.append(f"data_plane={quote_plus(self.data_plane)}")
        query = ("?" + "&".join(params)) if params else ""
        return f"iomete+https://{user}:{token}@{self.host}:{self.port}/{db}{query}"

    def connect(self):
        if self._engine is None:
            self._engine = create_engine(self._build_url())
        if self._connection is None:
            self._connection = self._engine.connect()
        return self._connection

    def disconnect(self):
        if self._connection is not None:
            self._connection.close()
            self._connection = None
        if self._engine is not None:
            self._engine.dispose()
            self._engine = None

    def test_connection(self) -> Dict[str, Any]:
        try:
            self.connect()
            row = self._connection.execute(text("SELECT current_database()")).fetchone()
            db_name = row[0] if row else self.database
            self.disconnect()
            return {
                'success': True,
                'message': 'Connection successful',
                'server_info': {
                    'version': 'iomete Lakehouse',
                    'database': db_name,
                    'lakehouse': self.lakehouse,
                    'data_plane': self.data_plane,
                }
            }
        except Exception as e:
            self.disconnect()
            msg = str(e) or repr(e) or type(e).__name__ or 'Connection failed'
            return {'success': False, 'message': msg}

    def list_schemas(self) -> List[Dict[str, Any]]:
        try:
            self.connect()
            databases = [r[0] for r in self._connection.execute(text("SHOW DATABASES")).fetchall()]
            result = []
            for db_name in databases:
                try:
                    tables = self._connection.execute(text(f"SHOW TABLES IN `{db_name}`")).fetchall()
                    count = len(tables)
                except Exception:
                    count = 0
                result.append({'name': db_name, 'table_count': count})
            self.disconnect()
            return result
        except Exception as e:
            self.disconnect()
            raise e

    def list_tables(self, schema: Optional[str] = None) -> List[Dict[str, Any]]:
        try:
            self.connect()
            db_name = schema or self.database or 'default'
            rows = self._connection.execute(text(f"SHOW TABLES IN `{db_name}`")).fetchall()

            tables = []
            for r in rows:
                # SHOW TABLES returns (database, tableName, isTemporary) on Spark/iomete
                table_name = r[1] if len(r) > 1 else r[0]
                table_type = 'BASE TABLE'
                row_count = 0
                size_mb = 0.0
                try:
                    desc = self._connection.execute(
                        text(f"DESCRIBE TABLE EXTENDED `{db_name}`.`{table_name}`")
                    ).fetchall()
                    for row in desc:
                        col = str(row[0]) if row[0] else ''
                        val = str(row[1]) if len(row) > 1 and row[1] else ''
                        if 'Type' in col and table_type == 'BASE TABLE':
                            if 'VIEW' in val.upper():
                                table_type = 'VIEW'
                        elif col.strip() == 'Statistics':
                            row_count, size_mb = _parse_statistics(val)
                except Exception:
                    pass

                if row_count == 0 and table_type == 'BASE TABLE':
                    try:
                        cnt = self._connection.execute(
                            text(f"SELECT COUNT(*) FROM `{db_name}`.`{table_name}`")
                        ).fetchone()
                        if cnt and cnt[0] is not None:
                            row_count = int(cnt[0])
                    except Exception:
                        pass

                tables.append({
                    'schema_name': db_name,
                    'table_name': table_name,
                    'table_type': table_type,
                    'row_count': row_count,
                    'size_mb': size_mb,
                    'comment': None,
                })
            self.disconnect()
            return tables
        except Exception as e:
            self.disconnect()
            raise e

    def get_columns(self, schema: str, table: str) -> List[Dict[str, Any]]:
        try:
            self.connect()
            rows = self._connection.execute(
                text(f"DESCRIBE TABLE `{schema}`.`{table}`")
            ).fetchall()

            result = []
            position = 0
            for col in rows:
                col_name = (col[0] or '').strip()
                # Stop at partition info / empty separator rows
                if not col_name or col_name.startswith('#'):
                    break
                position += 1
                col_type = (col[1] or 'string').strip()
                comment = col[2].strip() if len(col) > 2 and col[2] else None
                result.append({
                    'column_name': col_name,
                    'data_type': col_type.split('(')[0].lower(),
                    'full_type': col_type,
                    'max_length': None,
                    'precision': None,
                    'scale': None,
                    'is_nullable': True,
                    'is_primary_key': False,
                    'is_foreign_key': False,
                    'default_value': None,
                    'comment': comment,
                    'position': position,
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
