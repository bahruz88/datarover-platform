# DataRover - Data Governance Platform

Professional data governance platforması.

## 📁 Struktur

```
datarover_final/
├── index.html          # Əsas səhifə
├── backend.php         # API
├── database.sql        # Database quraşdırma
├── css/
│   └── style.css       # Styles
├── js/
│   └── app.js          # JavaScript
└── docs/
    └── README.md       # Bu fayl
```

## 🚀 Quraşdırma

### 1. Database Yaradın

```bash
mysql -u root -p < database.sql
```

### 2. Backend Konfiqurasiya

`backend.php` faylını açın və sətir 11-12-də kredensialları dəyişdirin:

```php
'root',  // ← Sizin MySQL username
''       // ← Sizin MySQL şifrə
```

### 3. Server Başladın

```bash
php -S localhost:8000
```

### 4. Brauzerdə Açın

```
http://localhost:8000
```

## ✅ Test

Terminal test:

```bash
# Stats
curl http://localhost:8000/backend.php?action=stats

# Glossary
curl "http://localhost:8000/backend.php?action=glossary&q=müştəri"
```

## 📊 Modullar

1. **Business Glossary** - 10 terminlər
2. **Data Catalog** - 5 cədvəllər
3. **Reporting Catalog** - 5 hesabatlar
4. **Data Quality** - 3 məsələlər
5. **Data Lineage** - 12 nodes, qrafik vizualizasiya ⭐ YENİ

## 🔌 API

| Endpoint | Açıqlama |
|----------|----------|
| `?action=glossary&q=search` | Glossary axtarış |
| `?action=catalog&q=search` | Catalog axtarış |
| `?action=reports&q=search` | Reports axtarış |
| `?action=quality` | Quality issues |
| `?action=lineage` | Data lineage graph ⭐ YENİ |
| `?action=stats` | Statistika |

## ⚠️ Problemlər?

**404 xətası:**
```bash
php -S localhost:8000
```

**Database xətası:**
```bash
mysql -u root -p < database.sql
```

**Məlumat yoxdur:**
- backend.php-də kredensialları yoxlayın
- Database yaradıldığını yoxlayın:
```bash
mysql -u root -p -e "USE datarover; SELECT COUNT(*) FROM glossary;"
```

## 📝 Xüsusiyyətlər

- ✅ Sadə struktur
- ✅ İşlək API
- ✅ Real-time axtarış
- ✅ Professional dizayn
- ✅ Responsive
- ✅ Təmiz kod

## 🔧 Texniki Detallar

- **Backend:** PHP 7.4+
- **Database:** MySQL 5.7+
- **Frontend:** Vanilla JavaScript
- **CSS:** Custom (responsive)

---

**Versiya:** 3.0 Final  
**Tarix:** 2025-11-20  
**Status:** Production Ready ✅
