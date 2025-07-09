<?php
require_once 'config.php';
require_once 'ai_recommendation_helpers.php';
require_once 'nlp_search.php'; // NLP fonksiyonları için ekledim
/**
 * Akıllı Kitap Arama Motoru
 * NLP sonuçlarını kullanarak Google Books API'den filtrelenmiş sonuçlar getirir
 */

// Hata ayıklama modu
error_reporting(E_ALL);
ini_set('display_errors', 1);

// CORS ayarları
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

startSecureSession();

// Rate limiting
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!checkRateLimit("smart_search:$clientIp", 40, 60)) {
    jsonResponse(['error' => 'Çok fazla akıllı arama isteği. Lütfen bir dakika sonra tekrar deneyin.'], 429);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'POST') {
    // POST verilerini al
    $postData = json_decode(file_get_contents('php://input'), true);
    
    // GET veya POST'tan gelen parametreleri al
    $query = sanitizeInput($postData['query'] ?? $_GET['q'] ?? '');
    $userId = intval($postData['userId'] ?? $_GET['user_id'] ?? 0);
    $page = max(1, intval($postData['page'] ?? $_GET['page'] ?? 1));
    $maxResults = min(40, max(5, intval($postData['maxResults'] ?? $_GET['max'] ?? 20)));
    $useNLP = true; // NLP arama sistemi aktif
    $hybridMode = isset($postData['hybrid']) ? $postData['hybrid'] : (isset($_GET['hybrid']) && $_GET['hybrid'] === 'true');
    
    if (empty($query)) {
        jsonResponse(['error' => 'Arama sorgusu boş olamaz'], 400);
    }
    
    handleSmartSearch($query, $userId, $page, $maxResults, $useNLP, $hybridMode);
} else {
    jsonResponse(['error' => 'Desteklenmeyen HTTP metodu'], 405);
}

