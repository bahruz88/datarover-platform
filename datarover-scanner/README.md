# DataRover Scanner

Database metadata extraction microservice for DataRover Data Governance Platform.

## 🚀 Dəstəklənən Verilənlər Bazaları

| Database | Driver | Status | Quraşdırma |
|----------|--------|--------|------------|
| MySQL | mysql-connector-python | ✅ Daxildir | - |
| MariaDB | mysql-connector-python | ✅ Daxildir | - |
| PostgreSQL | psycopg2 | ⚡ Optional | `pip install psycopg2-binary` |
| SQL Server | pymssql | ⚡ Optional | `pip install pymssql` |
| Oracle | oracledb | ⚡ Optional | `pip install oracledb` |

## 📦 Quraşdırma

### 1. Əsas Quraşdırma (MySQL/MariaDB)

```bash
cd datarover-scanner
python -m venv venv
venv\Scripts\activate  # Windows
pip install fastapi uvicorn pydantic mysql-connector-python
```

### 2. Əlavə Database Dəstəyi

```bash
# PostgreSQL
pip install psycopg2-binary

# SQL Server
pip install pymssql

# Oracle (Rust lazımdır - rustup.rs)
pip install oracledb

# Hamısı birlikdə
pip install psycopg2-binary pymssql
```

## 🖥️ İşə Salma

```bash
python main.py
```

Server: http://localhost:8000
API Docs: http://localhost:8000/docs

## 🔌 API Endpoints

| Endpoint | Method | Təsvir |
|----------|--------|--------|
| `/` | GET | Health check |
| `/databases` | GET | Dəstəklənən DB növləri |
| `/test-connection` | POST | Bağlantı testi |
| `/list-schemas` | POST | Schema siyahısı |
| `/list-tables` | POST | Cədvəl siyahısı |
| `/scan` | POST | Tam metadata scan |
| `/get-columns` | POST | Sütun məlumatları |
| `/get-sample-data` | POST | Nümunə data |

## 📁 Struktur

```
datarover-scanner/
├── main.py
├── requirements.txt
├── connectors/
│   ├── base.py        # Abstract connector
│   ├── mysql.py       # MySQL/MariaDB
│   ├── postgresql.py  # PostgreSQL
│   ├── sqlserver.py   # SQL Server
│   └── oracle.py      # Oracle
```
