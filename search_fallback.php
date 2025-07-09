<?php
require_once 'config.php';

// CORS headers
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: $origin");
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

/**
 * Fallback Arama Sistemi - API kota aşımında çalışır
 */

// Gelen parametreler
$query = sanitizeInput($_GET['q'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$maxResults = min(20, max(5, intval($_GET['maxResults'] ?? 10)));

if (empty($query)) {
    jsonResponse(['error' => 'Arama sorgusu boş olamaz'], 400);
}

try {
    // Önce cache'e bak
    $cacheKey = "fallback_search:" . md5($query . $page . $maxResults);
    $cachedResult = getCache($cacheKey);
    
    if ($cachedResult) {
        jsonResponse($cachedResult);
    }
    
    // Fallback arama stratejisi
    $results = performFallbackSearch($query, $page, $maxResults);
    
    // Sonucu cache'e kaydet
    setCache($cacheKey, $results, 3600); // 1 saat
    
    jsonResponse($results);
    
} catch (Exception $e) {
    jsonResponse([
        'error' => 'Arama sırasında hata oluştu: ' . $e->getMessage(),
        'books' => [],
        'totalItems' => 0
    ], 500);
}

function performFallbackSearch($query, $page, $maxResults) {
    $books = [];
    
    // 1. Yerel PDF dosyalarında ara
    $localBooks = searchLocalPDFs($query);
    $books = array_merge($books, $localBooks);
    
    // 2. Önceden cache'lenmiş sonuçlarda ara
    $cachedBooks = searchCachedResults($query);
    $books = array_merge($books, $cachedBooks);
    
    // 3. Statik kitap veritabanında ara (eğer varsa)
    $staticBooks = searchStaticDatabase($query);
    $books = array_merge($books, $staticBooks);
    
    // 4. Şablon kitaplar ekle (demo amaçlı)
    if (empty($books)) {
        $books = generateTemplateBooks($query);
    }
    
    // Sayfalama
    $startIndex = ($page - 1) * $maxResults;
    $paginatedBooks = array_slice($books, $startIndex, $maxResults);
    
    return [
        'success' => true,
        'books' => $paginatedBooks,
        'totalItems' => count($books),
        'fallback_mode' => true,
        'sources' => ['local', 'cache', 'template'],
        'message' => 'API kota aşımı nedeniyle fallback arama kullanıldı',
        'pagination' => [
            'current_page' => $page,
            'per_page' => $maxResults,
            'total' => count($books),
            'total_pages' => ceil(count($books) / $maxResults)
        ]
    ];
}

function searchLocalPDFs($query) {
    $books = [];
    $uploadsDir = __DIR__ . '/uploads/';
    
    if (is_dir($uploadsDir)) {
        $files = glob($uploadsDir . '*.pdf');
        foreach ($files as $file) {
            $filename = basename($file, '.pdf');
            if (stripos($filename, $query) !== false) {
                $books[] = createLocalBookEntry($filename, $file);
            }
        }
    }
    
    return $books;
}

function searchCachedResults($query) {
    // Redis'ten önceki arama sonuçlarını bul
    $redis = getRedisConnection();
    if (!$redis) return [];
    
    $keys = $redis->keys("search:*");
    $books = [];
    
    foreach ($keys as $key) {
        $cachedData = $redis->get($key);
        if ($cachedData) {
            $data = json_decode($cachedData, true);
            if ($data && isset($data['books'])) {
                foreach ($data['books'] as $book) {
                    if (stripos($book['title'], $query) !== false || 
                        stripos(implode(' ', $book['authors'] ?? []), $query) !== false) {
                        $books[] = $book;
                    }
                }
            }
        }
    }
    
    return array_slice($books, 0, 10); // En fazla 10 sonuç
}

function searchStaticDatabase($query) {
    // Türk klasikleri ve popüler kitaplar
    $staticBooks = [
        [
            'title' => 'Nutuk',
            'authors' => ['Mustafa Kemal Atatürk'],
            'description' => 'Mustafa Kemal Atatürk tarafından kaleme alınmış tarihi eser.',
            'publishedDate' => '1927',
            'language' => 'tr',
            'categories' => ['Tarih', 'Klasik'],
            'thumbnail' => 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTI4IiBoZWlnaHQ9IjE5MiIgdmlld0JveD0iMCAwIDEyOCAxOTIiIGZpbGw9Im5vbmUiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+PHJlY3Qgd2lkdGg9IjEwMCUiIGhlaWdodD0iMTAwJSIgZmlsbD0iIzMzNzNkYyIvPjx0ZXh0IHg9IjUwJSIgeT0iNTAlIiBmb250LWZhbWlseT0iQXJpYWwiIGZvbnQtc2l6ZT0iMTQiIGZvbnQtd2VpZ2h0PSI2MDAiIHRleHQtYW5jaG9yPSJtaWRkbGUiIGZpbGw9IndoaXRlIj5OdXR1azwvdGV4dD48L3N2Zz4='
        ],
        [
            'title' => 'Sabahattin Ali Hikayeler',
            'authors' => ['Sabahattin Ali'],
            'description' => 'Türk edebiyatının önemli hikayecilerinden Sabahattin Ali\'nin seçme hikayeleri.',
            'publishedDate' => '1950',
            'language' => 'tr',
            'categories' => ['Hikaye', 'Türk Edebiyatı'],
            'thumbnail' => 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTI4IiBoZWlnaHQ9IjE5MiIgdmlld0JveD0iMCAwIDEyOCAxOTIiIGZpbGw9Im5vbmUiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+PHJlY3Qgd2lkdGg9IjEwMCUiIGhlaWdodD0iMTAwJSIgZmlsbD0iIzEwYjk4MSIvPjx0ZXh0IHg9IjUwJSIgeT0iNTAlIiBmb250LWZhbWlseT0iQXJpYWwiIGZvbnQtc2l6ZT0iMTIiIGZvbnQtd2VpZ2h0PSI2MDAiIHRleHQtYW5jaG9yPSJtaWRkbGUiIGZpbGw9IndoaXRlIj5IaWtheWVsZXI8L3RleHQ+PC9zdmc+'
        ]
    ];
    
    $results = [];
    foreach ($staticBooks as $book) {
        if (stripos($book['title'], $query) !== false || 
            stripos(implode(' ', $book['authors']), $query) !== false) {
            $results[] = array_merge($book, [
                'google_id' => 'static_' . md5($book['title']),
                'pageCount' => null,
                'publisher' => 'Klasik Türk Edebiyatı',
                'infoLink' => '',
                'previewLink' => '',
                'isbn' => null,
                'pdf' => null,
                'rating' => 4.5,
                'ratingsCount' => 100,
                'availability' => ['pdf' => false, 'preview' => false, 'info' => false]
            ]);
        }
    }
    
    return $results;
}

function generateTemplateBooks($query) {
    return [[
        'google_id' => 'template_' . md5($query),
        'title' => "\"$query\" hakkında kitap",
        'authors' => ['Çeşitli Yazarlar'],
        'description' => "\"$query\" konusuyla ilgili kapsamlı bilgiler içeren kaynak kitap. API kota aşımı nedeniyle şablon sonuç gösteriliyor.",
        'publisher' => 'OMCN E-Kitap',
        'publishedDate' => date('Y'),
        'pageCount' => 200,
        'language' => 'tr',
        'categories' => ['Genel'],
        'thumbnail' => 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTI4IiBoZWlnaHQ9IjE5MiIgdmlld0JveD0iMCAwIDEyOCAxOTIiIGZpbGw9Im5vbmUiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+PHJlY3Qgd2lkdGg9IjEwMCUiIGhlaWdodD0iMTAwJSIgZmlsbD0iI2Y1OWU0MyIvPjx0ZXh0IHg9IjUwJSIgeT0iNTAlIiBmb250LWZhbWlseT0iQXJpYWwiIGZvbnQtc2l6ZT0iMTQiIGZvbnQtd2VpZ2h0PSI2MDAiIHRleHQtYW5jaG9yPSJtaWRkbGUiIGZpbGw9IndoaXRlIj5LaXRhcDwvdGV4dD48L3N2Zz4=',
        'infoLink' => '',
        'previewLink' => '',
        'isbn' => null,
        'pdf' => null,
        'rating' => 4.0,
        'ratingsCount' => 50,
        'availability' => ['pdf' => false, 'preview' => false, 'info' => false]
    ]];
}

function createLocalBookEntry($filename, $filepath) {
    return [
        'google_id' => 'local_' . md5($filename),
        'title' => ucfirst(str_replace(['-', '_'], ' ', $filename)),
        'authors' => ['Yerel Dosya'],
        'description' => 'Yerel sistemde bulunan PDF kitap dosyası.',
        'publisher' => 'OMCN E-Kitap',
        'publishedDate' => date('Y-m-d', filemtime($filepath)),
        'pageCount' => null,
        'language' => 'tr',
        'categories' => ['Yerel'],
        'thumbnail' => 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTI4IiBoZWlnaHQ9IjE5MiIgdmlld0JveD0iMCAwIDEyOCAxOTIiIGZpbGw9Im5vbmUiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+PHJlY3Qgd2lkdGg9IjEwMCUiIGhlaWdodD0iMTAwJSIgZmlsbD0iI2RjMjYyNiIvPjx0ZXh0IHg9IjUwJSIgeT0iNTAlIiBmb250LWZhbWlseT0iQXJpYWwiIGZvbnQtc2l6ZT0iMTQiIGZvbnQtd2VpZ2h0PSI2MDAiIHRleHQtYW5jaG9yPSJtaWRkbGUiIGZpbGw9IndoaXRlIj5QREY8L3RleHQ+PC9zdmc+',
        'infoLink' => '',
        'previewLink' => '',
        'isbn' => null,
        'pdf' => str_replace(__DIR__, '', $filepath),
        'rating' => null,
        'ratingsCount' => 0,
        'availability' => ['pdf' => true, 'preview' => false, 'info' => false]
    ];
}
?> 