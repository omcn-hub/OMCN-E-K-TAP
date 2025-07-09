<?php
require_once 'config.php';

/**
 * AI Öneri Sistemi Yardımcı Fonksiyonları
 */

/**
 * Kullanıcı tercih profili oluştur
 */
function createUserPreferenceProfile($userId) {
    try {
        $pdo = getDbConnection();
        
        // Kullanıcının verilerini analiz et
        $preferredCategories = calculatePreferredCategories($userId);
        $preferredAuthors = calculatePreferredAuthors($userId);
        $preferredLanguages = calculatePreferredLanguages($userId);
        $readingPatterns = calculateReadingPatterns($userId);
        $keywordPreferences = calculateKeywordPreferences($userId);
        
        $stmt = $pdo->prepare("
            INSERT INTO user_preference_profiles 
            (user_id, preferred_categories, preferred_authors, preferred_languages, 
             reading_patterns, keyword_preferences, last_calculated_at) 
            VALUES (?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
            preferred_categories = VALUES(preferred_categories),
            preferred_authors = VALUES(preferred_authors),
            preferred_languages = VALUES(preferred_languages),
            reading_patterns = VALUES(reading_patterns),
            keyword_preferences = VALUES(keyword_preferences),
            last_calculated_at = NOW()
        ");
        
        $stmt->execute([
            $userId,
            json_encode($preferredCategories),
            json_encode($preferredAuthors),
            json_encode($preferredLanguages),
            json_encode($readingPatterns),
            json_encode($keywordPreferences)
        ]);
        
        logInfo("Kullanıcı tercih profili oluşturuldu", ['user_id' => $userId]);
        
    } catch (Exception $e) {
        logError("Kullanıcı profil oluşturma hatası", ['user_id' => $userId, 'error' => $e->getMessage()]);
    }
}

/**
 * Kullanıcı tercih profilini güncelle
 */
function updateUserPreferenceProfile($userId) {
    createUserPreferenceProfile($userId); // Aynı logic
}

/**
 * Tercih edilen kategorileri hesapla
 */
function calculatePreferredCategories($userId) {
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("
            SELECT book_categories, 
                   SUM(CASE WHEN is_favorite = 1 THEN 3 ELSE 1 END) as score,
                   COUNT(*) as count
            FROM user_books 
            WHERE user_id = ? AND book_categories IS NOT NULL AND book_categories != ''
            GROUP BY book_categories
            ORDER BY score DESC
            LIMIT 10
        ");
        $stmt->execute([$userId]);
        $results = $stmt->fetchAll();
        
        $categories = [];
        $maxScore = 0;
        
        foreach ($results as $row) {
            if ($maxScore == 0) $maxScore = $row['score'];
            
            $weight = $row['score'] / $maxScore; // Normalize
            $categories[] = [
                'category' => $row['book_categories'],
                'weight' => min(1.0, $weight),
                'count' => $row['count']
            ];
        }
        
        return $categories;
        
    } catch (Exception $e) {
        logError("Kategori tercihi hesaplama hatası", ['user_id' => $userId, 'error' => $e->getMessage()]);
        return [];
    }
}

/**
 * Tercih edilen yazarları hesapla
 */
function calculatePreferredAuthors($userId) {
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("
            SELECT book_authors, 
                   SUM(CASE WHEN is_favorite = 1 THEN 3 ELSE 1 END) as score,
                   COUNT(*) as count
            FROM user_books 
            WHERE user_id = ? AND book_authors IS NOT NULL AND book_authors != ''
            GROUP BY book_authors
            ORDER BY score DESC
            LIMIT 10
        ");
        $stmt->execute([$userId]);
        $results = $stmt->fetchAll();
        
        $authors = [];
        $maxScore = 0;
        
        foreach ($results as $row) {
            if ($maxScore == 0) $maxScore = $row['score'];
            
            $weight = $row['score'] / $maxScore;
            $authors[] = [
                'author' => $row['book_authors'],
                'weight' => min(1.0, $weight),
                'count' => $row['count']
            ];
        }
        
        return $authors;
        
    } catch (Exception $e) {
        logError("Yazar tercihi hesaplama hatası", ['user_id' => $userId, 'error' => $e->getMessage()]);
        return [];
    }
}

/**
 * Tercih edilen dilleri hesapla
 */
function calculatePreferredLanguages($userId) {
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("
            SELECT book_language, COUNT(*) as count
            FROM user_books 
            WHERE user_id = ? AND book_language IS NOT NULL
            GROUP BY book_language
            ORDER BY count DESC
        ");
        $stmt->execute([$userId]);
        $results = $stmt->fetchAll();
        
        $languages = [];
        $totalBooks = array_sum(array_column($results, 'count'));
        
        foreach ($results as $row) {
            $languages[] = [
                'language' => $row['book_language'],
                'weight' => $row['count'] / $totalBooks,
                'count' => $row['count']
            ];
        }
        
        return $languages;
        
    } catch (Exception $e) {
        logError("Dil tercihi hesaplama hatası", ['user_id' => $userId, 'error' => $e->getMessage()]);
        return [];
    }
}

/**
 * Okuma kalıplarını hesapla
 */
function calculateReadingPatterns($userId) {
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("
            SELECT AVG(book_page_count) as avg_pages,
                   COUNT(CASE WHEN reading_status = 'completed' THEN 1 END) as completed_books,
                   COUNT(CASE WHEN reading_status = 'reading' THEN 1 END) as currently_reading,
                   AVG(rating) as avg_rating
            FROM user_books 
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        
        $patterns = [
            'avg_pages' => intval($result['avg_pages'] ?? 0),
            'completed_books' => intval($result['completed_books'] ?? 0),
            'currently_reading' => intval($result['currently_reading'] ?? 0),
            'avg_rating' => floatval($result['avg_rating'] ?? 0),
            'reading_speed' => 'medium', // Bu daha karmaşık hesaplanabilir
            'preferred_length' => getPreferredLength(intval($result['avg_pages'] ?? 0))
        ];
        
        return $patterns;
        
    } catch (Exception $e) {
        logError("Okuma kalıpları hesaplama hatası", ['user_id' => $userId, 'error' => $e->getMessage()]);
        return [];
    }
}

/**
 * Anahtar kelime tercihlerini hesapla
 */
function calculateKeywordPreferences($userId) {
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("
            SELECT query, COUNT(*) as frequency
            FROM search_history 
            WHERE user_id = ?
            GROUP BY query
            ORDER BY frequency DESC
            LIMIT 20
        ");
        $stmt->execute([$userId]);
        $results = $stmt->fetchAll();
        
        $keywords = [];
        $maxFreq = 0;
        
        foreach ($results as $row) {
            if ($maxFreq == 0) $maxFreq = $row['frequency'];
            
            $keywords[] = [
                'keyword' => $row['query'],
                'weight' => $row['frequency'] / $maxFreq,
                'frequency' => $row['frequency']
            ];
        }
        
        return $keywords;
        
    } catch (Exception $e) {
        logError("Anahtar kelime tercihi hesaplama hatası", ['user_id' => $userId, 'error' => $e->getMessage()]);
        return [];
    }
}

/**
 * Tercih edilen kitap uzunluğunu belirle
 */
function getPreferredLength($avgPages) {
    if ($avgPages < 150) return 'short';
    if ($avgPages < 300) return 'medium';
    return 'long';
}

/**
 * Kategoriye göre kitap ara (Google Books API)
 */
function searchBooksByCategory($category, $limit = 5) {
    try {
        $apiKey = defined('GOOGLE_BOOKS_API_KEY') ? GOOGLE_BOOKS_API_KEY : '';
        $apiUrl = "https://www.googleapis.com/books/v1/volumes";
        
        $params = [
            'q' => 'subject:' . urlencode($category),
            'maxResults' => $limit,
            'orderBy' => 'relevance',
            'printType' => 'books',
            'langRestrict' => 'tr'
        ];
        
        if (!empty($apiKey)) {
            $params['key'] = $apiKey;
        }
        
        $apiUrl .= '?' . http_build_query($params);
        $response = makeHttpRequest($apiUrl);
        
        if (!$response['success']) {
            return [];
        }
        
        $data = json_decode($response['data'], true);
        
        if (!isset($data['items'])) {
            return [];
        }
        
        $books = [];
        foreach ($data['items'] as $item) {
            $info = $item['volumeInfo'] ?? [];
            
            $books[] = [
                'id' => $item['id'],
                'title' => $info['title'] ?? '',
                'authors' => implode(', ', $info['authors'] ?? []),
                'categories' => implode(', ', $info['categories'] ?? []),
                'description' => $info['description'] ?? '',
                'thumbnail' => $info['imageLinks']['thumbnail'] ?? ''
            ];
        }
        
        return $books;
        
    } catch (Exception $e) {
        logError("Kategoriye göre arama hatası", ['category' => $category, 'error' => $e->getMessage()]);
        return [];
    }
}

/**
 * Yazara göre kitap ara (Google Books API)
 */
function searchBooksByAuthor($author, $limit = 3) {
    try {
        $apiKey = defined('GOOGLE_BOOKS_API_KEY') ? GOOGLE_BOOKS_API_KEY : '';
        $apiUrl = "https://www.googleapis.com/books/v1/volumes";
        
        $params = [
            'q' => 'inauthor:' . urlencode($author),
            'maxResults' => $limit,
            'orderBy' => 'relevance',
            'printType' => 'books'
        ];
        
        if (!empty($apiKey)) {
            $params['key'] = $apiKey;
        }
        
        $apiUrl .= '?' . http_build_query($params);
        $response = makeHttpRequest($apiUrl);
        
        if (!$response['success']) {
            return [];
        }
        
        $data = json_decode($response['data'], true);
        
        if (!isset($data['items'])) {
            return [];
        }
        
        $books = [];
        foreach ($data['items'] as $item) {
            $info = $item['volumeInfo'] ?? [];
            
            $books[] = [
                'id' => $item['id'],
                'title' => $info['title'] ?? '',
                'authors' => implode(', ', $info['authors'] ?? []),
                'categories' => implode(', ', $info['categories'] ?? []),
                'description' => $info['description'] ?? '',
                'thumbnail' => $info['imageLinks']['thumbnail'] ?? ''
            ];
        }
        
        return $books;
        
    } catch (Exception $e) {
        logError("Yazara göre arama hatası", ['author' => $author, 'error' => $e->getMessage()]);
        return [];
    }
}

/**
 * Fallback öneriler (popüler kitaplar)
 */
function getFallbackRecommendations($limit) {
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("
            SELECT ub.book_google_id, ub.book_title, ub.book_authors, 
                   ub.book_categories, ub.book_thumbnail, ub.book_description,
                   COUNT(*) as popularity_score
            FROM user_books ub 
            WHERE ub.book_google_id IS NOT NULL
            GROUP BY ub.book_google_id
            ORDER BY popularity_score DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $results = $stmt->fetchAll();
        
        $recommendations = [];
        foreach ($results as $book) {
            $recommendations[] = [
                'book_google_id' => $book['book_google_id'],
                'title' => $book['book_title'],
                'authors' => $book['book_authors'],
                'categories' => $book['book_categories'],
                'thumbnail' => $book['book_thumbnail'],
                'description' => $book['book_description'],
                'confidence_score' => 0.5,
                'reason_tags' => ['popular', 'fallback'],
                'recommendation_type' => 'fallback'
            ];
        }
        
        return $recommendations;
        
    } catch (Exception $e) {
        logError("Fallback öneriler hatası", ['error' => $e->getMessage()]);
        return [];
    }
}

/**
 * Collaborative filtering güven puanı hesapla
 */
function calculateCollaborativeConfidence($book, $similarUsers) {
    $totalSimilarity = array_sum($similarUsers);
    $weightedRating = 0;
    
    // Ağırlıklı ortalama hesapla
    foreach ($similarUsers as $userId => $similarity) {
        $weightedRating += $similarity * ($book['avg_rating'] ?? 3);
    }
    
    if ($totalSimilarity > 0) {
        $finalRating = $weightedRating / $totalSimilarity;
        return min(1.0, $finalRating / 5.0); // 0-1 arasına normalize et
    }
    
    return 0.5; // Default
}

/**
 * HTTP Request yapmak için yardımcı fonksiyon
 */
function makeHttpRequest($url, $timeout = 30) {
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_ENCODING => '',
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

/**
 * Kullanıcı aktivitesini kaydet
 */
function trackUserActivity($userId, $activityType, $bookData = [], $extraData = []) {
    try {
        $pdo = getDbConnection();
        
        $activityScore = getActivityScore($activityType);
        
        $stmt = $pdo->prepare("
            INSERT INTO user_activities 
            (user_id, activity_type, book_google_id, book_title, book_authors, 
             book_categories, search_query, activity_data, activity_score) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $userId,
            $activityType,
            $bookData['google_id'] ?? null,
            $bookData['title'] ?? null,
            $bookData['authors'] ?? null,
            $bookData['categories'] ?? null,
            $extraData['search_query'] ?? null,
            json_encode($extraData),
            $activityScore
        ]);
        
        // Profil güncellemesi tetikle (opsiyonel - belirli aktivite sayısından sonra)
        triggerProfileUpdateIfNeeded($userId);
        
    } catch (Exception $e) {
        logError("Aktivite kaydetme hatası", [
            'user_id' => $userId,
            'activity_type' => $activityType,
            'error' => $e->getMessage()
        ]);
    }
}

/**
 * Aktivite tipine göre skor belirle
 */
function getActivityScore($activityType) {
    $scores = [
        'view' => 1.0,
        'search' => 0.5,
        'favorite' => 3.0,
        'unfavorite' => -1.0,
        'read_start' => 2.0,
        'read_progress' => 1.5,
        'read_complete' => 4.0,
        'rate' => 2.5
    ];
    
    return $scores[$activityType] ?? 1.0;
}

/**
 * Gerekirse profil güncellemeyi tetikle
 */
function triggerProfileUpdateIfNeeded($userId) {
    try {
        $pdo = getDbConnection();
        
        // Son 24 saatteki aktivite sayısını kontrol et
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as activity_count
            FROM user_activities 
            WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        
        // 10'dan fazla aktivite varsa profili güncelle
        if ($result && $result['activity_count'] >= 10) {
            updateUserPreferenceProfile($userId);
        }
        
    } catch (Exception $e) {
        logError("Profil güncelleme kontrolü hatası", ['user_id' => $userId, 'error' => $e->getMessage()]);
    }
}

/**
 * Kullanıcı tercihleri profilini getir
 */
function getUserPreferenceProfile($userId) {
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("
            SELECT * FROM user_preference_profiles 
            WHERE user_id = ? 
            ORDER BY updated_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $profile = $stmt->fetch();
        
        if ($profile) {
            return [
                'preferred_categories' => json_decode($profile['preferred_categories'], true) ?: [],
                'preferred_authors' => json_decode($profile['preferred_authors'], true) ?: [],
                'preferred_languages' => json_decode($profile['preferred_languages'], true) ?: [],
                'reading_patterns' => json_decode($profile['reading_patterns'], true) ?: [],
                'keyword_preferences' => json_decode($profile['keyword_preferences'], true) ?: [],
                'total_engagement_score' => floatval($profile['total_engagement_score']),
                'updated_at' => $profile['updated_at']
            ];
        }
        
        return null;
        
    } catch (Exception $e) {
        logError("Kullanıcı profil alma hatası", ['user_id' => $userId, 'error' => $e->getMessage()]);
        return null;
    }
}

/**
 * Kullanıcı öneri cache'ini temizle
 */
function clearUserRecommendationCache($userId) {
    try {
        $pdo = getDbConnection();
        
        // Öneri cache'lerini temizle
        $stmt = $pdo->prepare("DELETE FROM ai_recommendations WHERE user_id = ?");
        $stmt->execute([$userId]);
        
        // NLP sorgu cache'lerini de temizle (opsiyonel)
        $stmt = $pdo->prepare("DELETE FROM nlp_query_cache WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $stmt->execute();
        
        logInfo("Kullanıcı öneri cache'i temizlendi", ['user_id' => $userId]);
        
    } catch (Exception $e) {
        logError("Öneri cache temizleme hatası", ['user_id' => $userId, 'error' => $e->getMessage()]);
    }
}

/**
 * Cache'den önerileri al
 */
function getCachedRecommendations($userId, $type, $limit) {
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("
            SELECT * FROM ai_recommendations 
            WHERE user_id = ? AND recommendation_type = ? AND expires_at > NOW()
            ORDER BY confidence_score DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $type, $limit]);
        $results = $stmt->fetchAll();
        
        $recommendations = [];
        foreach ($results as $rec) {
            $recommendations[] = [
                'book_google_id' => $rec['book_google_id'],
                'title' => $rec['book_title'],
                'authors' => $rec['book_authors'],
                'categories' => $rec['book_categories'],
                'thumbnail' => $rec['book_thumbnail'],
                'description' => $rec['book_description'],
                'confidence_score' => floatval($rec['confidence_score']),
                'reason_tags' => json_decode($rec['reason_tags'], true) ?? [],
                'recommendation_type' => $rec['recommendation_type']
            ];
        }
        
        return empty($recommendations) ? null : $recommendations;
        
    } catch (Exception $e) {
        logError("Cache öneri alma hatası", ['user_id' => $userId, 'error' => $e->getMessage()]);
        return null;
    }
}

/**
 * Önerileri cache'e kaydet
 */
function cacheRecommendations($userId, $type, $recommendations) {
    try {
        $pdo = getDbConnection();
        
        // Eski cache'i temizle
        $stmt = $pdo->prepare("
            DELETE FROM ai_recommendations 
            WHERE user_id = ? AND recommendation_type = ?
        ");
        $stmt->execute([$userId, $type]);
        
        // Yeni önerileri kaydet
        $stmt = $pdo->prepare("
            INSERT INTO ai_recommendations 
            (user_id, recommendation_type, book_google_id, book_title, book_authors, 
             book_categories, book_description, book_thumbnail, confidence_score, 
             reason_tags, expires_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))
        ");
        
        foreach ($recommendations as $rec) {
            $stmt->execute([
                $userId,
                $type,
                $rec['book_google_id'],
                $rec['title'],
                $rec['authors'],
                $rec['categories'],
                $rec['description'],
                $rec['thumbnail'],
                $rec['confidence_score'],
                json_encode($rec['reason_tags'])
            ]);
        }
        
        logInfo("Öneriler cache'e kaydedildi", ['user_id' => $userId, 'type' => $type, 'count' => count($recommendations)]);
        
    } catch (Exception $e) {
        logError("Cache kaydetme hatası", ['user_id' => $userId, 'error' => $e->getMessage()]);
    }
}

?> 