<?php
require_once 'config.php';

// Session başlat
startSecureSession();

// CORS headers
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: $origin");
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Rate limiting kontrolü
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!checkRateLimit("search:$clientIp", 100, 60)) {
    jsonResponse(['error' => 'Çok fazla arama isteği. Lütfen bir dakika sonra tekrar deneyin.'], 429);
}

// Global rate limit (tüm kullanıcılar için toplam)
if (!checkRateLimit('search_global', 1000, 60)) {
    jsonResponse(['error' => 'Sistem yoğun. Lütfen bir dakika sonra tekrar deneyin.'], 429);
}

// OPTIONS request için erken yanıt
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($requestMethod === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

// Sorgu parametrelerini al ve validate et
$query = sanitizeInput($_GET['q'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$maxResults = min(40, max(1, intval($_GET['max'] ?? 10)));
$language = sanitizeInput($_GET['lang'] ?? 'tr');
$category = sanitizeInput($_GET['category'] ?? '');
$author = sanitizeInput($_GET['author'] ?? '');
$orderBy = $_GET['orderBy'] ?? 'relevance'; // relevance, newest, title

// Validasyon
if (empty($query)) {
    jsonResponse(['error' => 'Arama sorgusu boş olamaz'], 400);
}

if (strlen($query) < 2) {
    jsonResponse(['error' => 'Arama sorgusu en az 2 karakter olmalı'], 400);
}

if (strlen($query) > 200) {
    jsonResponse(['error' => 'Arama sorgusu çok uzun'], 400);
}

// Güvenlik kontrolü - tehlikeli karakterler
if (preg_match('/[<>"\'\\\]/', $query)) {
    jsonResponse(['error' => 'Geçersiz karakterler içeren arama sorgusu'], 400);
}

try {
    // Arama geçmişini kaydet
    saveSearchHistory($query);
    
    // Cache kontrolü
    $cacheKey = "search:" . md5($query . $page . $maxResults . $language . $category . $author . $orderBy);
    $cachedResult = getCache($cacheKey);
    
    if ($cachedResult) {
        // Cached sonuçları döndür
        logInfo("Arama cache'den döndürüldü", ['query' => $query, 'results' => count($cachedResult['books'])]);
        jsonResponse($cachedResult);
    }
    
    // Google Books API isteği yap
    function makeGoogleBooksRequest($query, $maxResults = 20, $orderBy = 'relevance', $startIndex = 0, $langRestrict = null) {
        $attempts = 0;
        $maxAttempts = count(GOOGLE_BOOKS_API_KEYS);
        
        while ($attempts < $maxAttempts) {
            $apiKey = getActiveGoogleBooksApiKey();
            if (empty($apiKey)) {
                throw new Exception('Google Books API anahtarı bulunamadı');
            }
            
            $params = [
                'q' => $query,
                'maxResults' => $maxResults,
                'orderBy' => $orderBy,
                'startIndex' => $startIndex,
                'key' => $apiKey
            ];
            
            if ($langRestrict && $langRestrict !== 'undefined') {
                $params['langRestrict'] = $langRestrict;
            }
            
            $url = 'https://www.googleapis.com/books/v1/volumes?' . http_build_query($params);
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_USERAGENT => 'OMCN-EKitap/1.0',
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 && $response) {
                return json_decode($response, true);
            } else if ($httpCode === 403) {
                // Kota aşımı - anahtarı işaretle ve bir sonrakini dene
                markGoogleBooksApiQuotaExceeded($apiKey);
                $attempts++;
                continue;
            } else {
                // Diğer hatalar
                throw new Exception("API erişim hatası (HTTP $httpCode)");
            }
        }
        
        throw new Exception('Tüm Google Books API anahtarları kota aşımında');
    }

    $searchResult = performGoogleBooksSearch($query, $page, $maxResults, $language, $category, $author, $orderBy);
    
    if ($searchResult['success']) {
        // Başarılı sonucu cache'e kaydet
        setCache($cacheKey, $searchResult, 1800); // 30 dakika
        
        logInfo("Arama tamamlandı", [
            'query' => $query,
            'results' => count($searchResult['books']),
            'page' => $page
        ]);
    }
    
    jsonResponse($searchResult);
    
} catch (Exception $e) {
    logError("Arama hatası", [
        'query' => $query,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    jsonResponse([
        'error' => 'Arama sırasında bir hata oluştu. Lütfen daha sonra tekrar deneyin.',
        'books' => []
    ], 500);
}

function performGoogleBooksSearch($query, $page, $maxResults, $language, $category, $author, $orderBy) {
    // API anahtar listesini al
    $apiKeys = defined('GOOGLE_BOOKS_API_KEYS') ? GOOGLE_BOOKS_API_KEYS : [];

    // Redis global rate-limit (dakikada 900 istek)
    if (!checkRateLimit('google_books_global', 900, 60)) {
        return [
            'success' => false,
            'error' => 'Yerel rate limit aşıldı, lütfen daha sonra deneyin.',
            'books' => []
        ];
    }

    if (empty($apiKeys)) {
        $apiKeys = ['']; // Anahtarsız da denenebilir (düşük kota)
    }
    
    // API anahtarlarını filtrele - sadece boş olmayan anahtarları kullan
    $validKeys = array_filter($apiKeys, function($key) {
        return !empty(trim($key));
    });
    
    // Eğer geçerli anahtar yoksa, anahtarsız dene
    if (empty($validKeys)) {
        $apiKeys = ['']; // Anahtarsız kullan
    } else {
        $apiKeys = $validKeys;
    }

    // Google Books API parametrelerini hazırla
    $startIndex = ($page - 1) * $maxResults;
    
    // Arama sorgusunu geliştirilmiş formatta hazırla
    $searchQuery = $query;
    
    if (!empty($author)) {
        $searchQuery .= "+inauthor:" . urlencode($author);
    }
    
    if (!empty($category)) {
        $searchQuery .= "+subject:" . urlencode($category);
    }
    
    // API URL'sini oluştur
    $baseUrl = "https://www.googleapis.com/books/v1/volumes";

    $paramsTemplate = [
        'q' => $searchQuery,
        'startIndex' => $startIndex,
        'maxResults' => $maxResults,
        'langRestrict' => $language,
        'orderBy' => $orderBy,
        'printType' => 'books',
        'projection' => 'full'
    ];

    $data = null;
    $lastError = null;
    foreach ($apiKeys as $keyIdx => $apiKey) {
        $params = $paramsTemplate;
        if (!empty($apiKey)) {
            $params['key'] = $apiKey;
        }
        $apiUrl = $baseUrl . '?' . http_build_query($params);

        $response = makeHttpRequest($apiUrl);
        if (!$response['success']) {
            // HTTP 403/429 ise diğer anahtara geç
            if (preg_match('/HTTP\s+(403|429)/', $response['error'])) {
                $lastError = $response['error'];
                continue; // Başka anahtar dene
            }
            // Diğer hata – döndür
            logError("Google Books API hatası", ['error' => $response['error']]);
            return [
                'success' => false,
                'error' => $response['error'],
                'books' => []
            ];
        }

        $data = json_decode($response['data'], true);

        // Quota/rate reason kontrolü
        $reason = $data['error']['errors'][0]['reason'] ?? null;
        if ($reason && in_array($reason, ['rateLimitExceeded','userRateLimitExceeded','quotaExceeded','dailyLimitExceeded'])) {
            $lastError = $reason;
            continue; // sonraki anahtar
        }
        // Başarılı veya başka hata değilse döngüden çık
        break;
    }

    if ($data === null) {
        // Tüm anahtarlar tükendi
        $errMsg = $lastError ?: 'API erişim hatası';
        logError("Google Books API kota aşıldı", ['error' => $errMsg]);
        return [
            'success' => false,
            'error' => 'API kota sınırı aşıldı. Lütfen daha sonra tekrar deneyin.',
            'books' => []
        ];
    }

    // Boş sonuçta esnek sorgu: dil kısıtlamasını kaldır
    if ((!isset($data['items']) || empty($data['items'])) && !empty($language)) {
        logInfo('Boş sonuç – dil kısıtı kaldırılıyor', ['query' => $query]);
        return performGoogleBooksSearch($query, $page, $maxResults, '', $category, $author, $orderBy);
    }

    if (json_last_error() !== JSON_ERROR_NONE) {
        logError("JSON decode hatası", ['json_error_code' => json_last_error()]);
        return [
            'success' => false,
            'error' => 'API yanıtı işlenirken hata oluştu',
            'books' => []
        ];
    }
    
    if (!isset($data['items']) || !is_array($data['items'])) {
        return [
            'success' => true,
            'books' => [],
            'totalItems' => 0,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $maxResults,
                'total' => 0,
                'total_pages' => 0
            ]
        ];
    }
    
    // Sonuçları işle
    $books = [];
    foreach ($data['items'] as $item) {
        $book = processBookItem($item);
        if ($book) {
            $books[] = $book;
        }
    }
    
    $totalItems = intval($data['totalItems'] ?? 0);
    
    return [
        'success' => true,
        'books' => $books,
        'totalItems' => $totalItems,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $maxResults,
            'total' => $totalItems,
            'total_pages' => ceil($totalItems / $maxResults),
            'has_next' => $startIndex + $maxResults < $totalItems,
            'has_prev' => $page > 1
        ],
        'query' => $query,
        'filters' => [
            'language' => $language,
            'category' => $category,
            'author' => $author,
            'orderBy' => $orderBy
        ]
    ];
}

function processBookItem($item) {
    $info = $item['volumeInfo'] ?? [];
    
    // Temel bilgileri al
    $title = sanitizeInput($info['title'] ?? 'Başlık yok');
    $authors = isset($info['authors']) && is_array($info['authors']) ? $info['authors'] : [];
    $description = sanitizeInput($info['description'] ?? '');
    $publisher = sanitizeInput($info['publisher'] ?? '');
    $publishedDate = sanitizeInput($info['publishedDate'] ?? '');
    $pageCount = intval($info['pageCount'] ?? 0);
    $language = sanitizeInput($info['language'] ?? 'tr');
    $categories = isset($info['categories']) && is_array($info['categories']) ? $info['categories'] : [];
    
    // Resim URL'si al
    $thumbnail = null;
    if (isset($info['imageLinks'])) {
        $thumbnail = $info['imageLinks']['thumbnail'] ?? 
                     $info['imageLinks']['smallThumbnail'] ?? 
                     'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTI4IiBoZWlnaHQ9IjE5MiIgdmlld0JveD0iMCAwIDEyOCAxOTIiIGZpbGw9Im5vbmUiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+CjxkZWZzPgo8bGluZWFyR3JhZGllbnQgaWQ9ImdyYWQiIHgxPSIwJSIgeTE9IjAlIiB4Mj0iMTAwJSIgeTI9IjEwMCUiPgo8c3RvcCBvZmZzZXQ9IjAlIiBzdG9wLWNvbG9yPSIjZjFmNWY5Ii8+CjxzdG9wIG9mZnNldD0iMTAwJSIgc3RvcC1jb2xvcj0iI2UyZThmMCIvPgo8L2xpbmVhckdyYWRpZW50Pgo8L2RlZnM+CjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9InVybCgjZ3JhZCkiIHJ4PSI4Ii8+CjxjaXJjbGUgY3g9IjY0IiBjeT0iNzAiIHI9IjE4IiBmaWxsPSIjY2JkMWRjIi8+CjxwYXRoIGQ9Ik01NiA2NEg3MlY3Nkg1NloiIGZpbGw9IiNmOWZhZmIiLz4KPHR5YXQgeD0iNjQiIHk9IjEyMCIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjE0IiBmb250LXdlaWdodD0iNjAwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjQ3NDhiIj5SZXNpbSBZb2s8L3RleHQ+CjxyZWN0IHg9IjIwIiB5PSIxNTAiIHdpZHRoPSI4OCIgaGVpZ2h0PSI2IiBmaWxsPSIjY2JkMWRjIiByeD0iMyIgb3BhY2l0eT0iMC41Ii8+CjxyZWN0IHg9IjMwIiB5PSIxNjQiIHdpZHRoPSI2OCIgaGVpZ2h0PSI0IiBmaWxsPSIjY2JkMWRjIiByeD0iMiIgb3BhY2l0eT0iMC41Ii8+Cjwvc3ZnPgo=';
        
        // HTTP'yi HTTPS'e çevir
        $thumbnail = str_replace('http://', 'https://', $thumbnail);
    } else {
        $thumbnail = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTI4IiBoZWlnaHQ9IjE5MiIgdmlld0JveD0iMCAwIDEyOCAxOTIiIGZpbGw9Im5vbmUiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+CjxkZWZzPgo8bGluZWFyR3JhZGllbnQgaWQ9ImdyYWQiIHgxPSIwJSIgeTE9IjAlIiB4Mj0iMTAwJSIgeTI9IjEwMCUiPgo8c3RvcCBvZmZzZXQ9IjAlIiBzdG9wLWNvbG9yPSIjZjFmNWY5Ii8+CjxzdG9wIG9mZnNldD0iMTAwJSIgc3RvcC1jb2xvcj0iI2UyZThmMCIvPgo8L2xpbmVhckdyYWRpZW50Pgo8L2RlZnM+CjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9InVybCgjZ3JhZCkiIHJ4PSI4Ii8+CjxjaXJjbGUgY3g9IjY0IiBjeT0iNzAiIHI9IjE4IiBmaWxsPSIjY2JkMWRjIi8+CjxwYXRoIGQ9Ik01NiA2NEg3MlY3Nkg1NloiIGZpbGw9IiNmOWZhZmIiLz4KPHR5YXQgeD0iNjQiIHk9IjEyMCIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjE0IiBmb250LXdlaWdodD0iNjAwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjNjQ3NDhiIj5SZXNpbSBZb2s8L3RleHQ+CjxyZWN0IHg9IjIwIiB5PSIxNTAiIHdpZHRoPSI4OCIgaGVpZ2h0PSI2IiBmaWxsPSIjY2JkMWRjIiByeD0iMyIgb3BhY2l0eT0iMC41Ii8+CjxyZWN0IHg9IjMwIiB5PSIxNjQiIHdpZHRoPSI2OCIgaGVpZ2h0PSI0IiBmaWxsPSIjY2JkMWRjIiByeD0iMiIgb3BhY2l0eT0iMC41Ii8+Cjwvc3ZnPgo=';
    }
    
    // Link bilgileri
    $infoLink = sanitizeInput($info['canonicalVolumeLink'] ?? $info['infoLink'] ?? '');
    $previewLink = sanitizeInput($info['previewLink'] ?? '');
    
    // ISBN bilgileri
    $isbn10 = null;
    $isbn13 = null;
    if (isset($info['industryIdentifiers']) && is_array($info['industryIdentifiers'])) {
        foreach ($info['industryIdentifiers'] as $identifier) {
            if ($identifier['type'] === 'ISBN_10') {
                $isbn10 = sanitizeInput($identifier['identifier']);
            } elseif ($identifier['type'] === 'ISBN_13') {
                $isbn13 = sanitizeInput($identifier['identifier']);
            }
        }
    }
    
    // PDF linki kontrol et
    $pdfUrl = checkLocalPdfFile($title);
    
    // Rating bilgisi
    $rating = null;
    $ratingsCount = 0;
    if (isset($info['averageRating'])) {
        $rating = floatval($info['averageRating']);
    }
    if (isset($info['ratingsCount'])) {
        $ratingsCount = intval($info['ratingsCount']);
    }
    
    return [
        'google_id' => sanitizeInput($item['id'] ?? ''),
        'title' => $title,
        'authors' => array_map('sanitizeInput', $authors),
        'description' => $description,
        'publisher' => $publisher,
        'publishedDate' => $publishedDate,
        'pageCount' => $pageCount > 0 ? $pageCount : null,
        'language' => $language,
        'categories' => array_map('sanitizeInput', $categories),
        'thumbnail' => $thumbnail,
        'infoLink' => $infoLink,
        'previewLink' => $previewLink,
        'isbn10' => $isbn10,
        'isbn13' => $isbn13,
        'isbn' => $isbn13 ?: $isbn10,
        'pdf' => $pdfUrl,
        'rating' => $rating,
        'ratingsCount' => $ratingsCount,
        'availability' => [
            'pdf' => !empty($pdfUrl),
            'preview' => !empty($previewLink),
            'info' => !empty($infoLink)
        ]
    ];
}

function makeHttpRequest($url, $timeout = 30) {
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false, // Yerel geliştirme için SSL doğrulamayı kapat
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_ENCODING => '', // Otomatik encoding decode için
        CURLOPT_USERAGENT => 'OMCN E-Kitap v1.0 (compatible; PHP/' . PHP_VERSION . ')',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Accept-Language: tr-TR,tr;q=0.9,en;q=0.8',
            'Cache-Control: no-cache'
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    

    
    curl_close($ch);
    
    if ($error) {
        return [
            'success' => false,
            'error' => 'Bağlantı hatası: ' . $error,
            'data' => null
        ];
    }
    
    if ($httpCode !== 200) {
        return [
            'success' => false,
            'error' => "API erişim hatası (HTTP $httpCode)",
            'data' => null
        ];
    }
    
    if (!$response) {
        return [
            'success' => false,
            'error' => 'Boş yanıt alındı',
            'data' => null
        ];
    }
    
    return [
        'success' => true,
        'error' => null,
        'data' => $response
    ];
}

function checkLocalPdfFile($title) {
    if (empty($title)) {
        return null;
    }
    
    // Türkçe karakterleri ve özel karakterleri temizle
    $slug = slugify($title);
    
    if (empty($slug)) {
        return null;
    }
    
    $pdfPath = __DIR__ . "/uploads/{$slug}.pdf";
    
    if (file_exists($pdfPath) && is_readable($pdfPath)) {
        $host = (isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'http') . 
                '://' . $_SERVER['HTTP_HOST'];
        return "{$host}/uploads/{$slug}.pdf";
    }
    
    return null;
}

function slugify($text) {
    if (empty($text)) {
        return '';
    }
    
    // Türkçe karakter dönüşümleri
    $turkishChars = [
        'ş' => 's', 'Ş' => 's', 'ı' => 'i', 'İ' => 'i',
        'ç' => 'c', 'Ç' => 'c', 'ü' => 'u', 'Ü' => 'u',
        'ö' => 'o', 'Ö' => 'o', 'ğ' => 'g', 'Ğ' => 'g'
    ];
    
    $text = strtr($text, $turkishChars);
    
    // UTF-8'den ASCII'ye güvenli dönüşüm
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($converted !== false) {
            $text = $converted;
        }
    }
    
    // Küçük harfe çevir ve temizle
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9\-_]/', '-', $text);
    $text = preg_replace('/-+/', '-', $text);
    $text = trim($text, '-');
    
    // Maksimum uzunluk sınırı
    if (strlen($text) > 100) {
        $text = substr($text, 0, 100);
        $text = rtrim($text, '-');
    }
    
    return $text;
}

