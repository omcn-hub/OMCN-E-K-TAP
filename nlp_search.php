<?php
require_once 'config.php';
require_once 'ai_recommendation_helpers.php';

/**
 * Doğal Dil İşleme ve Akıllı Arama Sistemi
 * Kullanıcının doğal dilde yazdığı cümleleri kitap filtrelerine çevirir
 */

startSecureSession();

// Rate limiting
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!checkRateLimit("nlp_search:$clientIp", 30, 60)) {
    jsonResponse(['error' => 'Çok fazla NLP arama isteği. Lütfen bir dakika sonra tekrar deneyin.'], 429);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    handleNLPSearch();
} else {
    jsonResponse(['error' => 'Desteklenmeyen HTTP metodu'], 405);
}

function handleNLPSearch() {
    $query = sanitizeInput($_GET['q'] ?? '');
    $userId = intval($_GET['user_id'] ?? 0);
    $useCache = !isset($_GET['no_cache']);
    
    if (empty($query)) {
        jsonResponse(['error' => 'Arama sorgusu boş olamaz'], 400);
    }
    
    if (strlen($query) < 3) {
        jsonResponse(['error' => 'Arama sorgusu en az 3 karakter olmalı'], 400);
    }
    
    if (strlen($query) > 500) {
        jsonResponse(['error' => 'Arama sorgusu çok uzun'], 400);
    }
    
    try {
        $startTime = microtime(true);
        
        // Cache kontrolü
        if ($useCache) {
            $cachedResult = getNLPCacheResult($query);
            if ($cachedResult) {
                $cachedResult['cached'] = true;
                $cachedResult['processing_time_ms'] = 0;
                jsonResponse($cachedResult);
            }
        }
        
        // Doğal dil işleme
        $nlpResult = processNaturalLanguageQuery($query, $userId);
        
        if ($nlpResult['confidence_score'] < 0.3) {
            // Düşük güven puanı - fallback arama
            $nlpResult = performFallbackSearch($query);
        }
        
        // Cache'e kaydet
        if ($nlpResult['confidence_score'] >= 0.5) {
            cacheNLPResult($query, $nlpResult, microtime(true) - $startTime);
        }
        
        // Kullanıcı aktivitesini kaydet
        if ($userId > 0) {
            trackNLPSearchActivity($userId, $query, $nlpResult);
        }
        
        $nlpResult['cached'] = false;
        $nlpResult['processing_time_ms'] = round((microtime(true) - $startTime) * 1000, 2);
        
        jsonResponse($nlpResult);
        
    } catch (Exception $e) {
        logError("NLP arama hatası", [
            'query' => $query,
            'user_id' => $userId,
            'error' => $e->getMessage()
        ]);
        
        jsonResponse([
            'error' => 'Doğal dil işleme sırasında bir hata oluştu',
            'original_query' => $query,
            'filters' => [],
            'confidence_score' => 0
        ], 500);
    }
}

/**
 * Ana doğal dil işleme fonksiyonu
 */
