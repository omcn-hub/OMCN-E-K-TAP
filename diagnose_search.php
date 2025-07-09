<?php
require_once 'config.php';

echo "OMCN E-Kitap Arama Sistemi Tanılaması\n";
echo "====================================\n\n";

// 1. API Key kontrolü
echo "1. API Anahtarları Kontrolü:\n";
echo "----------------------------\n";
$apiKeys = GOOGLE_BOOKS_API_KEYS;
if (empty($apiKeys)) {
    echo "❌ HATA: Google Books API anahtarları yapılandırılmamış!\n";
    echo "Çözüm: .env dosyasında GOOGLE_BOOKS_API_KEYS değerini ayarlayın.\n\n";
} else {
    echo "✅ " . count($apiKeys) . " API anahtarı yapılandırılmış\n";
    foreach ($apiKeys as $i => $key) {
        echo "  Anahtar " . ($i + 1) . ": " . substr($key, 0, 10) . "...\n";
    }
}
echo "\n";

// 2. API Test
echo "2. Google Books API Erişim Testi:\n";
echo "---------------------------------\n";
if (!empty($apiKeys)) {
    $activeKey = getActiveGoogleBooksApiKey();
    
    // Test query
    $testUrl = "https://www.googleapis.com/books/v1/volumes?" . http_build_query([
        'q' => 'test',
        'maxResults' => 1,
        'key' => $activeKey
    ]);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $testUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        echo "✅ API erişimi başarılı\n";
        $data = json_decode($response, true);
        echo "   Toplam sonuç: " . ($data['totalItems'] ?? 0) . "\n";
    } else if ($httpCode === 403) {
        echo "❌ HATA: API erişimi engellendi (403)\n";
        echo "Neden: API anahtarları geçersiz veya izinler yetersiz\n";
        echo "Çözümler:\n";
        echo "  1. Google Cloud Console'da yeni API anahtarı oluşturun\n";
        echo "  2. Books API'yi etkinleştirin\n";
        echo "  3. API anahtarının kısıtlamalarını kontrol edin\n";
        echo "  4. Faturalandırma hesabını etkinleştirin\n";
    } else {
        echo "❌ HATA: Beklenmeyen HTTP kodu ($httpCode)\n";
        echo "Yanıt: " . substr($response, 0, 200) . "\n";
    }
} else {
    echo "❌ Test edilemiyor: API anahtarları yok\n";
}
echo "\n";

// 3. Veritabanı kontrolü
echo "3. Veritabanı Bağlantısı:\n";
echo "------------------------\n";
try {
    $pdo = getDbConnection();
    echo "✅ Veritabanı bağlantısı başarılı\n";
    
    // Kullanıcı tablosu kontrolü
    $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
    if ($stmt->rowCount() > 0) {
        echo "✅ Kullanıcı tablosu mevcut\n";
    } else {
        echo "❌ Kullanıcı tablosu bulunamadı\n";
    }
    
} catch (Exception $e) {
    echo "❌ Veritabanı hatası: " . $e->getMessage() . "\n";
}
echo "\n";

// 4. Cache kontrolü
echo "4. Cache Sistemi:\n";
echo "-----------------\n";
$redis = getRedisConnection();
if ($redis) {
    echo "✅ Redis cache bağlantısı başarılı\n";
    try {
        $redis->set('test_key', 'test_value', 60);
        $value = $redis->get('test_key');
        if ($value === 'test_value') {
            echo "✅ Cache okuma/yazma başarılı\n";
        }
        $redis->del('test_key');
    } catch (Exception $e) {
        echo "❌ Cache test hatası: " . $e->getMessage() . "\n";
    }
} else {
    echo "⚠️  Redis cache kullanılamıyor (opsiyonel)\n";
}
echo "\n";

// 5. Fallback çözüm önerisi
echo "5. Önerilen Çözümler:\n";
echo "--------------------\n";

if ($httpCode === 403) {
    echo "ANAFİŞ: Google Books API erişim sorunu tespit edildi!\n\n";
    echo "HIZLI ÇÖZÜM:\n";
    echo "1. search_fallback.php dosyasını kullanarak yerel arama sistemi aktif\n";
    echo "2. Manuel kitap veritabanı ile çalışabilir\n";
    echo "3. Alternatif kitap API'leri entegre edilebilir\n\n";
    
    echo "KALICI ÇÖZÜM:\n";
    echo "1. Google Cloud Console'a gidin (console.cloud.google.com)\n";
    echo "2. Yeni bir proje oluşturun\n";
    echo "3. Books API'yi etkinleştirin\n";
    echo "4. API anahtarı oluşturun\n";
    echo "5. .env dosyasındaki GOOGLE_BOOKS_API_KEYS değerini güncelleyin\n\n";
} else {
    echo "✅ Sistem genel olarak sağlıklı görünüyor\n";
    echo "Arama sorunu muhtemelen frontend/backend entegrasyonundan kaynaklanıyor\n";
}

echo "Tanılama tamamlandı.\n";
?> 