# 🤖 OMCN E-Kitap AI Öneri Sistemi ve Doğal Dil Arama

Bu sistem, e-kitap platformunuz için gelişmiş AI destekli öneriler ve doğal dil işleme özellikleri sunar.

## 🚀 Özellikler

### 1. **Yapay Zeka ile Kitap Öneri Sistemi**
- **Collaborative Filtering**: Benzer kullanıcıların tercihlerine dayalı öneriler
- **Content-Based Filtering**: Kitap içeriği ve kullanıcı geçmişine dayalı öneriler
- **Hybrid Approach**: Karışık yaklaşım ile en iyi sonuçlar
- **Trend-Based**: Popüler ve güncel kitap önerileri
- **Kişiselleştirme**: Her kullanıcı için özel profil oluşturma

### 2. **Doğal Dil Arama ve Filtreleme**
- **NLP İşleme**: Doğal dil cümlelerini anlama
- **Akıllı Filtreleme**: Otomatik filtre çıkarımı
- **Çoklu Parametre**: Yazar, kategori, dil, yıl, hedef kitle filtreleri
- **Güven Puanlama**: Sonuçların doğruluk oranı
- **Cache Sistemi**: Hızlı yanıt süresi

### 3. **Kullanıcı Aktivite İzleme**
- **Gerçek Zamanlı İzleme**: Tüm kullanıcı etkileşimleri
- **Engagement Analizi**: Kullanıcı katılım puanları
- **Davranış Profilleme**: Okuma alışkanlıkları analizi
- **Performans Optimizasyonu**: Batch processing ve cache

## 📁 Dosya Yapısı

```
omcn-ai-system/
├── ai_recommendations.sql          # Veritabanı tabloları
├── ai_recommendations.php          # Ana AI öneri motoru
├── ai_recommendation_helpers.php   # Yardımcı fonksiyonlar
├── user_activity_tracker.php      # Aktivite izleme sistemi
├── nlp_search.php                 # Doğal dil işleme motoru
├── smart_search.php               # Akıllı arama motoru
├── ai-smart-search.js             # Frontend JavaScript kütüphanesi
├── ai-smart-search.css            # Modern CSS stilleri
├── example_integration.html       # Demo ve entegrasyon örneği
└── AI_SYSTEM_README.md            # Bu dosya
```

## 🛠️ Kurulum

### 1. Veritabanı Kurulumu

```bash
# Mevcut database.sql'e ek olarak AI tablolarını oluşturun
mysql -u kullanici_adi -p veritabani_adi < ai_recommendations.sql
```

### 2. PHP Dosyaları

```bash
# Tüm PHP dosyalarını web dizininize kopyalayın
cp *.php /path/to/your/web/directory/
```

### 3. Frontend Dosyaları

```bash
# CSS ve JS dosyalarını assets dizininize kopyalayın
cp ai-smart-search.css /path/to/assets/css/
cp ai-smart-search.js /path/to/assets/js/
```

### 4. Konfigürasyon

`config.php` dosyanızda aşağıdaki ayarların olduğundan emin olun:

```php
// Google Books API Key (zaten mevcut)
define('GOOGLE_BOOKS_API_KEY', 'your-api-key');

// Redis Cache (opsiyonel ama önerilen)
define('REDIS_HOST', '127.0.0.1');
define('REDIS_PORT', 6379);

// AI Sistem Ayarları
define('AI_RECOMMENDATION_CACHE_TTL', 86400); // 24 saat
define('NLP_CACHE_TTL', 604800); // 7 gün
define('USER_PROFILE_UPDATE_INTERVAL', 86400); // 24 saat
```

## 🎯 Kullanım

### 1. Frontend Entegrasyonu

```html
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="assets/css/ai-smart-search.css">
</head>
<body>
    <!-- Arama widget'ı için container -->
    <div id="search-widget"></div>
    
    <script src="assets/js/ai-smart-search.js"></script>
    <script>
        // AI sistemi başlat
        const smartSearch = new OMCNSmartSearch({
            apiBaseUrl: '/api/',
            userId: getCurrentUserId(), // Oturum açan kullanıcı
            debug: false,
            autoTrack: true
        });
        
        // Arama widget'ı oluştur
        const searchWidget = new OMCNSearchWidget('search-widget', smartSearch);
    </script>
</body>
</html>
```