function processNaturalLanguageQuery($query, $userId = 0) {
    $query = mb_strtolower(trim($query), 'UTF-8');
    
    // Filtreleri başlat
    $filters = [
        'author' => null,
        'category' => null,
        'language' => null,
        'keyword' => null,
        'year' => null,
        'rating' => null,
        'page_count' => null,
        'audience' => null,
        'format' => null
    ];
    
    $confidence = 0.0;
    $matchedPatterns = [];
    
    // 1. Yazar filtreleme
    $authorResult = extractAuthorFilter($query);
    if ($authorResult['found']) {
        $filters['author'] = $authorResult['author'];
        $confidence += 0.2;
        $matchedPatterns[] = 'author';
        $query = $authorResult['remaining_query'];
    }
    
    // 2. Kategori filtreleme
    $categoryResult = extractCategoryFilter($query);
    if ($categoryResult['found']) {
        $filters['category'] = $categoryResult['category'];
        $confidence += 0.2;
        $matchedPatterns[] = 'category';
        $query = $categoryResult['remaining_query'];
    }
    
    // 3. Dil filtreleme
    $languageResult = extractLanguageFilter($query);
    if ($languageResult['found']) {
        $filters['language'] = $languageResult['language'];
        $confidence += 0.15;
        $matchedPatterns[] = 'language';
        $query = $languageResult['remaining_query'];
    }
    
    // 4. Hedef kitle filtreleme
    $audienceResult = extractAudienceFilter($query);
    if ($audienceResult['found']) {
        $filters['audience'] = $audienceResult['audience'];
        $confidence += 0.1;
        $matchedPatterns[] = 'audience';
        $query = $audienceResult['remaining_query'];
    }
    
    // 5. Yıl filtreleme
    $yearResult = extractYearFilter($query);
    if ($yearResult['found']) {
        $filters['year'] = $yearResult['year'];
        $confidence += 0.1;
        $matchedPatterns[] = 'year';
        $query = $yearResult['remaining_query'];
    }
    
    // 6. Sayfa sayısı filtreleme
    $pageResult = extractPageCountFilter($query);
    if ($pageResult['found']) {
        $filters['page_count'] = $pageResult['page_count'];
        $confidence += 0.05;
        $matchedPatterns[] = 'page_count';
        $query = $pageResult['remaining_query'];
    }
    
    // 7. Puan filtreleme
    $ratingResult = extractRatingFilter($query);
    if ($ratingResult['found']) {
        $filters['rating'] = $ratingResult['rating'];
        $confidence += 0.05;
        $matchedPatterns[] = 'rating';
        $query = $ratingResult['remaining_query'];
    }
    
    // 8. Kalan kelimeler anahtar kelime olarak
    $cleanQuery = trim(preg_replace('/\s+/', ' ', $query));
    if (!empty($cleanQuery)) {
        $filters['keyword'] = $cleanQuery;
        $confidence += 0.15;
        $matchedPatterns[] = 'keyword';
    }
    
    // Kullanıcı tercihlerine göre güven puanını artır
    if ($userId > 0) {
        $confidence = adjustConfidenceByUserPreferences($filters, $userId, $confidence);
    }
    
    // Minimm güven puanı kontrolü
    $confidence = max(0.3, $confidence);
    
    return [
        'success' => true,
        'original_query' => $_GET['q'] ?? '',
        'processed_query' => $cleanQuery,
        'filters' => array_filter($filters), // null değerleri kaldır
        'confidence_score' => round($confidence, 3),
        'matched_patterns' => $matchedPatterns,
        'suggestion_type' => 'nlp_processed'
    ];
}

/**
 * Yazar filtresini çıkar
 */
function extractAuthorFilter($query) {
    $patterns = [
        '/(.+?)\s+(?:kitapları|kitabı|eser|eserleri|yazarı|dan|den|tarafından)\b/u',
        '/(?:yazar|writer|author)[:]\s*([^,\n]+)/ui',
        '/(?:george orwell|agatha christie|haruki murakami|orhan pamuk|sabahattin ali|nazım hikmet|yaşar kemal|halide edib|reşat nuri|peyami safa)/ui'
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $query, $matches)) {
            $author = trim($matches[1]);
            
            // Bilinen yazarları kontrol et
            $knownAuthors = getKnownAuthors();
            $bestMatch = findBestAuthorMatch($author, $knownAuthors);
            
            if ($bestMatch['similarity'] > 0.7) {
                $remainingQuery = preg_replace($pattern, '', $query);
                return [
                    'found' => true,
                    'author' => $bestMatch['author'],
                    'remaining_query' => trim($remainingQuery),
                    'confidence' => $bestMatch['similarity']
                ];
            }
        }
    }
    
    return ['found' => false, 'author' => null, 'remaining_query' => $query];
}

/**
 * Kategori filtresini çıkar
 */
function extractCategoryFilter($query) {
    $categoryMap = [
        'bilim kurgu' => ['science fiction', 'sci-fi', 'bilimkurgu', 'scifi'],
        'fantastik' => ['fantasy', 'fantezi', 'büyü', 'sihir'],
        'polisiye' => ['mystery', 'detective', 'cinayet', 'gizem', 'dedektif'],
        'romantik' => ['romance', 'aşk', 'sevgili', 'romans'],
        'tarih' => ['history', 'historical', 'tarihi', 'geçmiş'],
        'felsefe' => ['philosophy', 'düşünce', 'felsefi'],
        'çocuk' => ['children', 'kids', 'child', 'çocuk', 'masallar'],
        'korku' => ['horror', 'thriller', 'gerilim', 'vampir'],
        'biyografi' => ['biography', 'memoir', 'otobiyografi', 'yaşam'],
        'şiir' => ['poetry', 'poem', 'şair', 'manzum'],
        'din' => ['religion', 'religious', 'dini', 'spiritual'],
        'eğitim' => ['education', 'academic', 'öğretim', 'ders'],
        'iş' => ['business', 'entrepreneurship', 'girişim', 'yönetim'],
        'sağlık' => ['health', 'medical', 'tıp', 'fitness'],
        'yemek' => ['cooking', 'recipe', 'mutfak', 'tarif']
    ];
    
    foreach ($categoryMap as $mainCategory => $keywords) {
        foreach ($keywords as $keyword) {
            if (stripos($query, $keyword) !== false) {
                $remainingQuery = preg_replace('/\b' . preg_quote($keyword, '/') . '\b/ui', '', $query);
                return [
                    'found' => true,
                    'category' => ucfirst($mainCategory),
                    'remaining_query' => trim($remainingQuery),
                    'confidence' => 0.9
                ];
            }
        }
    }
    
    return ['found' => false, 'category' => null, 'remaining_query' => $query];
}

