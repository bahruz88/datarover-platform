# DataRover Translations

## 📁 Fayl Strukturu

```
datarover/
├── index.html
└── js/
    ├── translations.js  ← Bütün tərcümələr buradadır
    └── app.js
```

## 🌍 Yeni Dil Əlavə Etmək

### 1. `translations.js` faylını açın

### 2. Yeni dil obyekti əlavə edin:

```javascript
const translations = {
    az: { ... },
    en: { ... },
    ru: {  // ← YENİ DİL
        // MENU ITEMS
        'Dashboard': 'Панель управления',
        'Business Glossary': 'Бизнес глоссарий',
        'Data Catalog': 'Каталог данных',
        // ... digər tərcümələr
    }
};
```

### 3. Navbar-a dil düyməsi əlavə edin

`index.html` faylında language switcher-ə yeni düymə əlavə edin:

```html
<div class="lang-switcher">
    <button class="lang-btn" data-lang="az" onclick="switchLanguage('az')">AZ</button>
    <button class="lang-btn" data-lang="en" onclick="switchLanguage('en')">EN</button>
    <button class="lang-btn" data-lang="ru" onclick="switchLanguage('ru')">RU</button>  ← YENİ
</div>
```

## 📝 Tərcümə Açarları (Translation Keys)

### Menyu
- `Dashboard`
- `Business Glossary`
- `Data Catalog`
- `Reporting Catalog`
- `Data Quality`
- `Data Lineage`
- `Column Mapper`
- `External Sources`

### Dashboard
- `Business Terms`
- `Data Tables`
- `Reports`
- `Column Mappings`
- `Quality Rules`
- `KPI Metrics`
- `Total Assets`
- `Recent Reports`
- `View All`
- `Quality Score`
- `Completeness`
- `Accuracy`
- `Timeliness`
- `Validity`

### Ümumi Button-lar
- `Search`, `Add`, `Edit`, `Delete`, `Save`, `Cancel`, `Close`
- `Export`, `Import`, `Download`, `Upload`, `View`, `Refresh`
- `Filter`, `Sort`, `Clear`, `Apply`, `Reset`

### Status-lar
- `Active`, `Inactive`, `Draft`, `Published`, `Archived`
- `Passed`, `Failed`, `Warning`, `Error`, `Success`

### Mesajlar
- `Are you sure?`
- `This action cannot be undone`
- `Successfully saved`
- `Successfully deleted`
- `Loading...`
- `No data available`

## ⚙️ Necə İşləyir?

### 1. Tərcümə Funksiyası

```javascript
function t(key) {
    return translations[currentLang][key] || key;
}
```

### 2. İstifadə

```javascript
// HTML-də
'<div>' + t('Business Terms') + '</div>'

// Button-da
'<button>' + t('Save') + '</button>'

// Mesajda
alert(t('Successfully saved'));
```

### 3. Dil Dəyişdirmə

```javascript
function switchLanguage(lang) {
    currentLang = lang;
    localStorage.setItem('datarover_lang', lang);
    // Səhifəni reload et
}
```

## 🎯 Nümunə: Rusca əlavə etmək

```javascript
const translations = {
    az: {
        'Business Glossary': 'Biznes Lüğəti',
        'Add': 'Əlavə et',
        'Delete': 'Sil'
    },
    en: {
        'Business Glossary': 'Business Glossary',
        'Add': 'Add',
        'Delete': 'Delete'
    },
    ru: {  // YENİ
        'Business Glossary': 'Бизнес глоссарий',
        'Add': 'Добавить',
        'Delete': 'Удалить'
    }
};
```

Navbar-da:
```html
<button class="lang-btn" data-lang="ru" onclick="switchLanguage('ru')">RU</button>
```

## ✅ Düzəliş: "Biznes Qlossar" → "Biznes Lüğəti"

`translations.js` faylında:

```javascript
az: {
    'Business Glossary': 'Biznes Lüğəti',  // ✅ Düzəldildi
    ...
}
```

## 📊 Cari Dillər

- 🇦🇿 **AZ** - Azərbaycan dili (default)
- 🇬🇧 **EN** - English

## 🚀 Növbəti Addımlar

Yeni dil əlavə etmək üçün:
1. `translations.js` açın
2. Yeni dil obyekti əlavə edin
3. Bütün açarları tərcümə edin
4. `index.html`-də düymə əlavə edin
5. Test edin!

**Sadə və aydın! 🎉**