### 2. API Endpoints

#### Akıllı Arama
```javascript
// GET smart_search.php
const searchResults = await fetch('/smart_search.php?' + new URLSearchParams({
    q: 'George Orwell kitapları ama sadece İngilizce olanlar',
    user_id: 123,
    page: 1,
    max: 20
}));
```

#### AI Önerileri
```javascript
// GET ai_recommendations.php
const recommendations = await fetch('/ai_recommendations.php?' + new URLSearchParams({
    user_id: 123,
    type: 'hybrid', // hybrid, collaborative, content_based, trending
    limit: 10
}));
```

#### NLP İşleme
```javascript
// GET nlp_search.php
const nlpResult = await fetch('/nlp_search.php?' + new URLSearchParams({
    q: 'bilim kurgu kitapları çocuklar için uygun olanlar',
    user_id: 123
}));
```

#### Aktivite Kaydetme
```javascript
// POST user_activity_tracker.php
await fetch('/user_activity_tracker.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
        user_id: 123,
        activity_type: 'view',
        book_data: {
            google_id: 'book123',
            title: 'Kitap Adı',
            authors: 'Yazar Adı',
            categories: 'Kategori'
        }
    })
});
```

### 3. Mevcut Sisteme Entegrasyon

#### Arama Sayfası Güncellemesi

```php
// search.php dosyanızı güncelleyin
if (isset($_GET['smart']) && $_GET['smart'] === 'true') {
    // Akıllı arama kullan
    header('Location: smart_search.php?' . $_SERVER['QUERY_STRING']);
    exit;
}
```

#### Aktivite İzleme Ekleme

```javascript
// Mevcut JavaScript kodlarınıza ekleyin
document.addEventListener('DOMContentLoaded', function() {
    // Activity tracker başlat
    if (typeof ActivityTracker !== 'undefined' && userId > 0) {
        window.activityTracker = new ActivityTracker(userId);
        
        // Kitap görüntüleme izleme
        document.querySelectorAll('.book-item').forEach(book => {
            book.addEventListener('click', function() {
                activityTracker.trackView({
                    google_id: this.dataset.bookId,
                    title: this.dataset.title,
                    authors: this.dataset.authors
                });
            });
        });
        
        // Favoriye ekleme izleme
        document.querySelectorAll('.favorite-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                activityTracker.trackFavorite({
                    google_id: this.dataset.bookId,
                    title: this.dataset.title,
                    authors: this.dataset.authors
                });
            });
        });
    }
});
```

## 🧪 Test Etme

### 1. Demo Sayfası

`example_integration.html` dosyasını açarak sistemi test edebilirsiniz:

```bash
# Web sunucunuzda
http://your-domain.com/example_integration.html
```

### 2. API Testleri

```bash
# NLP testi
curl "http://your-domain.com/nlp_search.php?q=George+Orwell+kitapları+İngilizce&user_id=1"

# AI önerileri testi
curl "http://your-domain.com/ai_recommendations.php?user_id=1&type=hybrid&limit=5"

# Akıllı arama testi
curl "http://your-domain.com/smart_search.php?q=bilim+kurgu+çocuk&user_id=1"
```

### 3. JavaScript Konsol Testleri

```javascript
// Tarayıcı konsolunda test edin
smartSearch.testNLP("George Orwell kitapları ama sadece İngilizce olanlar")
    .then(result => console.log(result));

smartSearch.getRecommendations("hybrid")
    .then(result => console.log(result));
```

## 📊 Performans Optimizasyonu

### 1. Cache Kullanımı

Sistem otomatik olarak şu cache stratejilerini kullanır:
- **NLP Cache**: 7 gün
- **AI Önerileri Cache**: 24 saat
- **Kullanıcı Profil Cache**: 24 saat
- **Google Books API Cache**: 30 dakika