/**
 * Dil filtresini çıkar
 */
function extractLanguageFilter($query) {
    $languageMap = [
        'tr' => ['türkçe', 'turkish', 'turkce'],
        'en' => ['ingilizce', 'english', 'ing'],
        'fr' => ['fransızca', 'french', 'fra'],
        'de' => ['almanca', 'german', 'deutsch'],
        'es' => ['ispanyolca', 'spanish', 'esp'],
        'it' => ['italyanca', 'italian', 'ita'],
        'ar' => ['arapça', 'arabic', 'arab']
    ];
    
    $patterns = [
        '/(?:sadece|yalnızca|csak|only)\s+([a-zA-ZçğıöşüÇĞIÖŞÜ]+)(?:\s+(?:olan|dil|language))?/ui',
        '/([a-zA-ZçğıöşüÇĞIÖŞÜ]+)\s+(?:dilinde|dili)/ui'
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $query, $matches)) {
            $langText = mb_strtolower(trim($matches[1]), 'UTF-8');
            
            foreach ($languageMap as $code => $keywords) {
                if (in_array($langText, $keywords)) {
                    $remainingQuery = preg_replace($pattern, '', $query);
                    return [
                        'found' => true,
                        'language' => $code,
                        'remaining_query' => trim($remainingQuery),
                        'confidence' => 0.95
                    ];
                }
            }
        }
    }
    
    return ['found' => false, 'language' => null, 'remaining_query' => $query];
}

/**
 * Hedef kitle filtresini çıkar
 */
function extractAudienceFilter($query) {
    $audienceMap = [
        'children' => ['çocuk', 'çocuklar', 'kids', 'child', 'bebek', 'okul öncesi'],
        'young_adult' => ['genç', 'ergen', 'teen', 'teenager', 'lise'],
        'adult' => ['yetişkin', 'adult', 'büyük', 'mature']
    ];
    
    $patterns = [
        '/(?:için|uygun)\s+([a-zA-ZçğıöşüÇĞIÖŞÜ\s]+?)(?:\s+(?:olan|olanlar|uygun))?/ui',
        '/([a-zA-ZçğıöşüÇĞIÖŞÜ]+)\s+(?:için|kitapları)/ui'
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $query, $matches)) {
            $audienceText = mb_strtolower(trim($matches[1]), 'UTF-8');
            
            foreach ($audienceMap as $audienceCode => $keywords) {
                foreach ($keywords as $keyword) {
                    if (stripos($audienceText, $keyword) !== false) {
                        $remainingQuery = preg_replace($pattern, '', $query);
                        return [
                            'found' => true,
                            'audience' => $audienceCode,
                            'remaining_query' => trim($remainingQuery),
                            'confidence' => 0.8
                        ];
                    }
                }
            }
        }
    }
    
    return ['found' => false, 'audience' => null, 'remaining_query' => $query];
}

/**
 * Yıl filtresini çıkar
 */
function extractYearFilter($query) {
    $patterns = [
        '/(\d{4})\s*(?:yılında|yılı|published|basımı)/ui',
        '/(?:after|sonra|den sonra)\s*(\d{4})/ui',
        '/(?:before|önce|den önce)\s*(\d{4})/ui'
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $query, $matches)) {
            $year = intval($matches[1]);
            
            if ($year >= 1800 && $year <= date('Y')) {
                $remainingQuery = preg_replace($pattern, '', $query);
                return [
                    'found' => true,
                    'year' => $year,
                    'remaining_query' => trim($remainingQuery),
                    'confidence' => 0.9
                ];
            }
        }
    }
    
    return ['found' => false, 'year' => null, 'remaining_query' => $query];
}

/**
 * Sayfa sayısı filtresini çıkar
 */
