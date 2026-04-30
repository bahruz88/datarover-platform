# 🔐 DataRover - Role-Based Access Control (RBAC) System

## 📋 TƏSVİR

DataRover üçün tam funksional rol əsaslı giriş nəzarət sistemi. 8 fərqli rol, 50+ icazə (permission) və Data Governance vs Non-Governance fokuslu istifadəçi təcrübəsi.

---

## 🎭 ROLLAR (8 ROL)

### 1️⃣ **Super Administrator** (`SUPER_ADMIN`)
**İstifadə:** System administrator  
**Səlahiyyətlər:** ⭐ Hər şeyə tam giriş  
**İstifadəçilər:**
- admin@datarover.az (Admin)

---

### 2️⃣ **Data Governance Manager** (`GOV_MANAGER`) 🔒
**İstifadə:** Data governance siyasətləri və standartlarını idarə edir  
**Fokus:** DATA GOVERNANCE  
**Səlahiyyətlər:**
- ✅ Governance: Tam giriş (view, create, edit, delete, approve)
- ✅ Quality: Görüntüləmə və təsdiq (compliance monitor)
- ✅ Glossary: Tam giriş (terms & standards)
- ✅ Catalog/Lineage: Görüntüləmə (data landscape)
- ✅ Reports/Audit: Görüntüləmə və export
- ✅ Dashboard

**İstifadəçilər:**
- governance.manager@datarover.az (Nigar Əliyeva)
- governance.officer@datarover.az (Rəşad Məmmədov)

**Tipik vəzifələr:**
- Data governance policy yaratmaq
- Compliance standartları təyin etmək
- Quality rules approve etmək
- Governance reporting

---

### 3️⃣ **Data Quality Manager** (`QUALITY_MANAGER`) ✅
**İstifadə:** Data quality qaydaları və monitoring  
**Fokus:** DATA QUALITY  
**Səlahiyyətlər:**
- ✅ Quality: Tam giriş (view, create, edit, delete, execute, approve)
- ✅ Governance: Görüntüləmə (policies understand)
- ✅ Glossary: Görüntüləmə
- ✅ Catalog/Lineage: Görüntüləmə
- ✅ Reports: Tam giriş
- ✅ Dashboard

**İstifadəçilər:**
- quality.manager@datarover.az (Leyla Həsənova)
- quality.analyst@datarover.az (Elvin Quliyev)

**Tipik vəzifələr:**
- Data quality rules yaratmaq
- Quality scans run etmək
- Quality scores monitor etmək
- Quality reports generate etmək

---

### 4️⃣ **Data Steward** (`DATA_STEWARD`) 📚
**İstifadə:** Catalog və glossary maintenance  
**Fokus:** DATA DOCUMENTATION  
**Səlahiyyətlər:**
- ✅ Catalog: Tam giriş
- ✅ Glossary: Tam giriş
- ✅ Lineage/Mapper: Görüntüləmə
- ✅ Quality/Governance: Görüntüləmə
- ✅ Reports: Görüntüləmə və export
- ✅ Dashboard

**İstifadəçilər:**
- steward.finance@datarover.az (Aynur Babayeva)
- steward.hr@datarover.az (Kamran İsmayılov)

**Tipik vəzifələr:**
- Catalog metadata update
- Business terms define
- Data documentation
- Column descriptions

---

### 5️⃣ **Data Engineer** (`DATA_ENGINEER`) 🛠️
**İstifadə:** Technical data infrastructure  
**Fokus:** TECHNICAL/ENGINEERING  
**Səlahiyyətlər:**
- ✅ Catalog: Tam giriş
- ✅ Lineage/Mapper: Tam giriş
- ✅ Quality: Execute checks
- ✅ Governance/Glossary: Görüntüləmə
- ✅ API: Access
- ✅ Dashboard

**İstifadəçilər:**
- engineer.senior@datarover.az (Əli Cəfərov)
- engineer.junior@datarover.az (Səbinə Nəsirova)

**Tipik vəzifələr:**
- Column mappings yaratmaq
- Data lineage build
- Catalog structure
- Integration work

---

### 6️⃣ **Business Analyst** (`ANALYST`) 📊
**İstifadə:** Data analysis və reporting  
**Fokus:** BUSINESS ANALYSIS  
**Səlahiyyətlər:**
- ✅ View: Hər şeyi görüntüləyir
- ✅ Reports: Export edə bilir
- ✅ Quality: Execute checks

**İstifadəçilər:**
- analyst.sales@datarover.az (Günel Orucova)
- analyst.marketing@datarover.az (Tural Həşimov)

**Tipik vəzifələr:**
- Reports review
- Ad-hoc quality checks
- Data analysis
- Export reports

---

### 7️⃣ **Compliance Officer** (`COMPLIANCE`) ⚖️
**İstifadə:** Compliance və audit  
**Fokus:** COMPLIANCE & AUDIT  
**Səlahiyyətlər:**
- ✅ Governance: Görüntüləmə
- ✅ Quality: Görüntüləmə
- ✅ Audit: Tam giriş
- ✅ Reports: Görüntüləmə və export
- ✅ Glossary: Görüntüləmə
- ✅ Dashboard

