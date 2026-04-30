# 🚀 DataRover v3.5 - Tez Başlanğıc

## ⭐ YENİ VERSIYA - v3.5

**Nə dəyişdi?**
- ✅ Lineage problemi həll edildi
- ✅ Kod 67% sadələşdirildi (46KB → 15KB)
- ✅ Hər şey işləyir
- ✅ Yeni logo (17KB WebP)

---

## 📦 QURAŞDIRMA (4 Addım)

### 1️⃣ Database Yarat

```bash
mysql -u root -p
```

```sql
CREATE DATABASE datarover;
USE datarover;
SOURCE database.sql;
EXIT;
```

### 2️⃣ Backend Konfiqurasiya

`backend.php` faylını aç və credentials dəyişdir:

```php
$host = 'localhost';
$db   = 'datarover';
$user = 'root';        // Öz istifadəçin
$pass = 'password';    // Öz şifrən
```

### 3️⃣ Server Başlat

```bash
php -S localhost:8000
```

### 4️⃣ Brauzerdə Aç

```
http://localhost:8000/index.html
```

**Və ya test səhifəsini aç:**
```
http://localhost:8000/TEST.html
```

---

## ✅ TEST

### Dashboard:
1. Açılanda dərhal 5 stat card görünür
2. Numbers yüklənir
3. Quick access buttons işləyir

### Data Lineage (ƏN ÖNƏMLİ!):
1. Sol menuda "Data Lineage" klik
2. **Dərhal görəcəksiniz:**
   - ✅ Table lineage qrafik
   - ✅ 12 node (Source, Transform, Target)
   - ✅ Rəngli connections
3. Scroll aşağı:
   - ✅ Column lineage dropdown
4. Dropdown-dan table seç (CRM_Database)
   - ✅ Column mappings cədvəl

### Digər Modullar:
- Business Glossary → 10 terms
- Data Catalog → 5 tables
- Reporting Catalog → 5 reports
- Data Quality → 3 issues

---

## 🎯 ƏSAS XÜSUSIYYƏTLƏR

### 6 Modul:
1. **Dashboard** - Ana səhifə, stats
2. **Business Glossary** - Terminlər
3. **Data Catalog** - Cədvəllər
4. **Reporting Catalog** - Reportlar
5. **Data Quality** - Issues
6. **Data Lineage** - Qrafik ⭐ Düzəldildi!

### Lineage:
- **Table-level:** 12 nodes, SVG qrafik
- **Column-level:** Dropdown table seçimi, cədvəl view
- **Sadə və işlək!**

---

## 📊 VERSIYA TARİXÇƏSİ

### v3.5 (2025-11-20) - **Current**
- ✅ Lineage problemi həll edildi
- ✅ Kod sadələşdirildi (15KB)
- ✅ Hər şey işləyir

### v3.4 (2025-11-20)
- Yeni logo (WebP)
- Modern UI/UX
- ⚠️ Lineage problemi var idi

### v3.3 (2025-11-20)
- Column lineage graph əlavə edildi

### v3.2
- Column lineage
- Dashboard

---

## 🐛 PROBLEM OLSA

### Lineage görünmür?
```bash
# 1. Backend işləyirsə yoxla
curl http://localhost:8000/backend.php?action=lineage

# 2. Database connection yoxla
mysql -u root -p -e "USE datarover; SELECT * FROM lineage_nodes LIMIT 1;"

# 3. Browser console yoxla (F12)
# Error varmı bax
```

### Stats yüklənmir?
```bash
# Backend test et
curl http://localhost:8000/backend.php?action=stats

# Response: {"success":true, "data":{...}}
```

---

## 💡 TÖVSİYƏLƏR

- ✅ Chrome və ya Firefox istifadə et
- ✅ PHP 7.4+ lazımdır
- ✅ MySQL 5.7+ lazımdır
- ✅ Backend.php credentials düzgündür

---

## 📚 SƏNƏDLƏR

- **BAŞLANĞIC.md** (bu fayl) - Tez start
- **v3.5_SIMPLIFIED_FIX.md** - v3.5 dəyişiklikləri
- **README_FULL.md** - Tam documentation
- **v3.4_UI_UX_UPDATE.md** - UI/UX dəyişiklikləri
- **v3.3_UPDATE.md** - Column graph

---

## 🎉 İŞLƏYİR!

```
DataRover v3.5 artıq tam işləkdir!

✅ 6 Modul
✅ Dashboard
✅ Lineage (Table + Column)
✅ Modern UI
✅ Yeni Logo
✅ 15KB Sadə Kod

Status: Production Ready! 🚀
```

---

**Müvəffəqiyyətlər!** 🎊