function extractPageCountFilter($query) {
    $patterns = [
        '/(?:kısa|short|az|küçük)\s+(?:kitap|book)/ui' => ['max' => 150],
        '/(?:orta|medium|normal)\s+(?:kitap|book)/ui' => ['min' => 150, 'max' => 300],
        '/(?:uzun|long|kalın|büyük)\s+(?:kitap|book)/ui' => ['min' => 300],
        '/(\d+)\s*(?:sayfa|page)/ui' => ['exact' => true]
    ];
    
    foreach ($patterns as $pattern => $range) {
        if (preg_match($pattern, $query, $matches)) {
            $remainingQuery = preg_replace($pattern, '', $query);
            
            if (isset($range['exact'])) {
                $pageCount = ['exact' => intval($matches[1])];
            } else {
                $pageCount = $range;
            }
            
            return [
                'found' => true,
                'page_count' => $pageCount,
                'remaining_query' => trim($remainingQuery),
                'confidence' => 0.7
            ];
        }
    }
    
    return ['found' => false, 'page_count' => null, 'remaining_query' => $query];
}

/**
 * Puan filtresini çıkar
 */
function extractRatingFilter($query) {
    $patterns = [
        '/(?:yüksek|iyi|kaliteli)\s+(?:puan|rating|değerlendirme)/ui' => ['min' => 4],
        '/(?:en iyi|best|top|mükemmel)/ui' => ['min' => 4.5],
        '/(\d+(?:\.\d+)?)\s*(?:puan|star|yıldız)/ui' => ['exact' => true]
    ];
    
    foreach ($patterns as $pattern => $range) {
        if (preg_match($pattern, $query, $matches)) {
            $remainingQuery = preg_replace($pattern, '', $query);
            
            if (isset($range['exact'])) {
                $rating = ['min' => floatval($matches[1])];
            } else {
                $rating = $range;
            }
            
            return [
                'found' => true,
                'rating' => $rating,
                'remaining_query' => trim($remainingQuery),
                'confidence' => 0.8
            ];
        }
    }
    
    return ['found' => false, 'rating' => null, 'remaining_query' => $query];
}

/**
 * Kullanıcı tercihlerine göre güven puanını ayarla
 */
function adjustConfidenceByUserPreferences($filters, $userId, $currentConfidence) {
    try {
        // Kullanıcının geçmiş tercihleriyle uyumlu mu kontrol et
        $userProfile = getUserPreferenceProfile($userId);
        
        if (empty($userProfile)) {
            return $currentConfidence;
        }
        
        $bonus = 0;
        
        // Kategori tercihi bonus
        if (isset($filters['category']) && !empty($userProfile['preferred_categories'])) {
            foreach ($userProfile['preferred_categories'] as $prefCat) {
                if (stripos($prefCat['category'], $filters['category']) !== false) {
                    $bonus += 0.1 * $prefCat['weight'];
                    break;
                }
            }
        }
        
        // Yazar tercihi bonus
        if (isset($filters['author']) && !empty($userProfile['preferred_authors'])) {
            foreach ($userProfile['preferred_authors'] as $prefAuthor) {
                if (stripos($prefAuthor['author'], $filters['author']) !== false) {
                    $bonus += 0.15 * $prefAuthor['weight'];
                    break;
                }
            }
        }
        
        // Dil tercihi bonus
        if (isset($filters['language']) && !empty($userProfile['preferred_languages'])) {
            foreach ($userProfile['preferred_languages'] as $prefLang) {
                if ($prefLang['language'] === $filters['language']) {
                    $bonus += 0.05 * $prefLang['weight'];
                    break;
                }
            }
        }
        
        return min(1.0, $currentConfidence + $bonus);
        
    } catch (Exception $e) {
        logError("Kullanıcı tercihi ayarlaması hatası", [
            'user_id' => $userId,
            'error' => $e->getMessage()
        ]);
        return $currentConfidence;
    }
}

/**
 * Fallback arama (düşük güven puanı durumunda)
 */
function performFallbackSearch($query) {
    return [
        'success' => true,
        'original_query' => $query,
        'processed_query' => $query,
        'filters' => ['keyword' => $query],
        'confidence_score' => 0.3,
        'matched_patterns' => ['fallback'],
        'suggestion_type' => 'fallback_search'
    ];
}

/**
 * Bilinen yazarları al
 */
