# 🚀 DQ SCHEDULE SERVICE - SIMPLE VERSION

**Mövcud Quality Rules-ları avtomatik işlədir!**

---

## 📋 NƏDİR?

DataRover-də artıq mövcud Quality Rules-ları schedule edib avtomatik işlətmək üçün servis.

**Sadə Konsept:**
```
Quality Rule artıq var → Schedule yarat → Avtomatik işlə!
```

---

## ✨ XÜSUSİYYƏTLƏR:

- ✅ Mövcud rule-ları seç
- ✅ Vaxt və tezlik təyin et
- ✅ Avtomatik işləyir
- ✅ Execution history
- ✅ Detailed results
- ✅ Manual trigger

---

## 🎯 NECƏ İŞLƏYİR?

### Quality Rule (DataRover-də artıq var):
```
Rule #12:
  ├─ name: "Null Check - Users"
  ├─ connection_id: 5  ← Hansı DB
  └─ query: "SELECT..."
```

### Schedule yaradırsan:
```json
{
  "name": "Daily Check",
  "rule_ids": [12, 15, 22],  ← Rule-ları seç
  "frequency": "daily",
  "run_time": "09:00"
}
```

### Avtomatik işləyir:
```
09:00-da:
  ├─ Rule #12 run (öz connection-ı ilə)
  ├─ Rule #15 run (öz connection-ı ilə)
  └─ Rule #22 run (öz connection-ı ilə)
```

---

## 🚀 QURAŞDIRMA:

### ADDIM 1: Install (2 dəqiqə)
```batch
install.bat
```

**Nə edir:**
- Virtual environment yaradır
- Python packages qurur:
  - Flask
  - APScheduler
  - Requests

### ADDIM 2: Start
```batch
start.bat
```

**Service başlayır port 8001-də!**

---

## 📊 DATABASE:

### SQLite (dq_schedules.db):

**schedules table:**
```sql
- id
- name
- rule_ids (JSON array)  ← [12, 15, 22]
- frequency
- run_time
- enabled
```

**executions table:**
```sql
- id
- schedule_id
- status
- started_at
- completed_at
- total_rules
- passed_rules
- failed_rules
```

**rule_results table:**
```sql
- id
- execution_id
- rule_id
- rule_name
- status
- passed_checks
- failed_checks
```

---

## 🔌 API ENDPOINTS:

### GET /
Health check

### GET /schedules
List all schedules

### POST /schedules
Create schedule
```json
{
  "name": "Daily Check",
  "rule_ids": [12, 15],
  "frequency": "daily",
  "run_time": "09:00",
  "notify_email": "admin@company.az",
  "enabled": true
}
```

### GET /schedules/{id}
Get schedule

### PUT /schedules/{id}
Update schedule

### DELETE /schedules/{id}
Delete schedule

### POST /schedules/{id}/run
Manual trigger

### GET /executions
List executions
- `?schedule_id=1` - Filter by schedule
- `?limit=50` - Limit results

### GET /executions/{id}/results
Get detailed results

---

## ⚙️ EXECUTION FLOW:

```
1. APScheduler trigger
       ↓
2. Read schedule (rule_ids)
       ↓
3. Call DataRover API:
   POST /backend.php?action=run_quality_rules
   Body: {"rule_ids": [12, 15, 22]}
       ↓
4. DataRover executes rules:
   - Each rule uses its own connection_id
   - Connects to appropriate DB
   - Runs query
   - Returns results
       ↓
5. Service saves results:
   - Execution record
   - Per-rule results
       ↓
6. View in UI
```

---

## 📝 CONFIGURATION:

### dq_schedule_simple.py:

```python
# DataRover URL
DATAROVER_URL = "http://localhost/datarover/backend.php"

# Database file
DB_FILE = "dq_schedules.db"

# Port
PORT = 8001
```

**Əgər DataRover başqa yerdədirsə URL-i dəyişin!**

---

## ✅ VERIFICATION:

### Check Service:
```
http://localhost:8001/
```

**Response:**
```json
{
  "service": "DQ Schedule Service",
  "version": "1.0",
  "status": "running",
  "port": 8001
}
```

### Check Schedules:
```
http://localhost:8001/schedules
```

### Manual Test:
```
POST http://localhost:8001/schedules/1/run
```

---

## 🔧 TROUBLESHOOTING:

### Service başlamır?
```
Check:
- Python installed? python --version
- Virtual env created? venv folder exists?
- Packages installed? pip list
```

### "Connection refused"?
```
- Port 8001 açıqdır?
- Service running? Check CMD window
```

### "DataRover API error"?
```
- DataRover işləyir?
- backend.php accessible?
- run_quality_rules endpoint var?
```

---

## 📊 FREQUENCY OPTIONS:

```
"hourly"   → Hər saat
"daily"    → Hər gün
"weekly"   → Həftədə bir (Bazar ertəsi)
"monthly"  → Ayda bir (1-ci gün)
```

---

## 💡 EXAMPLE USAGE:

### Create Daily Schedule:
```bash
curl -X POST http://localhost:8001/schedules \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Daily Production Check",
    "rule_ids": [12, 15, 18],
    "frequency": "daily",
    "run_time": "09:00",
    "enabled": true
  }'
```

### Get Executions:
```bash
curl http://localhost:8001/executions?limit=10
```

### Get Results:
```bash
curl http://localhost:8001/executions/1/results
```

---

## 🎯 KEY POINTS:

1. **No Connection Config Needed**
   - Rules already have connection_id
   - Service just calls DataRover API

2. **Simple Structure**
   - Schedule = Rule IDs + Time
   - Execution = Call existing API

3. **Transparent**
   - Uses DataRover's existing functions
   - No duplicate logic

4. **Lightweight**
   - SQLite storage
   - Minimal dependencies
   - Fast execution

---

## 📁 FILES:

```
dq-schedule-simple/
├── dq_schedule_simple.py  ← Main service
├── install.bat            ← Auto install
├── start.bat              ← Auto start
└── README.md              ← This file
```

---

## 🔄 UPDATES:

Service auto-reloads schedules:
- On create
- On update
- On delete
- On startup

---

## 🎉 SUMMARY:

**Simple = Better!**

- ✅ No complex config
- ✅ Uses existing rules
- ✅ Just schedule them
- ✅ Automatic execution
- ✅ Detailed tracking

---

**PORT: 8001**  
**DATABASE: SQLite**  
**METHOD: API Proxy**  
**RESULT: SIMPLE & EFFECTIVE!** 🎯✨