**İstifadəçilər:**
- compliance@datarover.az (Aysel Rəhimova)

**Tipik vəzifələr:**
- Compliance review
- Audit log monitoring
- Governance compliance check
- Regulatory reporting

---

### 8️⃣ **Report Viewer** (`VIEWER`) 👀
**İstifadə:** Read-only giriş  
**Fokus:** VIEW-ONLY  
**Səlahiyyətlər:**
- ✅ View: Dashboard, catalog, lineage, glossary, quality, governance
- ❌ Create/Edit/Delete: Heç nə

**İstifadəçilər:**
- viewer.executive@datarover.az (Fərid Əliyev)
- viewer.reports@datarover.az (Sevda Məmmədova)

**Tipik vəzifələr:**
- Dashboard view
- Reports review
- Read-only access

---

## 🔑 LOGİN CREDENTİALS

**Hamısının şifrəsi:** `Demo@123`

### 👑 Admin
```
Username: admin
Email: admin@datarover.az
```

### 🔒 Data Governance
```
Username: governance.manager@datarover.az
Email: governance.manager@datarover.az
```

### ✅ Data Quality
```
Username: quality.manager
Email: quality.manager@datarover.az
```

### 📚 Data Steward
```
Username: steward.finance
Email: steward.finance@datarover.az
```

### 🛠️ Data Engineer
```
Username: engineer.senior
Email: engineer.senior@datarover.az
```

### 📊 Business Analyst
```
Username: analyst.sales
Email: analyst.sales@datarover.az
```

### ⚖️ Compliance
```
Username: compliance.officer
Email: compliance@datarover.az
```

### 👀 Viewer
```
Username: viewer.exec
Email: viewer.executive@datarover.az
```

---

## 📂 FİLE STRUKTURU

```
datarover/
├── roles_and_users_setup.sql       ← Database setup
├── auth_middleware.php             ← Backend permission checks
└── js/
    └── permissions.js              ← Frontend permission checks
```

---

## 🚀 QURULUM

### 1️⃣ **Database Setup**

```bash
# MySQL-ə daxil ol
mysql -u root -p datarover

# SQL script-i run et
source /path/to/roles_and_users_setup.sql
```

**Nəticə:**
- 8 rol yaradılacaq
- 50+ permission yaradılacaq
- 14 demo user yaradılacaq
- Role-permission assignments

### 2️⃣ **Backend Integration**

`backend.php`-ə əlavə et:

```php
<?php
// Include auth middleware
require_once 'auth_middleware.php';

// Initialize authentication
initAuth();

// Your existing code...

switch ($action) {
    // Add permission checks to existing endpoints
    case 'catalog_tables':
        requireAuth();
        requirePermission('catalog', 'view');
        // ... existing code
        break;
        
    case 'create_table':
        requireAuth();
        requirePermission('catalog', 'create');
        // ... existing code
        break;
        
    case 'quality_rules':
        requireAuth();
        
        if ($method === 'GET') {
            requirePermission('quality', 'view');
        } elseif ($method === 'POST') {
            requirePermission('quality', 'create');
        } elseif ($method === 'PUT') {
            requirePermission('quality', 'edit');
        } elseif ($method === 'DELETE') {
            requirePermission('quality', 'delete');
        }
        // ... existing code
        break;
        
    // Add user permissions endpoint
    case 'user_permissions':
        requireAuth();
        echo json_encode([
            'success' => true,
            'data' => getUserPermissionsForUI()
        ]);
        break;
}
?>
```

### 3️⃣ **Frontend Integration**

`index.html`-ə script əlavə et:

```html
<script src="js/permissions.js"></script>
```

`app.js`-də initialize et:

```javascript
// App initialization
window.addEventListener('DOMContentLoaded', async function() {
    // Initialize permission system
    await initPermissionSystem();
    
    // Your existing init code...
});
```

### 4️⃣ **UI Permission Control**

#### **HTML data-attributes:**

```html
<!-- Show only if user has permission -->
<button data-permission="catalog.create" onclick="addTable()">
    ➕ Add Table
</button>

<!-- Show only for specific role -->
<div data-role="GOV_MANAGER">
    🔒 Governance Dashboard
</div>

<!-- Show if user can perform action -->
<button data-can="executeQuality" onclick="runQualityCheck()">
    ▶️ Run Quality Check
</button>
```

#### **JavaScript checks:**

```javascript
// Check permission before action
function deleteTable(table) {
    if (!can('deleteCatalog')) {
        alert('Permission denied!');
        return;
    }
    // ... delete logic
}

// Role-specific UI
if (hasRole('GOV_MANAGER')) {
    showGovernancePanel();
} else if (hasRole('DATA_ENGINEER')) {
    showEngineeringPanel();
}

// Conditional button rendering
var html = '';
if (can('createQuality')) {
    html += '<button>Create Quality Rule</button>';
}
```

