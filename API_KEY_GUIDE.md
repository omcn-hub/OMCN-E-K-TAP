# 🔑 Google Books API Anahtarı Alma Rehberi

## 1. Google Cloud Console'a Giriş
1. [Google Cloud Console](https://console.cloud.google.com/) adresine git
2. Google hesabınla giriş yap
3. Yeni proje oluştur veya mevcut projeyi seç

## 2. Books API'yi Etkinleştir
1. **API'ler ve Hizmetler** > **Kütüphane** git
2. "Books API" ara ve seç
3. **ETKİNLEŞTİR** butonuna tıkla

## 3. API Anahtarı Oluştur
1. **API'ler ve Hizmetler** > **Kimlik Bilgileri** git
2. **+ KİMLİK BİLGİSİ OLUŞTUR** > **API anahtarı** seç
3. Oluşan anahtarı kopyala

## 4. API Anahtarını Güncelle
`.env` dosyasında:
```
GOOGLE_BOOKS_API_KEYS=YENİ_ANAHTAR_BURAYA,YENİ_ANAHTAR_2
```

## 5. Kota Limitlerini Artır
1. **API'ler ve Hizmetler** > **Kotalar** git
2. Books API bul ve günlük limiti artır
3. Gerekirse faturalandırmayı etkinleştir

## 💡 **İpuçları:**
- Birden fazla API anahtarı kullanarak kota limitini artırabilirsiniz
- Her anahtar için günde 1000 istek ücretsiz
- Faturalandırma etkinleştirerek limiti artırabilirsiniz 