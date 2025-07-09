<?php
require_once 'config.php';
require_once 'ai_recommendation_helpers.php';

/**
 * Kullanıcı Aktivite İzleme Sistemi
 * Tüm kullanıcı etkileşimlerini kaydeder ve AI öneriler için veri sağlar
 */

startSecureSession();

// JavaScript kodu döndür (AJAX istekleri için)
if (isset($_GET['js']) && $_GET['js'] === 'tracker') {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: public, max-age=3600'); // 1 saatlik cache
    echo generateActivityTrackingJS();
    exit;
}

// Rate limiting
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!checkRateLimit("activity_tracker:$clientIp", 100, 60)) {
    jsonResponse(['error' => 'Çok fazla aktivite kaydı. Lütfen bir dakika sonra tekrar deneyin.'], 429);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handleActivityTracking();
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    handleActivityRetrieval();
} else {
    jsonResponse(['error' => 'Desteklenmeyen HTTP metodu'], 405);
}

/**
 * Aktivite kaydetme istekleri
 */
function handleActivityTracking() {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        jsonResponse(['error' => 'Geçersiz JSON verisi'], 400);
    }
    
    // Batch activities kontrolü
    if (isset($input['batch_activities']) && is_array($input['batch_activities'])) {
        handleBatchActivities($input['batch_activities']);
        return;
    }
    
    // Tek aktivite işleme
    $userId = intval($input['user_id'] ?? 0);
    $activityType = sanitizeInput($input['activity_type'] ?? '');
    $bookData = $input['book_data'] ?? [];
    $extraData = $input['extra_data'] ?? [];
    
    // Validasyon
    if ($userId <= 0) {
        jsonResponse(['error' => 'Geçerli bir kullanıcı ID gerekli'], 400);
    }
    
    $validActivityTypes = [
        'view', 'search', 'favorite', 'unfavorite', 
        'read_start', 'read_progress', 'read_complete', 'rate'
    ];
    
    if (!in_array($activityType, $validActivityTypes)) {
        jsonResponse(['error' => 'Geçersiz aktivite tipi'], 400);
    }
    
    try {
        // Aktiviteyi kaydet
        trackUserActivity($userId, $activityType, $bookData, $extraData);
        
        // Real-time öneri güncelleme (opsiyonel)
        if (in_array($activityType, ['favorite', 'rate', 'read_complete'])) {
            clearUserRecommendationCache($userId);
        }
        
        jsonResponse([
            'success' => true,
            'message' => 'Aktivite başarıyla kaydedildi',
            'activity_type' => $activityType,
            'user_id' => $userId
        ]);
        
    } catch (Exception $e) {
        logError("Aktivite kaydetme hatası", [
            'user_id' => $userId,
            'activity_type' => $activityType,
            'error' => $e->getMessage()
        ]);
        
        jsonResponse(['error' => 'Aktivite kaydedilirken bir hata oluştu'], 500);
    }
}

/**
 * Batch aktivite kaydetme
 */
function handleBatchActivities($activities) {
    if (empty($activities) || !is_array($activities)) {
        jsonResponse(['error' => 'Geçersiz batch aktivite verisi'], 400);
    }
    
    $validActivityTypes = [
        'view', 'search', 'favorite', 'unfavorite', 
        'read_start', 'read_progress', 'read_complete', 'rate'
    ];
    
    $processedCount = 0;
    $errorCount = 0;
    $cacheClears = [];
    
    try {
        foreach ($activities as $activity) {
            $userId = intval($activity['user_id'] ?? 0);
            $activityType = sanitizeInput($activity['activity_type'] ?? '');
            $bookData = $activity['book_data'] ?? [];
            $extraData = $activity['extra_data'] ?? [];
            
            // Validasyon
            if ($userId <= 0 || !in_array($activityType, $validActivityTypes)) {
                $errorCount++;
                continue;
            }
            
            // Aktiviteyi kaydet
            trackUserActivity($userId, $activityType, $bookData, $extraData);
            $processedCount++;
            
            // Cache temizleme ihtiyacı
            if (in_array($activityType, ['favorite', 'rate', 'read_complete'])) {
                $cacheClears[] = $userId;
            }
        }
        
        // Unique user ID'ler için cache temizle
        $uniqueUserIds = array_unique($cacheClears);
        foreach ($uniqueUserIds as $userId) {
            clearUserRecommendationCache($userId);
        }
        
        jsonResponse([
            'success' => true,
            'message' => 'Batch aktiviteler işlendi',
            'processed_count' => $processedCount,
            'error_count' => $errorCount,
            'cache_clears' => count($uniqueUserIds)
        ]);
        
    } catch (Exception $e) {
        logError("Batch aktivite kaydetme hatası", [
            'processed_count' => $processedCount,
            'error_count' => $errorCount,
            'error' => $e->getMessage()
        ]);
        
        jsonResponse(['error' => 'Batch aktiviteler kaydedilirken bir hata oluştu'], 500);
    }
}