function saveSearchHistory($query) {
    try {
        $pdo = getDbConnection();
        $userId = $_SESSION['user_id'] ?? null;
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        // Günlük arama sayısını kontrol et (spam koruması)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM search_history 
            WHERE ip_address = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
        ");
        $stmt->execute([$clientIp]);
        $dailySearchCount = $stmt->fetchColumn();
        
        if ($dailySearchCount > 500) {
            return; // Çok fazla arama, kaydetme
        }
        
        // Arama geçmişini kaydet
        $stmt = $pdo->prepare("
            INSERT INTO search_history (user_id, query, ip_address) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$userId, $query, $clientIp]);
        
    } catch (PDOException $e) {
        // Hata logla ama arama sonuçlarını engelleme
        logError("Arama geçmişi kaydetme hatası", [
            'query' => $query,
            'error' => $e->getMessage()
        ]);
    }
}

// Popüler arama terimleri endpoint'i
if (isset($_GET['popular']) && $_GET['popular'] === '1') {
    try {
        $pdo = getDbConnection();
        
        // Cache kontrolü
        $cacheKey = "popular_searches";
        $cachedSearches = getCache($cacheKey);
        
        if ($cachedSearches) {
            jsonResponse($cachedSearches);
        }
        
        // Son 30 gündeki popüler aramaları al
        $stmt = $pdo->prepare("
            SELECT query, COUNT(*) as search_count
            FROM search_history 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            AND LENGTH(query) >= 3
            GROUP BY query 
            ORDER BY search_count DESC, query ASC
            LIMIT 20
        ");
        $stmt->execute();
        $searches = $stmt->fetchAll();
        
        $response = [
            'success' => true,
            'popular_searches' => $searches
        ];
        
        // Cache'e kaydet (2 saat)
        setCache($cacheKey, $response, 7200);
        
        jsonResponse($response);
        
    } catch (PDOException $e) {
        logError("Popüler aramalar hatası", ['error' => $e->getMessage()]);
        jsonResponse(['error' => 'Popüler aramalar alınırken hata oluştu'], 500);
    }
}

// Arama önerileri endpoint'i
if (isset($_GET['suggest']) && !empty($_GET['suggest'])) {
    try {
        $term = sanitizeInput($_GET['suggest']);
        
        if (strlen($term) < 2) {
            jsonResponse(['suggestions' => []]);
        }
        
        $pdo = getDbConnection();
        
        // Cache kontrolü
        $cacheKey = "search_suggestions:" . md5($term);
        $cachedSuggestions = getCache($cacheKey);
        
        if ($cachedSuggestions) {
            jsonResponse($cachedSuggestions);
        }
        
        // Benzer aramaları bul
        $stmt = $pdo->prepare("
            SELECT DISTINCT query
            FROM search_history 
            WHERE query LIKE ? 
            AND LENGTH(query) >= 3
            AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ORDER BY query ASC
            LIMIT 10
        ");
        $stmt->execute(["%$term%"]);
        $suggestions = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $response = [
            'suggestions' => $suggestions
        ];
        
        // Cache'e kaydet (1 saat)
        setCache($cacheKey, $response, 3600);
        
        jsonResponse($response);
        
    } catch (PDOException $e) {
        logError("Arama önerileri hatası", ['error' => $e->getMessage()]);
        jsonResponse(['suggestions' => []]);
    }
}
?> 