function handleSmartSearch($query, $userId, $page, $maxResults, $useNLP, $hybridMode) {
    try {
        $startTime = microtime(true);
        $searchResults = [];
        
        if ($useNLP) {
            // NLP ile akıllı arama
            $nlpResult = performNLPSearch($query, $userId);
            $searchResults = performSmartBookSearch($nlpResult, $page, $maxResults);
            
            // Hybrid mode: NLP + klasik arama karışımı
            if ($hybridMode && empty($searchResults['books'])) {
                $classicResults = performClassicSearch($query, $page, $maxResults);
                $searchResults = mergeSearchResults($searchResults, $classicResults);
            }
        } else {
            // Klasik arama
            $searchResults = performClassicSearch($query, $page, $maxResults);
        }
        
        // Kullanıcı tercihlerine göre sırala
        if ($userId > 0 && !empty($searchResults['books'])) {
            $searchResults['books'] = reorderByUserPreferences($searchResults['books'], $userId);
        }
        
        // AI önerileri ekle (eğer sonuç azsa)
        if ($userId > 0 && count($searchResults['books']) < 5) {
            $aiRecommendations = getRelevantAIRecommendations($userId, $query, 5);
            $searchResults = addAIRecommendations($searchResults, $aiRecommendations);
        }
        
        // Kullanıcı aktivitesini kaydet
        if ($userId > 0) {
            trackSearchActivity($userId, $query, $searchResults, $useNLP);
        }
        
        $searchResults['processing_time_ms'] = round((microtime(true) - $startTime) * 1000, 2);
        $searchResults['search_type'] = $useNLP ? 'smart_nlp' : 'classic';
        $searchResults['hybrid_mode'] = $hybridMode;
        
        jsonResponse($searchResults);
        
    } catch (Exception $e) {
        error_log("Smart Search Error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        
        jsonResponse([
            'error' => 'Arama sırasında bir hata oluştu: ' . $e->getMessage(),
            'books' => [],
            'total_found' => 0
        ], 500);
    }
}

/**
 * NLP arama işlemi - Direkt fonksiyon çağrısı
 */
function performNLPSearch($query, $userId) {
    // nlp_search.php'deki ana fonksiyonu direkt çağır
    return processNaturalLanguageQuery($query, $userId);
}

/**
 * Akıllı kitap arama (NLP filtrelerini kullanarak)
 */
function performSmartBookSearch($nlpResult, $page, $maxResults) {
    $apiKey = GOOGLE_BOOKS_API_KEY;
    $filters = $nlpResult['filters'];
    $startIndex = ($page - 1) * $maxResults;
    
    // Google Books API sorgusu oluştur
    $apiQuery = buildGoogleBooksQuery($filters);
    $apiParams = [
        'q' => $apiQuery,
        'startIndex' => $startIndex,
        'maxResults' => $maxResults,
        'orderBy' => 'relevance',
        'printType' => 'books',
        'key' => $apiKey
    ];
    
    // API isteği yap
    $apiUrl = "https://www.googleapis.com/books/v1/volumes?" . http_build_query($apiParams);
    $response = makeHttpRequest($apiUrl);
    
    if (!$response['success']) {
        throw new Exception('Google Books API hatası: ' . $response['error']);
    }
    
    $data = json_decode($response['data'], true);
    
    return [
        'success' => true,
        'books' => $data['items'] ?? [],
        'total_found' => count($data['items'] ?? []),
        'total_available' => intval($data['totalItems'] ?? 0),
        'nlp_result' => $nlpResult,
        'api_query' => $apiQuery
    ];
}

/**
 * Google Books API sorgusu oluştur
 */
function buildGoogleBooksQuery($filters) {
    $queryParts = [];
    
    // Anahtar kelime
    if (isset($filters['keyword']) && !empty($filters['keyword'])) {
        $queryParts[] = $filters['keyword'];
    }
    
    // Yazar
    if (isset($filters['author']) && !empty($filters['author'])) {
        $queryParts[] = 'inauthor:"' . $filters['author'] . '"';
    }
    
    // Kategori
    if (isset($filters['category']) && !empty($filters['category'])) {
        $queryParts[] = 'subject:"' . $filters['category'] . '"';
    }
    
    // Eğer hiç filtre yoksa genel arama
    if (empty($queryParts)) {
        $queryParts[] = isset($filters['keyword']) ? $filters['keyword'] : 'books';
    }
    
    return implode(' ', $queryParts);
}

/**
 * Kitabın filtrelere uyup uymadığını kontrol et
 */
function matchesFilters($book, $filters) {
    // Yıl filtresi
    if (isset($filters['year']) && !empty($book['published_date'])) {
        $bookYear = intval(substr($book['published_date'], 0, 4));
        if ($bookYear !== intval($filters['year'])) {
            return false;
        }
    }
    
    // Sayfa sayısı filtresi
    if (isset($filters['page_count']) && !empty($book['page_count'])) {
        $pageCount = intval($book['page_count']);
        $filter = $filters['page_count'];
        
        if (isset($filter['exact']) && $pageCount !== $filter['exact']) {
            return false;
        }
        if (isset($filter['min']) && $pageCount < $filter['min']) {
            return false;
        }
        if (isset($filter['max']) && $pageCount > $filter['max']) {
            return false;
        }
    }
    
    // Puan filtresi
    if (isset($filters['rating']) && !empty($book['average_rating'])) {
        $rating = floatval($book['average_rating']);
        $filter = $filters['rating'];
        
        if (isset($filter['min']) && $rating < $filter['min']) {
            return false;
        }
    }
    
    // Hedef kitle filtresi
    if (isset($filters['audience'])) {
        if (!isAppropriateForAudience($book, $filters['audience'])) {
            return false;
        }
    }
    
    return true;
}

/**
 * Hedef kitle uygunluğunu kontrol et
 */
function isAppropriateForAudience($book, $audience) {
    $categories = mb_strtolower($book['categories'] ?? '', 'UTF-8');
    $description = mb_strtolower($book['description'] ?? '', 'UTF-8');
    $content = $categories . ' ' . $description;
    
    switch ($audience) {
        case 'children':
            $childKeywords = ['çocuk', 'children', 'kids', 'masal', 'fairy tale', 'picture book'];
            foreach ($childKeywords as $keyword) {
                if (stripos($content, $keyword) !== false) {
                    return true;
                }
            }
            return false;
            
        case 'young_adult':
            $yaKeywords = ['genç', 'teen', 'teenager', 'young adult', 'ergen'];
            foreach ($yaKeywords as $keyword) {
                if (stripos($content, $keyword) !== false) {
                    return true;
                }
            }
            // Çocuk kitabı değilse genellikle uygun
            return !isAppropriateForAudience($book, 'children');
            
        case 'adult':
            // Çocuk kitabı değilse yetişkin için uygun
            return !isAppropriateForAudience($book, 'children');
    }
    
    return true;
}

/**
 * Relevance score hesapla
 */
function calculateRelevanceScore($book, $filters) {
    $score = 0.5; // Base score
    
    // Anahtar kelime match'i
    if (isset($filters['keyword'])) {
        $keyword = mb_strtolower($filters['keyword'], 'UTF-8');
        $title = mb_strtolower($book['title'] ?? '', 'UTF-8');
        $description = mb_strtolower($book['description'] ?? '', 'UTF-8');
        
        if (stripos($title, $keyword) !== false) {
            $score += 0.3; // Title match
        }
        if (stripos($description, $keyword) !== false) {
            $score += 0.1; // Description match
        }
    }
    
    // Yazar match'i
    if (isset($filters['author'])) {
        $filterAuthor = mb_strtolower($filters['author'], 'UTF-8');
        $bookAuthors = mb_strtolower($book['authors'] ?? '', 'UTF-8');
        
        if (stripos($bookAuthors, $filterAuthor) !== false) {
            $score += 0.25;
        }
    }
    
    // Kategori match'i
    if (isset($filters['category'])) {
        $filterCategory = mb_strtolower($filters['category'], 'UTF-8');
        $bookCategories = mb_strtolower($book['categories'] ?? '', 'UTF-8');
        
        if (stripos($bookCategories, $filterCategory) !== false) {
            $score += 0.2;
        }
    }
    
    // Kalite bonusu
    if (!empty($book['average_rating']) && floatval($book['average_rating']) >= 4.0) {
        $score += 0.1;
    }
    
    // Popülerlik bonusu
    if (!empty($book['ratings_count']) && intval($book['ratings_count']) >= 100) {
        $score += 0.05;
    }
    
    return min(1.0, $score);
}

/**
 * Klasik arama
 */
function performClassicSearch($query, $page, $maxResults) {
    $startIndex = ($page - 1) * $maxResults;
    
    $apiParams = [
        'q' => $query,
        'startIndex' => $startIndex,
        'maxResults' => $maxResults,
        'orderBy' => 'relevance',
        'printType' => 'books',
        'projection' => 'full'
    ];
    
    if (defined('GOOGLE_BOOKS_API_KEY') && GOOGLE_BOOKS_API_KEY) {
        $apiParams['key'] = GOOGLE_BOOKS_API_KEY;
    }
    
    $apiUrl = "https://www.googleapis.com/books/v1/volumes?" . http_build_query($apiParams);
    $response = makeHttpRequest($apiUrl);
    
    if (!$response['success']) {
        throw new Exception('Google Books API hatası: ' . $response['error']);
    }
    
    $data = json_decode($response['data'], true);
    
    $books = [];
    if (isset($data['items'])) {
        foreach ($data['items'] as $item) {
            $book = processBookItem($item);
            if ($book) {
                $book['relevance_score'] = 0.5; // Default score for classic search
                $books[] = $book;
            }
        }
    }
    
    return [
        'success' => true,
        'books' => $books,
        'total_found' => count($books),
        'total_available' => intval($data['totalItems'] ?? 0)
    ];
}

/**
 * Arama sonuçlarını birleştir
 */
function mergeSearchResults($smartResults, $classicResults) {
    $allBooks = $smartResults['books'];
    $seenBooks = array_column($allBooks, 'id');
    
    // Klasik aramadan yeni kitapları ekle
    foreach ($classicResults['books'] as $book) {
        if (!in_array($book['id'], $seenBooks)) {
            $book['relevance_score'] *= 0.8; // Klasik arama sonuçları için düşük skor
            $allBooks[] = $book;
        }
    }
    
    // Relevance score'a göre tekrar sırala
    usort($allBooks, function($a, $b) {
        return $b['relevance_score'] <=> $a['relevance_score'];
    });
    
    $smartResults['books'] = $allBooks;
    $smartResults['total_found'] = count($allBooks);
    
    return $smartResults;
}

/**
 * Kullanıcı tercihlerine göre sonuçları yeniden sırala
 */
function reorderByUserPreferences($books, $userId) {
    try {
        $userProfile = getUserPreferenceProfile($userId);
        
        if (empty($userProfile)) {
            return $books;
        }
        
        foreach ($books as &$book) {
            $preferenceBonus = 0;
            
            // Kategori tercihi bonusu
            if (!empty($userProfile['preferred_categories'])) {
                foreach ($userProfile['preferred_categories'] as $prefCat) {
                    if (stripos($book['categories'] ?? '', $prefCat['category']) !== false) {
                        $preferenceBonus += 0.1 * $prefCat['weight'];
                        break;
                    }
                }
            }
            
            // Yazar tercihi bonusu
            if (!empty($userProfile['preferred_authors'])) {
                foreach ($userProfile['preferred_authors'] as $prefAuthor) {
                    if (stripos($book['authors'] ?? '', $prefAuthor['author']) !== false) {
                        $preferenceBonus += 0.15 * $prefAuthor['weight'];
                        break;
                    }
                }
            }
            
            // Dil tercihi bonusu
            if (!empty($userProfile['preferred_languages'])) {
                foreach ($userProfile['preferred_languages'] as $prefLang) {
                    if (($book['language'] ?? 'tr') === $prefLang['language']) {
                        $preferenceBonus += 0.05 * $prefLang['weight'];
                        break;
                    }
                }
            }
            
            $book['relevance_score'] = min(1.0, $book['relevance_score'] + $preferenceBonus);
        }
        
        // Yeniden sırala
        usort($books, function($a, $b) {
            return $b['relevance_score'] <=> $a['relevance_score'];
        });
        
        return $books;
        
    } catch (Exception $e) {
        logError("Kullanıcı tercihi sıralama hatası", [
            'user_id' => $userId,
            'error' => $e->getMessage()
        ]);
        return $books;
    }
}

/**
 * İlgili AI önerilerini al
 */
function getRelevantAIRecommendations($userId, $query, $limit) {
    try {
        $url = 'ai_recommendations.php?' . http_build_query([
            'user_id' => $userId,
            'type' => 'content_based',
            'limit' => $limit
        ]);
        
        $response = @file_get_contents($url, false, stream_context_create([
            'http' => ['timeout' => 5]
        ]));
        
        if ($response === false) {
            return [];
        }
        
        $result = json_decode($response, true);
        
        if ($result && $result['success']) {
            return $result['recommendations'] ?? [];
        }
        
        return [];
        
    } catch (Exception $e) {
        logError("AI öneri alma hatası", [
            'user_id' => $userId,
            'query' => $query,
            'error' => $e->getMessage()
        ]);
        return [];
    }
}

/**
 * AI önerilerini arama sonuçlarına ekle
 */
function addAIRecommendations($searchResults, $aiRecommendations) {
    if (empty($aiRecommendations)) {
        return $searchResults;
    }
    
    $seenBooks = array_column($searchResults['books'], 'id');
    $addedCount = 0;
    
    foreach ($aiRecommendations as $rec) {
        if (!in_array($rec['book_google_id'], $seenBooks) && $addedCount < 3) {
            $book = [
                'id' => $rec['book_google_id'],
                'title' => $rec['title'],
                'authors' => $rec['authors'],
                'categories' => $rec['categories'],
                'thumbnail' => $rec['thumbnail'],
                'description' => $rec['description'],
                'relevance_score' => $rec['confidence_score'] * 0.6, // AI öneri skorunu düşür
                'source' => 'ai_recommendation'
            ];
            
            $searchResults['books'][] = $book;
            $addedCount++;
        }
    }
    
    if ($addedCount > 0) {
        $searchResults['total_found'] += $addedCount;
        $searchResults['ai_recommendations_added'] = $addedCount;
        
        // Tekrar sırala
        usort($searchResults['books'], function($a, $b) {
            return $b['relevance_score'] <=> $a['relevance_score'];
        });
    }
    
    return $searchResults;
}

/**
 * Arama aktivitesini kaydet
 */
function trackSearchActivity($userId, $query, $results, $usedNLP) {
    try {
        $activityData = [
            'search_type' => $usedNLP ? 'smart_nlp' : 'classic',
            'results_count' => $results['total_found'],
            'processing_time' => $results['processing_time_ms'] ?? 0
        ];
        
        if ($usedNLP && isset($results['nlp_result'])) {
            $activityData['nlp_confidence'] = $results['nlp_result']['confidence_score'];
            $activityData['filters_applied'] = $results['filters_applied'] ?? [];
        }
        
        trackUserActivity($userId, 'search', [], [
            'search_query' => $query,
            'search_data' => $activityData
        ]);
        
    } catch (Exception $e) {
        logError("Arama aktivitesi kaydetme hatası", [
            'user_id' => $userId,
            'query' => $query,
            'error' => $e->getMessage()
        ]);
    }
}

/**
 * Kitap item'ını işle (search.php'den adapt edildi)
 */
function processBookItem($item) {
    $info = $item['volumeInfo'] ?? [];
    
    if (empty($info['title'])) {
        return null;
    }
    
    return [
        'id' => $item['id'],
        'title' => $info['title'],
        'authors' => implode(', ', $info['authors'] ?? []),
        'categories' => implode(', ', $info['categories'] ?? []),
        'description' => $info['description'] ?? '',
        'thumbnail' => $info['imageLinks']['thumbnail'] ?? '',
        'page_count' => $info['pageCount'] ?? null,
        'published_date' => $info['publishedDate'] ?? '',
        'language' => $info['language'] ?? 'tr',
        'average_rating' => $info['averageRating'] ?? null,
        'ratings_count' => $info['ratingsCount'] ?? null,
        'info_link' => $info['infoLink'] ?? '',
        'preview_link' => $info['previewLink'] ?? ''
    ];
}

?> 