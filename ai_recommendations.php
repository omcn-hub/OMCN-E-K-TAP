<?php
require_once 'config.php';
require_once 'ai_recommendation_helpers.php';

/**
 * AI Tabanlı Kitap Öneri Sistemi
 * Collaborative Filtering + Content-Based + Hybrid yaklaşımları
 */

startSecureSession();

// Rate limiting
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!checkRateLimit("ai_recommendations:$clientIp", 20, 60)) {
    jsonResponse(['error' => 'Çok fazla öneri isteği. Lütfen bir dakika sonra tekrar deneyin.'], 429);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    handleRecommendationRequest();
} else {
    jsonResponse(['error' => 'Desteklenmeyen HTTP metodu'], 405);
}

function handleRecommendationRequest() {
    $userId = intval($_GET['user_id'] ?? 0);
    $type = sanitizeInput($_GET['type'] ?? 'hybrid');
    $limit = min(20, max(5, intval($_GET['limit'] ?? 10)));
    $refresh = isset($_GET['refresh']) && $_GET['refresh'] === 'true';
    
    if ($userId <= 0) {
        jsonResponse(['error' => 'Geçerli bir kullanıcı ID gerekli'], 400);
    }
    
    try {
        // Cache kontrolü (refresh değilse)
        if (!$refresh) {
            $cachedRecommendations = getCachedRecommendations($userId, $type, $limit);
            if ($cachedRecommendations) {
                jsonResponse([
                    'success' => true,
                    'recommendations' => $cachedRecommendations,
                    'type' => $type,
                    'cached' => true,
                    'user_id' => $userId
                ]);
            }
        }
        
        // Yeni öneriler oluştur
        $recommendations = generateRecommendations($userId, $type, $limit);
        
        if (empty($recommendations)) {
            // Fallback: popüler kitaplar
            $recommendations = getFallbackRecommendations($limit);
        }
        
        // Cache'e kaydet
        if (!empty($recommendations)) {
            cacheRecommendations($userId, $type, $recommendations);
        }
        
        jsonResponse([
            'success' => true,
            'recommendations' => $recommendations,
            'type' => $type,
            'cached' => false,
            'user_id' => $userId
        ]);
        
    } catch (Exception $e) {
        logError("AI öneri sistemi hatası", [
            'user_id' => $userId,
            'type' => $type,
            'error' => $e->getMessage()
        ]);
        
        jsonResponse(['error' => 'Öneriler oluşturulurken bir hata oluştu'], 500);
    }
}

/**
 * Ana öneri oluşturma fonksiyonu
 */
function generateRecommendations($userId, $type, $limit) {
    $recommendations = [];
    
    switch ($type) {
        case 'collaborative':
            $recommendations = getCollaborativeRecommendations($userId, $limit);
            break;
            
        case 'content_based':
            $recommendations = getContentBasedRecommendations($userId, $limit);
            break;
            
        case 'category_based':
            $recommendations = getCategoryBasedRecommendations($userId, $limit);
            break;
            
        case 'trending':
            $recommendations = getTrendingRecommendations($limit);
            break;
            
        case 'hybrid':
        default:
            $recommendations = getHybridRecommendations($userId, $limit);
            break;
    }
    
    return $recommendations;
}

/**
 * Collaborative Filtering (Kullanıcı-Kullanıcı Benzerliği)
 */
