# 📚 OMCN E-Kitap Platformu

Modern, güvenli ve performanslı e-kitap yönetim sistemi.

## ✨ Özellikler

### 🔐 Güvenlik
- Rate limiting koruması
- CSRF token güvenliği
- XSS ve SQL injection koruması
- Güvenli session yönetimi
- Hesap kilitleme sistemi

### 📖 Kitap Yönetimi
- Google Books API entegrasyonu
- Kişisel kütüphane yönetimi
- Okuma durumu takibi (okunacak, okunuyor, okundu)
- Kitap puanlama ve notlar
- Favori kitap işaretleme
- Kategori bazlı filtreleme

### 🔍 Arama
- Gelişmiş kitap arama
- Filtreleme seçenekleri
- Sayfalama desteği
- Arama geçmişi

### 👑 Admin Paneli
- Kullanıcı yönetimi
- Sistem istatistikleri
- Log görüntüleme
- Cache yönetimi
- Veritabanı yedekleme

## 🚀 Kurulum

### Gereksinimler
- PHP 7.4+
- MySQL 5.7+
- Apache/Nginx
- Redis (opsiyonel)

### Adımlar

1. **Projeyi klonlayın**
```bash
git clone https://github.com/username/omcn-ebook.git
cd omcn-ebook
```

2. **Veritabanını kurun**
```bash
mysql -u root -p
CREATE DATABASE omcn_ebook CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE omcn_ebook;
source database_basic.sql;
```

3. **Yapılandırma**
```bash
cp env.example .env
# .env dosyasını düzenleyin
```

4. **İzinleri ayarlayın**
```bash
chmod 755 logs/ uploads/
```

## 📖 API Dokümantasyonu

### Auth API
```javascript
// Kullanıcı girişi
fetch('/auth.php?action=login', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({email, password})
});

// Kullanıcı kaydı
fetch('/auth.php?action=register', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({name, email, password, confirmPassword})
});
```

### Library API
```javascript
// Kitap kaydetme
fetch('/library.php?action=save', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({title, authors, ...bookData, csrf_token})
});

// Kütüphaneyi listeleme
fetch('/library.php?action=list');
```

### Search API
```javascript
// Kitap arama
fetch('/search.php?q=query&page=1&max=10');
```

## 🧪 Test

Backend testlerini çalıştırmak için:

```bash
php test_backend.php
```

Test kapsamı:
- Veritabanı bağlantısı
- API endpoint'leri
- Güvenlik kontrolleri
- Performans testleri

## 🔧 Yapılandırma

### Çevre Değişkenleri (.env)
```env
DB_HOST=localhost
DB_NAME=omcn_ebook
DB_USER=root
DB_PASS=your-password

REDIS_HOST=127.0.0.1
REDIS_PORT=6379

JWT_SECRET=your-secret-key
GOOGLE_BOOKS_API_KEY=your-api-key
```

### Apache Ayarları
`.htaccess` dosyası otomatik olarak:
- GZIP sıkıştırma
- Browser caching
- Güvenlik headers
- Rate limiting

## 📊 Monitoring

### Log Dosyaları
- `logs/error.log` - Hata logları
- Sistem logları admin panelinden görüntülenebilir

### Performans
- Redis cache sistemi
- Database indexing
- Query optimization
- API response caching

## 🛡️ Güvenlik

### Implemented Security Measures
- Rate limiting (IP bazlı)
- CSRF token koruması
- XSS sanitization
- SQL injection koruması
- Session security
- Input validation
- Secure headers

### Security Headers
```
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
X-XSS-Protection: 1; mode=block
Strict-Transport-Security: max-age=63072000
Content-Security-Policy: ...
```

## 👨‍💻 Geliştirme

### Dizin Yapısı
```
omcn-ebook/
├── auth.php          # Kimlik doğrulama
├── library.php       # Kütüphane yönetimi
├── search.php        # Arama API
├── admin.php         # Admin paneli
├── config.php        # Yapılandırma
├── index.html        # Frontend
├── main.js          # JavaScript
├── database.sql     # Veritabanı şeması
└── logs/            # Log dosyaları
```

### Kod Standartları
- PSR-12 coding standards
- Comprehensive error handling
- Detailed logging
- Input validation
- SQL prepared statements

## 🤝 Katkıda Bulunma

1. Fork edin
2. Feature branch oluşturun (`git checkout -b feature/amazing-feature`)
3. Commit edin (`git commit -m 'Add amazing feature'`)
4. Push edin (`git push origin feature/amazing-feature`)
5. Pull Request açın

## 📝 Lisans

Bu proje MIT lisansı altında lisanslanmıştır.

## 📞 Destek

- 📧 Email: support@omcn.com
- 📱 GitHub Issues
- 📖 [Backend İyileştirmeleri Dokümantasyonu](BACKEND_IMPROVEMENTS.md)

---

**Made with ❤️ by OMCN Team**