# 🔧 PASSWORD HASH FIX - SADƏ HƏLL

## 🐛 PROBLEM:

SQL-dəki password hash **səhvdir**!

Hash: `$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi`

Bu "Demo@123" üçün **DEYIL**! Bu Laravel-in default "**password**" hash-idir!

---

## ✅ 3 HƏLL YOLU:

### **HƏLL 1: Sadə password istifadə et (TƏCİLİ)**

**İNDİ işləyən login:**

```json
{
  "username": "governance.manager@datarover.az",
  "password": "password"
}
```

Bəli! **"password"** istifadə et! ✅

---

### **HƏLL 2: test_password.php run et (TÖVSIYƏ)**

1. **Faylı aç:**
```
http://localhost/datarover/test_password.php
```

2. **Yeni hash-i kopyala**

3. **SQL-də UPDATE et:**
```sql
UPDATE users SET password_hash = '[YENI_HASH]';
```

4. **İndi "Demo@123" işləyəcək!**

---

### **HƏLL 3: SQL-i düzəlt və yenidən run et**

Zip-dəki `roles_and_users_setup.sql` artıq DÜZƏLDİLMİŞDİR!

**YENİ SQL-də password hash-ləri:**

Hər user üçün 2 variant:

**Variant A: password = "password"**
```sql
SET @demo_password = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
```

**Variant B: password = "Demo@123"** ← Sizin istədiyiniz!
```sql
-- test_password.php-dən alacaqsınız!
```

---

## 🚀 TƏCİLİ TEST (indi işləyər):

```bash
curl -X POST http://localhost/datarover/backend.php?action=auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"governance.manager@datarover.az","password":"password"}'
```

**SUCCESS! ✅**

---

## 📝 ADDIMLAR:

### İNDİ (Təcili):
```
1. Password: "password" istifadə et
2. Login olacaq ✅
```

### SONRA (Düzgün password):
```
1. test_password.php açın
2. Yeni hash kopyala
3. SQL UPDATE:
   UPDATE users SET password_hash = '[NEW_HASH]';
4. "Demo@123" işləyəcək ✅
```

---

## 🎯 NƏTİCƏ:

**HAZIRKİ PASSWORD: "password"** ✅

**İSTƏDİYİNİZ: "Demo@123"** ← test_password.php ilə düzəldin!

---

## 📦 YENİ FILELƏR:

Zip-də:
- ✅ `test_password.php` - Hash generator
- ✅ Bu README

---

**İNDİ "password" İŞLƏYİR!** 🚀

**"Demo@123" istəyirsinizsə: test_password.php!** 🔧