function getCollaborativeRecommendations($userId, $limit) {
    try {
        $pdo = getDbConnection();
        
        // Benzer kullanıcıları bul
        $similarUsers = findSimilarUsers($userId, 10);
        
        if (empty($similarUsers)) {
            return [];
        }
        
        $similarUserIds = array_keys($similarUsers);
        $placeholders = str_repeat('?,', count($similarUserIds) - 1) . '?';
        
        // Benzer kullanıcıların favori kitaplarını al
        $stmt = $pdo->prepare("
            SELECT DISTINCT ub.book_google_id, ub.book_title, ub.book_authors, 
                   ub.book_categories, ub.book_thumbnail, ub.book_description,
                   AVG(ub.rating) as avg_rating,
                   COUNT(*) as popularity_score
            FROM user_books ub 
            WHERE ub.user_id IN ($placeholders) 
            AND ub.is_favorite = 1 
            AND ub.book_google_id NOT IN (
                SELECT book_google_id FROM user_books 
                WHERE user_id = ? AND book_google_id IS NOT NULL
            )
            GROUP BY ub.book_google_id
            ORDER BY avg_rating DESC, popularity_score DESC
            LIMIT ?
        ");
        
        $params = array_merge($similarUserIds, [$userId, $limit]);
        $stmt->execute($params);
        $results = $stmt->fetchAll();
        
        $recommendations = [];
        foreach ($results as $book) {
            $confidence = calculateCollaborativeConfidence($book, $similarUsers);
            
            $recommendations[] = [
                'book_google_id' => $book['book_google_id'],
                'title' => $book['book_title'],
                'authors' => $book['book_authors'],
                'categories' => $book['book_categories'],
                'thumbnail' => $book['book_thumbnail'],
                'description' => $book['book_description'],
                'confidence_score' => $confidence,
                'reason_tags' => ['similar_users', 'highly_rated'],
                'recommendation_type' => 'collaborative'
            ];
        }
        
        return $recommendations;
        
    } catch (Exception $e) {
        logError("Collaborative filtering hatası", ['user_id' => $userId, 'error' => $e->getMessage()]);
        return [];
    }
}

/**
 * Content-Based Filtering (İçerik Benzerliği)
 */
function getContentBasedRecommendations($userId, $limit) {
    try {
        $pdo = getDbConnection();
        
        // Kullanıcının tercihleri profili
        $userProfile = getUserPreferenceProfile($userId);
        
        if (empty($userProfile)) {
            return [];
        }
        
        // Google Books API'den öneriler al
        $recommendations = [];
        
        // En çok tercih edilen kategorilerde ara
        if (!empty($userProfile['preferred_categories'])) {
            foreach ($userProfile['preferred_categories'] as $category) {
                if (count($recommendations) >= $limit) break;
                
                $categoryBooks = searchBooksByCategory($category['category'], 5);
                foreach ($categoryBooks as $book) {
                    $confidence = $category['weight'] * 0.8; // Kategori ağırlığı
                    
                    $recommendations[] = [
                        'book_google_id' => $book['id'],
                        'title' => $book['title'],
                        'authors' => $book['authors'] ?? '',
                        'categories' => $book['categories'] ?? '',
                        'thumbnail' => $book['thumbnail'] ?? '',
                        'description' => $book['description'] ?? '',
                        'confidence_score' => $confidence,
                        'reason_tags' => ['favorite_category', $category['category']],
                        'recommendation_type' => 'content_based'
                    ];
                }
            }
        }
        
        // En çok tercih edilen yazarlardan ara
        if (!empty($userProfile['preferred_authors']) && count($recommendations) < $limit) {
            foreach ($userProfile['preferred_authors'] as $author) {
                if (count($recommendations) >= $limit) break;
                
                $authorBooks = searchBooksByAuthor($author['author'], 3);
                foreach ($authorBooks as $book) {
                    $confidence = $author['weight'] * 0.9; // Yazar tercihi yüksek ağırlık
                    
                    $recommendations[] = [
                        'book_google_id' => $book['id'],
                        'title' => $book['title'],
                        'authors' => $book['authors'] ?? '',
                        'categories' => $book['categories'] ?? '',
                        'thumbnail' => $book['thumbnail'] ?? '',
                        'description' => $book['description'] ?? '',
                        'confidence_score' => $confidence,
                        'reason_tags' => ['favorite_author', $author['author']],
                        'recommendation_type' => 'content_based'
                    ];
                }
            }
        }
        
        // Güven puanına göre sırala ve limitle
        usort($recommendations, function($a, $b) {
            return $b['confidence_score'] <=> $a['confidence_score'];
        });
        
        return array_slice($recommendations, 0, $limit);
        
    } catch (Exception $e) {
        logError("Content-based filtering hatası", ['user_id' => $userId, 'error' => $e->getMessage()]);
        return [];
    }
}

/**
 * Hybrid Recommendations (Karışık Yaklaşım)
 */
function getHybridRecommendations($userId, $limit) {
    $collaborativeRecs = getCollaborativeRecommendations($userId, $limit * 0.6);
    $contentBasedRecs = getContentBasedRecommendations($userId, $limit * 0.3);
    $trendingRecs = getTrendingRecommendations($limit * 0.1);
    
    // Tüm önerileri birleştir
    $allRecommendations = array_merge($collaborativeRecs, $contentBasedRecs, $trendingRecs);
    
    // Dublicatları kaldır
    $uniqueRecs = [];
    $seenBooks = [];
    
    foreach ($allRecommendations as $rec) {
        $bookId = $rec['book_google_id'];
        if (!isset($seenBooks[$bookId])) {
            $seenBooks[$bookId] = true;
            $rec['recommendation_type'] = 'hybrid';
            $uniqueRecs[] = $rec;
        }
    }
    
    // Güven puanına göre sırala
    usort($uniqueRecs, function($a, $b) {
        return $b['confidence_score'] <=> $a['confidence_score'];
    });
    
    return array_slice($uniqueRecs, 0, $limit);
}

/**
 * Kategori Tabanlı Öneriler
 */
function getCategoryBasedRecommendations($userId, $limit) {
    try {
        $pdo = getDbConnection();
        
        // Kullanıcının en çok favori eklediği kategorileri bul
        $stmt = $pdo->prepare("
            SELECT book_categories, COUNT(*) as category_count
            FROM user_books 
            WHERE user_id = ? AND is_favorite = 1 AND book_categories IS NOT NULL
            GROUP BY book_categories
            ORDER BY category_count DESC
            LIMIT 3
        ");
        $stmt->execute([$userId]);
        $favoriteCategories = $stmt->fetchAll();
        
        $recommendations = [];
        
        foreach ($favoriteCategories as $cat) {
            if (count($recommendations) >= $limit) break;
            
            $categoryBooks = searchBooksByCategory($cat['book_categories'], 5);
            foreach ($categoryBooks as $book) {
                $confidence = min(0.9, $cat['category_count'] * 0.1); // Kategori popülerliğine göre
                
                $recommendations[] = [
                    'book_google_id' => $book['id'],
                    'title' => $book['title'],
                    'authors' => $book['authors'] ?? '',
                    'categories' => $book['categories'] ?? '',
                    'thumbnail' => $book['thumbnail'] ?? '',
                    'description' => $book['description'] ?? '',
                    'confidence_score' => $confidence,
                    'reason_tags' => ['category_preference', $cat['book_categories']],
                    'recommendation_type' => 'category_based'
                ];
            }
        }
        
        return array_slice($recommendations, 0, $limit);
        
    } catch (Exception $e) {
        logError("Category-based recommendations hatası", ['user_id' => $userId, 'error' => $e->getMessage()]);
        return [];
    }
}

/**
 * Trending Kitaplar (Popüler)
 */
function getTrendingRecommendations($limit) {
    try {
        $pdo = getDbConnection();
        
        // Son 30 gündeki en popüler kitaplar
        $stmt = $pdo->prepare("
            SELECT ub.book_google_id, ub.book_title, ub.book_authors, 
                   ub.book_categories, ub.book_thumbnail, ub.book_description,
                   COUNT(*) as popularity_score,
                   AVG(ub.rating) as avg_rating
            FROM user_books ub 
            WHERE ub.saved_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            AND ub.book_google_id IS NOT NULL
            GROUP BY ub.book_google_id
            HAVING COUNT(*) >= 2
            ORDER BY popularity_score DESC, avg_rating DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $results = $stmt->fetchAll();
        
        $recommendations = [];
        foreach ($results as $book) {
            $confidence = min(0.8, $book['popularity_score'] * 0.1);
            
            $recommendations[] = [
                'book_google_id' => $book['book_google_id'],
                'title' => $book['book_title'],
                'authors' => $book['book_authors'],
                'categories' => $book['book_categories'],
                'thumbnail' => $book['book_thumbnail'],
                'description' => $book['book_description'],
                'confidence_score' => $confidence,
                'reason_tags' => ['trending', 'popular'],
                'recommendation_type' => 'trending'
            ];
        }
        
        return $recommendations;
        
    } catch (Exception $e) {
        logError("Trending recommendations hatası", ['error' => $e->getMessage()]);
        return [];
    }
}

/**
 * Benzer kullanıcıları bul (Cosine Similarity)
 */
function findSimilarUsers($userId, $limit = 10) {
    try {
        $pdo = getDbConnection();
        
        // Kullanıcının kitap tercihlerini al
        $stmt = $pdo->prepare("
            SELECT book_google_id, rating, is_favorite 
            FROM user_books 
            WHERE user_id = ? AND book_google_id IS NOT NULL
        ");
        $stmt->execute([$userId]);
        $userBooks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($userBooks)) {
            return [];
        }
        
        // Diğer kullanıcıları karşılaştır
        $stmt = $pdo->prepare("
            SELECT DISTINCT user_id 
            FROM user_books 
            WHERE user_id != ? AND book_google_id IS NOT NULL
            LIMIT 100
        ");
        $stmt->execute([$userId]);
        $otherUsers = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $similarities = [];
        
        foreach ($otherUsers as $otherUserId) {
            $similarity = calculateUserSimilarity($userId, $otherUserId, $userBooks);
            if ($similarity > 0.3) { // Minimum benzerlik eşiği
                $similarities[$otherUserId] = $similarity;
            }
        }
        
        // Benzerlik puanına göre sırala
        arsort($similarities);
        
        return array_slice($similarities, 0, $limit, true);
        
    } catch (Exception $e) {
        logError("Benzer kullanıcı bulma hatası", ['user_id' => $userId, 'error' => $e->getMessage()]);
        return [];
    }
}

/**
 * İki kullanıcı arasındaki benzerliği hesapla
 */
function calculateUserSimilarity($userId1, $userId2, $user1Books = null) {
    try {
        $pdo = getDbConnection();
        
        if ($user1Books === null) {
            $stmt = $pdo->prepare("
                SELECT book_google_id, rating, is_favorite 
                FROM user_books 
                WHERE user_id = ? AND book_google_id IS NOT NULL
            ");
            $stmt->execute([$userId1]);
            $user1Books = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        $stmt = $pdo->prepare("
            SELECT book_google_id, rating, is_favorite 
            FROM user_books 
            WHERE user_id = ? AND book_google_id IS NOT NULL
        ");
        $stmt->execute([$userId2]);
        $user2Books = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Ortak kitapları bul
        $user1BookIds = array_column($user1Books, 'book_google_id');
        $user2BookIds = array_column($user2Books, 'book_google_id');
        $commonBooks = array_intersect($user1BookIds, $user2BookIds);
        
        if (count($commonBooks) < 2) {
            return 0; // Minimum 2 ortak kitap gerekli
        }
        
        // Cosine similarity hesapla
        $user1Ratings = [];
        $user2Ratings = [];
        
        foreach ($commonBooks as $bookId) {
            $rating1 = 0;
            $rating2 = 0;
            
            foreach ($user1Books as $book) {
                if ($book['book_google_id'] === $bookId) {
                    $rating1 = $book['rating'] ?? ($book['is_favorite'] ? 5 : 3);
                    break;
                }
            }
            
            foreach ($user2Books as $book) {
                if ($book['book_google_id'] === $bookId) {
                    $rating2 = $book['rating'] ?? ($book['is_favorite'] ? 5 : 3);
                    break;
                }
            }
            
            $user1Ratings[] = $rating1;
            $user2Ratings[] = $rating2;
        }
        
        return cosineSimilarity($user1Ratings, $user2Ratings);
        
    } catch (Exception $e) {
        logError("Kullanıcı benzerlik hesaplama hatası", [
            'user1' => $userId1, 
            'user2' => $userId2, 
            'error' => $e->getMessage()
        ]);
        return 0;
    }
}

/**
 * Cosine Similarity hesaplama
 */
function cosineSimilarity($vector1, $vector2) {
    if (count($vector1) !== count($vector2) || empty($vector1)) {
        return 0;
    }
    
    $dotProduct = 0;
    $magnitude1 = 0;
    $magnitude2 = 0;
    
    for ($i = 0; $i < count($vector1); $i++) {
        $dotProduct += $vector1[$i] * $vector2[$i];
        $magnitude1 += $vector1[$i] * $vector1[$i];
        $magnitude2 += $vector2[$i] * $vector2[$i];
    }
    
    $magnitude1 = sqrt($magnitude1);
    $magnitude2 = sqrt($magnitude2);
    
    if ($magnitude1 == 0 || $magnitude2 == 0) {
        return 0;
    }
    
    return $dotProduct / ($magnitude1 * $magnitude2);
}

?> 