/**
 * Aktivite geçmişi sorgulama
 */
function handleActivityRetrieval() {
    $userId = intval($_GET['user_id'] ?? 0);
    $activityType = sanitizeInput($_GET['activity_type'] ?? '');
    $limit = min(100, max(1, intval($_GET['limit'] ?? 50)));
    $startDate = sanitizeInput($_GET['start_date'] ?? '');
    $endDate = sanitizeInput($_GET['end_date'] ?? '');
    
    if ($userId <= 0) {
        jsonResponse(['error' => 'Geçerli bir kullanıcı ID gerekli'], 400);
    }
    
    try {
        $activities = getUserActivities($userId, $activityType, $limit, $startDate, $endDate);
        $analytics = getUserActivityAnalytics($userId, $startDate, $endDate);
        
        jsonResponse([
            'success' => true,
            'activities' => $activities,
            'analytics' => $analytics,
            'user_id' => $userId
        ]);
        
    } catch (Exception $e) {
        logError("Aktivite sorgulama hatası", [
            'user_id' => $userId,
            'error' => $e->getMessage()
        ]);
        
        jsonResponse(['error' => 'Aktivite geçmişi alınırken bir hata oluştu'], 500);
    }
}

/**
 * Kullanıcı aktivitelerini getir
 */
function getUserActivities($userId, $activityType = '', $limit = 50, $startDate = '', $endDate = '') {
    try {
        $pdo = getDbConnection();
        
        $whereConditions = ['user_id = ?'];
        $params = [$userId];
        
        if (!empty($activityType)) {
            $whereConditions[] = 'activity_type = ?';
            $params[] = $activityType;
        }
        
        if (!empty($startDate)) {
            $whereConditions[] = 'created_at >= ?';
            $params[] = $startDate . ' 00:00:00';
        }
        
        if (!empty($endDate)) {
            $whereConditions[] = 'created_at <= ?';
            $params[] = $endDate . ' 23:59:59';
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        $stmt = $pdo->prepare("
            SELECT ua.*, ub.book_thumbnail
            FROM user_activities ua
            LEFT JOIN user_books ub ON ua.book_google_id = ub.book_google_id AND ub.user_id = ua.user_id
            WHERE $whereClause
            ORDER BY ua.created_at DESC
            LIMIT ?
        ");
        
        $params[] = $limit;
        $stmt->execute($params);
        $results = $stmt->fetchAll();
        
        $activities = [];
        foreach ($results as $activity) {
            $activities[] = [
                'id' => $activity['id'],
                'activity_type' => $activity['activity_type'],
                'book_google_id' => $activity['book_google_id'],
                'book_title' => $activity['book_title'],
                'book_authors' => $activity['book_authors'],
                'book_categories' => $activity['book_categories'],
                'book_thumbnail' => $activity['book_thumbnail'],
                'search_query' => $activity['search_query'],
                'activity_data' => json_decode($activity['activity_data'], true),
                'activity_score' => floatval($activity['activity_score']),
                'created_at' => $activity['created_at']
            ];
        }
        
        return $activities;
        
    } catch (Exception $e) {
        logError("Kullanıcı aktivitelerini getirme hatası", [
            'user_id' => $userId,
            'error' => $e->getMessage()
        ]);
        return [];
    }
}

/**
 * Kullanıcı aktivite analitiği
 */
function getUserActivityAnalytics($userId, $startDate = '', $endDate = '') {
    try {
        $pdo = getDbConnection();
        
        $whereConditions = ['user_id = ?'];
        $params = [$userId];
        
        if (!empty($startDate)) {
            $whereConditions[] = 'created_at >= ?';
            $params[] = $startDate . ' 00:00:00';
        }
        
        if (!empty($endDate)) {
            $whereConditions[] = 'created_at <= ?';
            $params[] = $endDate . ' 23:59:59';
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        // Aktivite tipine göre sayılar
        $stmt = $pdo->prepare("
            SELECT activity_type, COUNT(*) as count, SUM(activity_score) as total_score
            FROM user_activities 
            WHERE $whereClause
            GROUP BY activity_type
            ORDER BY count DESC
        ");
        $stmt->execute($params);
        $activityTypes = $stmt->fetchAll();
        
        // En çok etkileşim kurulan kategoriler
        $stmt = $pdo->prepare("
            SELECT book_categories, COUNT(*) as count
            FROM user_activities 
            WHERE $whereClause AND book_categories IS NOT NULL
            GROUP BY book_categories
            ORDER BY count DESC
            LIMIT 10
        ");
        $stmt->execute($params);
        $topCategories = $stmt->fetchAll();
        
        // En çok etkileşim kurulan yazarlar
        $stmt = $pdo->prepare("
            SELECT book_authors, COUNT(*) as count
            FROM user_activities 
            WHERE $whereClause AND book_authors IS NOT NULL
            GROUP BY book_authors
            ORDER BY count DESC
            LIMIT 10
        ");
        $stmt->execute($params);
        $topAuthors = $stmt->fetchAll();
        
        // Günlük aktivite trendi (son 30 gün)
        $stmt = $pdo->prepare("
            SELECT DATE(created_at) as date, COUNT(*) as count
            FROM user_activities 
            WHERE $whereClause AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ");
        $stmt->execute($params);
        $dailyTrend = $stmt->fetchAll();
        
        // En çok aranan kelimeler
        $stmt = $pdo->prepare("
            SELECT search_query, COUNT(*) as count
            FROM user_activities 
            WHERE $whereClause AND activity_type = 'search' AND search_query IS NOT NULL
            GROUP BY search_query
            ORDER BY count DESC
            LIMIT 10
        ");
        $stmt->execute($params);
        $topSearches = $stmt->fetchAll();
        
        return [
            'activity_types' => $activityTypes,
            'top_categories' => $topCategories,
            'top_authors' => $topAuthors,
            'daily_trend' => $dailyTrend,
            'top_searches' => $topSearches,
            'total_activities' => array_sum(array_column($activityTypes, 'count')),
            'engagement_score' => array_sum(array_column($activityTypes, 'total_score'))
        ];
        
    } catch (Exception $e) {
        logError("Aktivite analizi hatası", [
            'user_id' => $userId,
            'error' => $e->getMessage()
        ]);
        return [];
    }
}



/**
 * Toplu aktivite kaydetme (batch processing)
 */
function batchTrackActivities($activities) {
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("
            INSERT INTO user_activities 
            (user_id, activity_type, book_google_id, book_title, book_authors, 
             book_categories, search_query, activity_data, activity_score) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $pdo->beginTransaction();
        
        foreach ($activities as $activity) {
            $activityScore = getActivityScore($activity['activity_type']);
            $bookData = $activity['book_data'] ?? [];
            $extraData = $activity['extra_data'] ?? [];
            
            $stmt->execute([
                $activity['user_id'],
                $activity['activity_type'],
                $bookData['google_id'] ?? null,
                $bookData['title'] ?? null,
                $bookData['authors'] ?? null,
                $bookData['categories'] ?? null,
                $extraData['search_query'] ?? null,
                json_encode($extraData),
                $activityScore
            ]);
        }
        
        $pdo->commit();
        
        logInfo("Toplu aktivite kaydı tamamlandı", ['count' => count($activities)]);
        return true;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        logError("Toplu aktivite kaydetme hatası", ['error' => $e->getMessage()]);
        return false;
    }
}

/**
 * Eski aktiviteleri temizle (veri tabanı bakımı)
 */
function cleanOldActivities($daysToKeep = 365) {
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("
            DELETE FROM user_activities 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$daysToKeep]);
        
        $deletedCount = $stmt->rowCount();
        
        logInfo("Eski aktiviteler temizlendi", [
            'deleted_count' => $deletedCount,
            'days_kept' => $daysToKeep
        ]);
        
        return $deletedCount;
        
    } catch (Exception $e) {
        logError("Eski aktivite temizleme hatası", ['error' => $e->getMessage()]);
        return 0;
    }
}

/**
 * Kullanıcı engagment puanı hesapla
 */
function calculateUserEngagementScore($userId, $days = 30) {
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("
            SELECT SUM(activity_score) as total_score,
                   COUNT(*) as total_activities,
                   COUNT(DISTINCT DATE(created_at)) as active_days
            FROM user_activities 
            WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$userId, $days]);
        $result = $stmt->fetch();
        
        $totalScore = floatval($result['total_score'] ?? 0);
        $totalActivities = intval($result['total_activities'] ?? 0);
        $activeDays = intval($result['active_days'] ?? 0);
        
        // Engagement puanı = (toplam skor * aktivite sıklığı * düzenlilik)
        $frequencyBonus = min(2.0, $totalActivities / 10); // Aktivite sıklığı bonusu
        $consistencyBonus = min(1.5, $activeDays / $days); // Düzenlilik bonusu
        
        $engagementScore = $totalScore * $frequencyBonus * $consistencyBonus;
        
        return [
            'engagement_score' => round($engagementScore, 2),
            'total_score' => $totalScore,
            'total_activities' => $totalActivities,
            'active_days' => $activeDays,
            'avg_daily_activities' => $activeDays > 0 ? round($totalActivities / $activeDays, 1) : 0
        ];
        
    } catch (Exception $e) {
        logError("Engagement puanı hesaplama hatası", [
            'user_id' => $userId,
            'error' => $e->getMessage()
        ]);
        return [];
    }
}

/**
 * JavaScript için aktivite izleme kodu oluştur
 */
function generateActivityTrackingJS() {
    return "
    // OMCN E-Kitap Aktivite İzleme
    // Duplicate loading önleme
    if (typeof window.ActivityTracker === 'undefined') {
        console.log('✅ ActivityTracker yükleniyor...');
        class ActivityTracker {
            constructor(userId) {
                this.userId = userId;
                this.apiUrl = 'user_activity_tracker.php';
                this.batchQueue = [];
                this.batchSize = 10;
                this.flushInterval = 30000; // 30 saniye
                
                this.startBatchProcessor();
            }
        
        track(activityType, bookData = {}, extraData = {}) {
            const activity = {
                user_id: this.userId,
                activity_type: activityType,
                book_data: bookData,
                extra_data: {
                    ...extraData,
                    timestamp: new Date().toISOString(),
                    url: window.location.href,
                    user_agent: navigator.userAgent
                }
            };
            
            this.batchQueue.push(activity);
            
            if (this.batchQueue.length >= this.batchSize) {
                this.flush();
            }
        }
        
        async flush() {
            if (this.batchQueue.length === 0) return;
            
            const activities = [...this.batchQueue];
            this.batchQueue = [];
            
            try {
                const response = await fetch(this.apiUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        batch_activities: activities
                    })
                });
                
                if (!response.ok) {
                    console.warn('Aktivite gönderimi başarısız:', response.status);
                }
            } catch (error) {
                console.error('Aktivite izleme hatası:', error);
                // Hata durumunda aktiviteleri geri ekle
                this.batchQueue.unshift(...activities);
            }
        }
        
        startBatchProcessor() {
            setInterval(() => {
                this.flush();
            }, this.flushInterval);
            
            // Sayfa kapanırken kalan aktiviteleri gönder
            window.addEventListener('beforeunload', () => {
                this.flush();
            });
        }
        
        // Kolay kullanım için yardımcı metodlar
        trackView(bookData) {
            this.track('view', bookData);
        }
        
        trackSearch(query) {
            this.track('search', {}, { search_query: query });
        }
        
        trackFavorite(bookData) {
            this.track('favorite', bookData);
        }
        
        trackUnfavorite(bookData) {
            this.track('unfavorite', bookData);
        }
        
        trackReadStart(bookData) {
            this.track('read_start', bookData);
        }
        
        trackReadProgress(bookData, page) {
            this.track('read_progress', bookData, { page: page });
        }
        
        trackReadComplete(bookData) {
            this.track('read_complete', bookData);
        }
        
        trackRate(bookData, rating) {
            this.track('rate', bookData, { rating: rating });
        }
    }
    
    // Global kullanım için
    window.ActivityTracker = ActivityTracker;
    
    } else {
        console.warn('⚠️ ActivityTracker zaten yüklenmiş, duplicate loading atlanıyor');
    }
    ";
}

?> 