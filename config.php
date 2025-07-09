<?php
// Güvenlik için hata gösterimini kapat
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');

// .env dosyasını yükle
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
    }
}

// Veritabanı konfigürasyonu - Çevre değişkenlerinden al
define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'omcn_ebook');
define('DB_USER', $_ENV['DB_USER'] ?? 'root');
define('DB_PASS', $_ENV['DB_PASS'] ?? 'omer782can322');
define('DB_CHARSET', 'utf8mb4');

// Google Books API Key - .env dosyasından al
define('GOOGLE_BOOKS_API_KEY', $_ENV['GOOGLE_BOOKS_API_KEYS'] ?? '');

// Google Books API Anahtarları - .env dosyasından al
$apiKeysString = $_ENV['GOOGLE_BOOKS_API_KEYS'] ?? '';
$apiKeysArray = !empty($apiKeysString) ? explode(',', $apiKeysString) : [];
$apiKeysArray = array_map('trim', $apiKeysArray); // Boşlukları temizle
define('GOOGLE_BOOKS_API_KEYS', $apiKeysArray);

/**
 * Google Books API için otomatik anahtar rotasyonu
 */
function getActiveGoogleBooksApiKey() {
    $keys = GOOGLE_BOOKS_API_KEYS;
    if (empty($keys)) {
        return '';
    }
    
    $redis = getRedisConnection();
    $currentKeyIndex = 0;
    
    if ($redis) {
        $cacheKey = 'google_books_api_key_index';
        $savedIndex = $redis->get($cacheKey);
        if ($savedIndex !== false) {
            $currentKeyIndex = intval($savedIndex);
        }
        
        // Eğer kota aşımı varsa bir sonraki anahtara geç
        $quotaExceededKey = 'google_books_quota_exceeded_' . $currentKeyIndex;
        if ($redis->exists($quotaExceededKey)) {
            $currentKeyIndex = ($currentKeyIndex + 1) % count($keys);
            $redis->setex($cacheKey, 3600, $currentKeyIndex); // 1 saat cache
        }
    }
    
    // Anahtarları kontrol et ve geçerli olanı döndür
    if (isset($keys[$currentKeyIndex])) {
        return $keys[$currentKeyIndex];
    }
    
    return $keys[0] ?? '';
}

/**
 * API kota aşımını işaretle
 */
function markGoogleBooksApiQuotaExceeded($apiKey) {
    $redis = getRedisConnection();
    if ($redis && !empty($apiKey)) {
        $keys = GOOGLE_BOOKS_API_KEYS;
        $keyIndex = array_search($apiKey, $keys);
        if ($keyIndex !== false) {
            $quotaExceededKey = 'google_books_quota_exceeded_' . $keyIndex;
            $redis->setex($quotaExceededKey, 3600, 1); // 1 saat engelle
            
            logError("Google Books API kota aşıldı", [
                'api_key' => substr($apiKey, 0, 10) . '...',
                'key_index' => $keyIndex
            ]);
        }
    }
}

// Redis Cache konfigürasyonu
define('REDIS_HOST', $_ENV['REDIS_HOST'] ?? '127.0.0.1');
define('REDIS_PORT', $_ENV['REDIS_PORT'] ?? 6379);
define('REDIS_PASSWORD', $_ENV['REDIS_PASSWORD'] ?? null);

// Güvenlik ayarları
define('JWT_SECRET', $_ENV['JWT_SECRET'] ?? 'your-secret-key-here-change-this');
define('BCRYPT_COST', 12);
define('SESSION_LIFETIME', 3600); // 1 saat
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 dakika

// API ayarları
define('API_RATE_LIMIT', 100); // dakikada 100 istek
define('CACHE_TTL', 3600); // 1 saat cache
define('MAX_RESULTS_PER_PAGE', 50);