---

## 🎯 DATA GOVERNANCE vs NON-GOVERNANCE

### **Data Governance Fokuslu Rollar:**

- 🔒 **Data Governance Manager** - Policies & standards
- ⚖️ **Compliance Officer** - Audit & compliance
- 📚 **Data Steward** - Documentation & glossary

**Xüsusiyyətləri:**
- Governance module-ə tam giriş
- Quality-ni approve edə bilər
- Glossary-ni manage edir
- Compliance reporting

---

### **Non-Governance Rollar:**

- ✅ **Data Quality Manager** - Quality focus
- 🛠️ **Data Engineer** - Technical focus
- 📊 **Business Analyst** - Analysis focus
- 👀 **Report Viewer** - Read-only

**Xüsusiyyətləri:**
- Governance-i görür amma edit edə bilməz
- Quality rules yaradır
- Technical infrastructure
- Data analysis

---

## 📊 PERMİSSİON SUMMARY

### **Module Breakdown:**

| Module | Permissions |
|--------|-------------|
| Dashboard | view |
| Catalog | view, create, edit, delete |
| Lineage | view, create, edit, delete |
| Mapper | view, create, edit, delete |
| Glossary | view, create, edit, delete, approve |
| **Governance** | view, create, edit, delete, approve |
| **Quality** | view, create, edit, delete, execute, approve |
| Reports | view, create, edit, delete, export |
| Users | view, create, edit, delete |
| Roles | view, create, edit, delete |
| Audit | view, export |
| Settings | view, edit |
| API | access |

**Cəmi:** 53 unique permission

---

## 🧪 TEST ETM

### **1. Login Test:**

```bash
# Hər roldan login ol
# URL: http://localhost/datarover/login
# Username: gov.manager
# Password: Demo@123
```

### **2. Permission Test:**

**Data Governance Manager kimi:**
- ✅ Governance create edə bilməli
- ✅ Quality approve edə bilməli
- ❌ Catalog create edə bilməməli
- ❌ User management görmməli

**Data Engineer kimi:**
- ✅ Catalog create edə bilməli
- ✅ Lineage create edə bilməli
- ❌ Governance edit edə bilməməli
- ❌ Quality approve edə bilməməli

**Viewer kimi:**
- ✅ Hər şeyi görə bilməli
- ❌ Heç nə edit edə bilməməli
- ❌ Create/Delete buttonlar görməməli

### **3. Backend Test:**

```bash
# Test permission endpoint
curl http://localhost/datarover/backend.php?action=user_permissions

# Should return:
{
  "success": true,
  "data": {
    "authenticated": true,
    "user": {...},
    "roles": [...],
    "permissions": {...},
    "can": {...}
  }
}
```

---

## 📝 VERİFİKASİYA

Database-də verify et:

```sql
-- User count by role
SELECT 
    ur.name as Role,
    COUNT(ura.user_id) as Users
FROM user_roles ur
LEFT JOIN user_role_assignments ura ON ur.id = ura.role_id
GROUP BY ur.id, ur.name;

-- Permissions by role
SELECT 
    ur.name as Role,
    COUNT(rp.permission_id) as Permissions
FROM user_roles ur
LEFT JOIN role_permissions rp ON ur.id = rp.role_id
GROUP BY ur.id, ur.name;

-- Data Governance vs Non-Governance
SELECT 
    CASE 
        WHEN ur.code IN ('GOV_MANAGER', 'COMPLIANCE') 
            THEN 'Data Governance'
        ELSE 'Non-Governance'
    END as Category,
    COUNT(ura.user_id) as Users
FROM user_roles ur
LEFT JOIN user_role_assignments ura ON ur.id = ura.role_id
WHERE ur.code != 'SUPER_ADMIN'
GROUP BY Category;
```

---

## ✅ EXPECTED RESULTS

**Roles:** 8  
**Users:** 14  
**Permissions:** 53  
**Role-Permission Links:** 200+

**Data Governance Focus:** 4 users (Gov Manager x2, Compliance, Steward)  
**Non-Governance:** 9 users (Quality, Engineer, Analyst, Viewer)

---

## 🆘 TROUBLESHOOTING

**Problem:** Permission checks fail  
**Həll:** Check session started: `session_start()` in backend.php

**Problem:** Frontend can't load permissions  
**Həll:** Add `case 'user_permissions'` endpoint to backend.php

**Problem:** SQL errors  
**Həll:** Run migration: `ALTER TABLE users ADD COLUMN tenant_id INT DEFAULT 1`

---

## 🎯 NƏTİCƏ

✅ 8 fərqli rol  
✅ 14 demo user  
✅ 53 granular permission  
✅ Data Governance vs Non-Governance fokus  
✅ Frontend və Backend permission checks  
✅ Role-based UI customization  

**SİSTEM HAZIRDIR!** 🚀