### 2. Veritabanı Optimizasyonu

```sql
-- Önemli indeksler (zaten mevcut)
CREATE INDEX idx_user_activity_user_date ON user_activities(user_id, created_at);
CREATE INDEX idx_recommendations_user_score ON ai_recommendations(user_id, confidence_score);
CREATE INDEX idx_nlp_cache_expires ON nlp_query_cache(expires_at);
```

### 3. Batch Processing

```php
// Büyük veri setleri için batch işleme
$activities = []; // Toplu aktiviteler
batchTrackActivities($activities); // Tek seferde kaydet
```

## 🔧 Yapılandırma Seçenekleri

### AI Algoritma Ağırlıkları

```php
// ai_recommendations.php içinde özelleştirilebilir
$ALGORITHM_WEIGHTS = [
    'collaborative' => 0.4,    // %40 collaborative filtering
    'content_based' => 0.3,    // %30 content-based
    'trending' => 0.2,         // %20 trending
    'category_based' => 0.1    // %10 category-based
];
```

### NLP Güven Eşikleri

```php
// nlp_search.php içinde ayarlanabilir
$CONFIDENCE_THRESHOLDS = [
    'high' => 0.8,      // Yüksek güven
    'medium' => 0.5,    // Orta güven
    'low' => 0.3        // Düşük güven (fallback)
];
```

## 📈 Monitoring ve Analytics

### 1. Sistem Logları

```bash
# Log dosyalarını kontrol edin
tail -f logs/app.log
tail -f logs/error.log
```

### 2. Performans Metrikleri

```sql
-- En çok aranan NLP sorguları
SELECT original_query, COUNT(*) as usage_count 
FROM nlp_query_cache 
GROUP BY original_query 
ORDER BY usage_count DESC 
LIMIT 10;

-- Kullanıcı engagement skorları
SELECT user_id, SUM(activity_score) as total_engagement 
FROM user_activities 
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY user_id 
ORDER BY total_engagement DESC;
```

### 3. AI Önerileri Başarı Oranı

```sql
-- Öneri türlerinin kullanım istatistikleri
SELECT recommendation_type, 
       AVG(confidence_score) as avg_confidence,
       COUNT(*) as usage_count
FROM ai_recommendations 
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY recommendation_type;
```

## 🔍 Sorun Giderme

### Yaygın Hatalar

1. **NLP Sonuç Bulunamadı**
   - Türkçe karakter desteği kontrolü
   - Minimum kelime uzunluğu (3 karakter)
   - Cache temizleme

2. **AI Önerileri Boş**
   - Kullanıcı aktivite verisi kontrolü
   - Google Books API bağlantısı
   - Veritabanı bağlantısı

3. **Performans Sorunları**
   - Redis cache kurulumu
   - Veritabanı indeks kontrolü
   - Log dosyası boyutu

### Debug Modu

```javascript
// Frontend debug aktif
const smartSearch = new OMCNSmartSearch({
    debug: true, // Konsol loglarını göster
    // ... diğer ayarlar
});
```

```php
// Backend debug
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

## 🚀 Gelecek Geliştirmeler

- [ ] Derin öğrenme modelleri entegrasyonu
- [ ] Çok dilli NLP desteği
- [ ] Gerçek zamanlı öneri güncellemeleri
- [ ] A/B testing framework
- [ ] Machine learning model versioning
- [ ] Sentiment analysis
- [ ] Voice search desteği

## 📞 Destek

Sistem ile ilgili sorunlar için:
1. Log dosyalarını kontrol edin
2. Debug modunu aktifleştirin
3. API endpoint'lerini test edin
4. Veritabanı bağlantılarını doğrulayın

---

**Not**: Bu sistem, [Medium makalesinde](https://medium.com/@deepapandithu/recommender-system-user-collaborative-filtering-37613f0c6a9) ve [Towards Data Science](https://towardsdatascience.com/make-your-own-book-and-movie-recommender-system-using-surprise-42cc1c840a19/) platformunda açıklanan modern AI teknikleri kullanılarak geliştirilmiştir. 