// Dosya yükleme ayarları
define('UPLOAD_MAX_SIZE', 50 * 1024 * 1024); // 50MB
define('ALLOWED_FILE_TYPES', ['pdf', 'epub', 'mobi']);

// Gemini (Google Generative AI) API anahtarı
define('GEMINI_API_KEY', $_ENV['GEMINI_API_KEY'] ?? '');

// Global değişkenler
$pdo = null;
$redis = null;

// Gelişmiş PDO veritabanı bağlantısı
function getDbConnection() {
    global $pdo;
    
    if ($pdo !== null) {
        return $pdo;
    }
    
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => true, // Connection pooling
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ];
        
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        
        // Timezone ayarla
        $pdo->exec("SET time_zone = '+03:00'");
        
        return $pdo;
        
    } catch (PDOException $e) {
        logError("Veritabanı bağlantı hatası: " . $e->getMessage());
        die("Veritabanı bağlantısı kurulamadı. Lütfen daha sonra tekrar deneyin.");
    }
}

// Redis bağlantısı
function getRedisConnection() {
    global $redis;
    
    if ($redis !== null) {
        return $redis;
    }
    
    try {
        if (class_exists('Redis')) {
            $redis = new Redis();
            $redis->connect(REDIS_HOST, REDIS_PORT);
            
            if (REDIS_PASSWORD) {
                $redis->auth(REDIS_PASSWORD);
            }
            
            return $redis;
        }
    } catch (Exception $e) {
        logError("Redis bağlantı hatası: " . $e->getMessage());
    }
    
    return null;
}

// Güvenli oturum başlatma
function startSecureSession() {
    if (session_status() === PHP_SESSION_NONE) {
        // Güvenli session ayarları
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_secure', 0); // HTTP için false
        ini_set('session.use_only_cookies', 1);
        ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
        
        // Session başlat
        session_start();
        
        // Oturumun ilk kez başlatıldığını not et
        $isFirstStart = true;
        
        // Session hijacking koruması
        if (!isset($_SESSION['created'])) {
            $_SESSION['created'] = time();
        } else if (time() - $_SESSION['created'] > SESSION_LIFETIME) {
            session_regenerate_id(true);
            $_SESSION['created'] = time();
        } else {
            $isFirstStart = false; // Daha önce oluşturulmuş bir oturum devam ediyor
        }
        
        // Sadece yeni oturum oluşturulduysa logla
        if ($isFirstStart) {
            logInfo("Oturum başlatıldı", ['session_id' => session_id()]);
        }
    }
}

// Gelişmiş JSON yanıt fonksiyonu
function jsonResponse($data, $status = 200, $headers = []) {
    http_response_code($status);
    
    // CORS ayarları - Development için
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');  
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');
    
    header('Content-Type: application/json; charset=utf-8');
    
    // Güvenlik başlıkları
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=()');
    
    // CSP başlığı - external placeholder API kaldırıldı
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' fonts.googleapis.com; font-src fonts.gstatic.com; img-src 'self' data:;");
    
    // Özel başlıklar
    foreach ($headers as $key => $value) {
        header("$key: $value");
    }
    
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

// Gelişmiş input sanitization
function sanitizeInput($input, $type = 'string') {
    if (is_array($input)) {
        return array_map(function($item) use ($type) {
            return sanitizeInput($item, $type);
        }, $input);
    }
    
    $input = trim($input);
    
    switch ($type) {
        case 'id':
            return filter_var($input, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1]
            ]);
        case 'email':
            return filter_var($input, FILTER_SANITIZE_EMAIL);
        case 'url':
            return filter_var($input, FILTER_SANITIZE_URL);
        case 'filename':
            return preg_replace('/[^a-zA-Z0-9_.-]/', '', basename($input));
        case 'path':
            // Directory traversal önleme
            $input = str_replace(['../', '..\\'], '', $input);
            return realpath($input) ?: $input;
        default:
            return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
    }
}