function getKnownAuthors() {
    return [
        'George Orwell', 'Agatha Christie', 'Haruki Murakami', 'Orhan Pamuk',
        'Sabahattin Ali', 'Nazım Hikmet', 'Yaşar Kemal', 'Halide Edib',
        'Reşat Nuri Güntekin', 'Peyami Safa', 'Ahmet Hamdi Tanpınar',
        'Stephen King', 'J.K. Rowling', 'Dan Brown', 'Paulo Coelho',
        'Gabriel García Márquez', 'Ernest Hemingway', 'Franz Kafka'
    ];
}

/**
 * En iyi yazar eşleşmesini bul
 */
function findBestAuthorMatch($inputAuthor, $knownAuthors) {
    $bestMatch = ['author' => $inputAuthor, 'similarity' => 0];
    
    foreach ($knownAuthors as $knownAuthor) {
        $similarity = calculateStringSimilarity($inputAuthor, $knownAuthor);
        if ($similarity > $bestMatch['similarity']) {
            $bestMatch = ['author' => $knownAuthor, 'similarity' => $similarity];
        }
    }
    
    return $bestMatch;
}

/**
 * String benzerliği hesapla (Levenshtein + Jaro-Winkler benzeri)
 */
function calculateStringSimilarity($str1, $str2) {
    $str1 = mb_strtolower($str1, 'UTF-8');
    $str2 = mb_strtolower($str2, 'UTF-8');
    
    if ($str1 === $str2) return 1.0;
    
    $len1 = mb_strlen($str1, 'UTF-8');
    $len2 = mb_strlen($str2, 'UTF-8');
    
    if ($len1 === 0 || $len2 === 0) return 0.0;
    
    // Levenshtein distance
    $distance = levenshtein($str1, $str2);
    $maxLen = max($len1, $len2);
    $levenshteinSimilarity = 1 - ($distance / $maxLen);
    
    // Substring bonus
    $substringBonus = 0;
    if (strpos($str1, $str2) !== false || strpos($str2, $str1) !== false) {
        $substringBonus = 0.2;
    }
    
    return min(1.0, $levenshteinSimilarity + $substringBonus);
}

/**
 * NLP cache sonucunu al
 */
function getNLPCacheResult($query) {
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("
            SELECT * FROM nlp_query_cache 
            WHERE original_query = ? AND expires_at > NOW()
        ");
        $stmt->execute([$query]);
        $result = $stmt->fetch();
        
        if ($result) {
            return [
                'success' => true,
                'original_query' => $result['original_query'],
                'processed_query' => '',
                'filters' => json_decode($result['processed_filters'], true),
                'confidence_score' => floatval($result['confidence_score']),
                'matched_patterns' => ['cached'],
                'suggestion_type' => 'cached_nlp'
            ];
        }
        
        return null;
        
    } catch (Exception $e) {
        logError("NLP cache alma hatası", ['query' => $query, 'error' => $e->getMessage()]);
        return null;
    }
}

/**
 * NLP sonucunu cache'e kaydet
 */
function cacheNLPResult($query, $result, $processingTime) {
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("
            INSERT INTO nlp_query_cache 
            (original_query, processed_filters, confidence_score, processing_time_ms, expires_at) 
            VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))
            ON DUPLICATE KEY UPDATE
            processed_filters = VALUES(processed_filters),
            confidence_score = VALUES(confidence_score),
            processing_time_ms = VALUES(processing_time_ms),
            expires_at = VALUES(expires_at)
        ");
        
        $stmt->execute([
            $query,
            json_encode($result['filters']),
            $result['confidence_score'],
            round($processingTime * 1000, 2)
        ]);
        
    } catch (Exception $e) {
        logError("NLP cache kaydetme hatası", ['query' => $query, 'error' => $e->getMessage()]);
    }
}

/**
 * NLP arama aktivitesini kaydet
 */
function trackNLPSearchActivity($userId, $query, $result) {
    try {
        // Bu fonksiyon user_activity_tracker.php'den kullanılacak
        $activityData = [
            'nlp_confidence' => $result['confidence_score'],
            'matched_patterns' => $result['matched_patterns'],
            'filters_extracted' => array_keys(array_filter($result['filters']))
        ];
        
        trackUserActivity($userId, 'search', [], [
            'search_query' => $query,
            'search_type' => 'nlp',
            'nlp_data' => $activityData
        ]);
        
    } catch (Exception $e) {
        logError("NLP aktivite kaydetme hatası", [
            'user_id' => $userId,
            'query' => $query,
            'error' => $e->getMessage()
        ]);
    }
}

?> 