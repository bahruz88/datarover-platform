# 🔑 DataRover Login Credentials

## 📝 Hamısının Şifrəsi: `Demo@123`

---

## 👥 BÜTÜN USERLƏR (Username/Email eynidir):

### 👑 **Admin**
```
Username: admin
Password: Demo@123
```

---

### 🔒 **Data Governance Manager** (2 user)
```
Username: governance.manager@datarover.az
Password: Demo@123
Name: Nigar Əliyeva
```

```
Username: governance.officer@datarover.az
Password: Demo@123
Name: Rəşad Məmmədov
```

---

### ✅ **Data Quality Manager** (2 user)
```
Username: quality.manager@datarover.az
Password: Demo@123
Name: Leyla Həsənova
```

```
Username: quality.analyst@datarover.az
Password: Demo@123
Name: Elvin Quliyev
```

---

### 📚 **Data Steward** (2 user)
```
Username: steward.finance@datarover.az
Password: Demo@123
Name: Aynur Babayeva
```

```
Username: steward.hr@datarover.az
Password: Demo@123
Name: Kamran İsmayılov
```

---

### 🛠️ **Data Engineer** (2 user)
```
Username: engineer.senior@datarover.az
Password: Demo@123
Name: Əli Cəfərov
```

```
Username: engineer.junior@datarover.az
Password: Demo@123
Name: Səbinə Nəsirova
```

---

### 📊 **Business Analyst** (2 user)
```
Username: analyst.sales@datarover.az
Password: Demo@123
Name: Günel Orucova
```

```
Username: analyst.marketing@datarover.az
Password: Demo@123
Name: Tural Həşimov
```

---

### ⚖️ **Compliance Officer** (1 user)
```
Username: compliance@datarover.az
Password: Demo@123
Name: Aysel Rəhimova
```

---

### 👀 **Report Viewer** (2 user)
```
Username: viewer.executive@datarover.az
Password: Demo@123
Name: Fərid Əliyev
```

```
Username: viewer.reports@datarover.az
Password: Demo@123
Name: Sevda Məmmədova
```

---

## 📋 QISA SIYAHI (Copy-Paste üçün):

```
admin / Demo@123
governance.manager@datarover.az / Demo@123
governance.officer@datarover.az / Demo@123
quality.manager@datarover.az / Demo@123
quality.analyst@datarover.az / Demo@123
steward.finance@datarover.az / Demo@123
steward.hr@datarover.az / Demo@123
engineer.senior@datarover.az / Demo@123
engineer.junior@datarover.az / Demo@123
analyst.sales@datarover.az / Demo@123
analyst.marketing@datarover.az / Demo@123
compliance@datarover.az / Demo@123
viewer.executive@datarover.az / Demo@123
viewer.reports@datarover.az / Demo@123
```

---

## 🧪 TEST LOGIN (JSON):

### Governance Manager:
```json
{
  "username": "governance.manager@datarover.az",
  "password": "Demo@123"
}
```

### Quality Manager:
```json
{
  "username": "quality.manager@datarover.az",
  "password": "Demo@123"
}
```

### Data Engineer:
```json
{
  "username": "engineer.senior@datarover.az",
  "password": "Demo@123"
}
```

### Viewer:
```json
{
  "username": "viewer.executive@datarover.az",
  "password": "Demo@123"
}
```

---

## ✅ DƏYİŞİKLİK (v37 fixed):

**Problem:** Username ≠ Email idi  
**Həll:** Username = Email oldu  

**Əvvəl:**
- Username: `gov.manager`
- Email: `governance.manager@datarover.az`
- ❌ Qarışıqlıq!

**İndi:**
- Username: `governance.manager@datarover.az`
- Email: `governance.manager@datarover.az`
- ✅ Asan!

---

## 🎯 LOGİN NÜMUNƏ:

```bash
# cURL ilə
curl -X POST http://localhost/datarover/backend.php?action=login \
  -H "Content-Type: application/json" \
  -d '{"username":"governance.manager@datarover.az","password":"Demo@123"}'

# Response:
{
  "success": true,
  "user": {
    "id": 2,
    "username": "governance.manager@datarover.az",
    "email": "governance.manager@datarover.az",
    "first_name": "Nigar",
    "last_name": "Əliyeva",
    "role": "Data Governance Manager"
  },
  "token": "..."
}
```

---

**İNDİ İŞLƏYƏCƏK!** ✅