// Dosya yolu güvenliği
function securePath($path, $allowedDirs = ['uploads', 'previews']) {
    $path = str_replace(['../', '..\\'], '', $path);
    $realPath = realpath($path);
    
    if (!$realPath) {
        return false;
    }
    
    $dir = dirname($realPath);
    foreach ($allowedDirs as $allowed) {
        if (strpos($dir, realpath($allowed)) === 0) {
            return $realPath;
        }
    }
    
    return false;
}

// Basit boyut tabanlı log rotasyonu (platform bağımsız)
function rotateLog($filePath, $maxSize = 5242880, $maxFiles = 5) { // 5 MB varsayılan
    if (!file_exists($filePath)) {
        return;
    }
    if (filesize($filePath) < $maxSize) {
        return; // Boyut sınırına ulaşılmamış
    }

    // Varsa en eski yedeği sil
    $oldest = $filePath . '.' . $maxFiles;
    if (file_exists($oldest)) {
        @unlink($oldest);
    }

    // Diğer yedekleri geri sayarak yeniden adlandır
    for ($i = $maxFiles - 1; $i >= 1; $i--) {
        $src = $filePath . '.' . $i;
        if (file_exists($src)) {
            $dst = $filePath . '.' . ($i + 1);
            @rename($src, $dst);
        }
    }

    // Asıl dosyayı .1 olarak taşı
    @rename($filePath, $filePath . '.1');
    // Yeni boş log dosyası oluştur
    @touch($filePath);
}

// Gelişmiş loglama sistemi
function logError($message, $context = []) {
    $logFile = __DIR__ . '/logs/error.log';
    rotateLog($logFile);

    $logData = [
        'timestamp' => date('Y-m-d H:i:s'),
        'level' => 'ERROR',
        'message' => $message,
        'context' => $context,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
    ];
    
    $logLine = json_encode($logData, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
}

function logInfo($message, $context = []) {
    $logFile = __DIR__ . '/logs/app.log';
    rotateLog($logFile);

    $logData = [
        'timestamp' => date('Y-m-d H:i:s'),
        'level' => 'INFO',
        'message' => $message,
        'context' => $context
    ];
    
    $logLine = json_encode($logData, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
}

// Rate limiting fonksiyonu
function checkRateLimit($identifier, $limit = API_RATE_LIMIT, $window = 60) {
    $redis = getRedisConnection();
    if (!$redis) {
        return true; // Redis yoksa rate limit uygulanmaz
    }
    
    $key = "rate_limit:$identifier";
    $current = $redis->incr($key);
    
    if ($current === 1) {
        $redis->expire($key, $window);
    }
    
    return $current <= $limit;
}

// Cache fonksiyonları
function getCache($key) {
    $redis = getRedisConnection();
    if (!$redis) {
        return null;
    }
    
    $data = $redis->get("cache:$key");
    return $data ? json_decode($data, true) : null;
}

function setCache($key, $data, $ttl = CACHE_TTL) {
    $redis = getRedisConnection();
    if (!$redis) {
        return false;
    }
    
    return $redis->setex("cache:$key", $ttl, json_encode($data));
}

function deleteCache($key) {
    $redis = getRedisConnection();
    if (!$redis) {
        return false;
    }
    
    return $redis->del("cache:$key");
}

// CSRF token fonksiyonları
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Dosya upload güvenlik kontrolü
function validateFileUpload($file) {
    $errors = [];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Dosya yükleme hatası';
    }
    
    if ($file['size'] > UPLOAD_MAX_SIZE) {
        $errors[] = 'Dosya boyutu çok büyük';
    }
    
    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($fileExtension, ALLOWED_FILE_TYPES)) {
        $errors[] = 'Geçersiz dosya türü';
    }
    
    return $errors;
}

// Sistem başlatma
startSecureSession();
$pdo = getDbConnection();

// Veritabanı bağlantısı başarılı satırı kaldırıldı; gereksiz tekrar logunu engelliyoruz. 