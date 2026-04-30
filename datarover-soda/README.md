# DataRover Soda Integration

Data Quality scanning using Soda Core for DataRover.

## Quick Start

```bash
# 1. Install dependencies
pip install -r requirements.txt

# 2. Configure connection
cp .env.example .env
# Edit .env with your database credentials

# 3. Test connection
soda test-connection -d postgres_db -c config/postgres_connection.yml

# 4. Run scan
soda scan -d postgres_db -c config/postgres_connection.yml checks/

# Or use Python
python scripts/soda_scanner.py -d postgres_db -c checks/
```

## Start API Service

```bash
python scripts/soda_service.py
# API available at http://localhost:8001
```

## API Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/` | GET | Service info |
| `/health` | GET | Health check |
| `/scan` | POST | Start async scan |
| `/scan/sync` | POST | Run sync scan |
| `/scan/{id}` | GET | Get scan status |
| `/scan/{id}/results` | GET | Get scan results |
| `/results/latest` | GET | Latest results |
| `/results` | GET | List all results |
| `/checks` | GET | List check files |
| `/datasources` | GET | List data sources |

## Directory Structure

```
datarover-soda/
├── config/           # Connection configurations
├── checks/           # Quality check definitions
│   ├── common/       # Reusable checks
│   ├── tables/       # Table-specific checks
│   └── custom/       # Business rules
├── scripts/          # Python scripts
├── results/          # Scan results
└── logs/             # Execution logs
```

## Documentation

See [SODA_CORE_INTEGRATION.md](SODA_CORE_INTEGRATION.md) for full documentation.
