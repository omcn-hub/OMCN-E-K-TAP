<?php
require_once 'config.php';

// Session başlat
startSecureSession();

// Rate limiting kontrolü
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!checkRateLimit("library:$clientIp", 100, 60)) {
    jsonResponse(['error' => 'Çok fazla istek. Lütfen bir dakika sonra tekrar deneyin.'], 429);
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

// Oturum kontrolü
function requireAuth() {
    if (!isset($_SESSION['user_id'])) {
        jsonResponse(['error' => 'Giriş yapmış kullanıcı gerekli'], 401);
    }
}

// İstek yöntemini al
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Güvenlik headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

switch ($action) {
    case 'save':
        if ($method === 'POST') {
            saveBook();
        }
        break;
    
    case 'list':
        if ($method === 'GET') {
            getUserLibrary();
        }
        break;
    
    case 'remove':
        if ($method === 'POST' || $method === 'DELETE') {
            removeBook();
        }
        break;
    
    case 'check':
        if ($method === 'POST') {
            checkBookSaved();
        }
        break;
    
    case 'count':
        if ($method === 'GET') {
            getBookCount();
        }
        break;
        
    case 'stats':
        if ($method === 'GET') {
            getUserStats();
        }
        break;
        
    case 'update-status':
        if ($method === 'POST') {
            updateReadingStatus();
        }
        break;
        
    case 'rate':
        if ($method === 'POST') {
            rateBook();
        }
        break;
        
    case 'add-note':
        if ($method === 'POST') {
            addBookNote();
        }
        break;
        
    case 'toggle-favorite':
        if ($method === 'POST') {
            toggleFavorite();
        }
        break;
        
    case 'categories':
        if ($method === 'GET') {
            getBookCategories();
        }
        break;
    
    default:
        jsonResponse(['error' => 'Geçersiz işlem'], 400);
}

function saveBook() {
    requireAuth();
    $pdo = getDbConnection();
    
    $userId = $_SESSION['user_id'];
    
    // POST verilerini al
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        jsonResponse(['error' => 'Geçersiz JSON veri'], 400);
    }
    
    // CSRF token kontrolü - esnek
    $csrfToken = $input['csrf_token'] ?? '';
    if (!empty($csrfToken) && !validateCSRFToken($csrfToken)) {
        // Token varsa ama geçersizse, yeni token oluştur ve kabul et
        $newToken = generateCSRFToken();
        logInfo("CSRF token yenilendi", ['user_id' => $userId, 'old_token' => substr($csrfToken, 0, 10)]);
    }
    
    // Giriş verilerini sanitize et
    $bookTitle = sanitizeInput($input['title'] ?? '');
    $bookAuthors = $input['authors'] ?? [];
    $bookDescription = sanitizeInput($input['description'] ?? '');
    $bookThumbnail = sanitizeInput($input['thumbnail'] ?? '', 'url');
    $bookInfoLink = sanitizeInput($input['infoLink'] ?? '', 'url');
    $bookPdfLink = sanitizeInput($input['pdf'] ?? '', 'url');
    $bookGoogleId = sanitizeInput($input['google_id'] ?? '');
    $bookIsbn = sanitizeInput($input['isbn'] ?? '');
    $bookPageCount = sanitizeInput($input['pageCount'] ?? 0, 'int');
    $bookPublishedDate = sanitizeInput($input['publishedDate'] ?? '');
    $bookCategories = is_array($input['categories']) ? $input['categories'] : [];
    $bookLanguage = sanitizeInput($input['language'] ?? 'tr');
    
    // Validasyon
    if (empty($bookTitle)) {
        jsonResponse(['error' => 'Kitap başlığı gerekli'], 400);
    }
    
    if (strlen($bookTitle) > 255) {
        jsonResponse(['error' => 'Kitap başlığı çok uzun'], 400);
    }
    
    // Authors array'ini string'e çevir
    $authorsString = is_array($bookAuthors) ? implode(', ', array_map('sanitizeInput', $bookAuthors)) : sanitizeInput($bookAuthors);
    $categoriesString = is_array($bookCategories) ? implode(', ', array_map('sanitizeInput', $bookCategories)) : sanitizeInput($bookCategories);
    
    // Yayın tarihini doğru formata çevir
    $publishedDate = null;
    if (!empty($bookPublishedDate)) {
        $publishedDate = date('Y-m-d', strtotime($bookPublishedDate));
        if ($publishedDate === '1970-01-01') {
            $publishedDate = null;
        }
    }
    
    try {
        // Kitap zaten kaydedilmiş mi kontrol et
        $stmt = $pdo->prepare("
            SELECT id FROM user_books 
            WHERE user_id = ? AND (
                book_google_id = ? OR 
                (book_title = ? AND book_authors = ?)
            )
        ");
        $stmt->execute([$userId, $bookGoogleId, $bookTitle, $authorsString]);
        
        if ($stmt->fetch()) {
            jsonResponse(['error' => 'Bu kitap zaten kütüphanenizde'], 409);
        }
        
        // Kitabı kaydet
        $stmt = $pdo->prepare("
            INSERT INTO user_books (
                user_id, book_title, book_authors, book_description, 
                book_thumbnail, book_info_link, book_pdf_link, book_google_id,
                book_isbn, book_page_count, book_published_date, book_language,
                book_categories
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $userId, $bookTitle, $authorsString, $bookDescription,
            $bookThumbnail, $bookInfoLink, $bookPdfLink, $bookGoogleId,
            $bookIsbn, $bookPageCount > 0 ? $bookPageCount : null, $publishedDate, $bookLanguage,
            $categoriesString
        ]);
        
        if ($result) {
            $bookId = $pdo->lastInsertId();
            
            // Cache'i temizle
            deleteCache("user_library:$userId");
            deleteCache("user_stats:$userId");
            
            // Log kaydet
            logInfo("Kitap kütüphaneye eklendi", [
                'user_id' => $userId,
                'book_id' => $bookId,
                'book_title' => $bookTitle
            ]);
            
            jsonResponse([
                'success' => true,
                'message' => 'Kitap kütüphanenize eklendi',
                'book_id' => $bookId
            ]);
        } else {
            jsonResponse(['error' => 'Kitap kaydedilirken hata oluştu'], 500);
        }
        
    } catch (PDOException $e) {
        logError("Kitap kaydetme hatası", [
            'user_id' => $userId,
            'book_title' => $bookTitle,
            'error' => $e->getMessage()
        ]);
        jsonResponse(['error' => 'Kitap kaydedilirken sistem hatası oluştu'], 500);
    }
}

function getUserLibrary() {
    requireAuth();
    $pdo = getDbConnection();
    
    $userId = $_SESSION['user_id'];
    
    // Query parametreleri
    $status = $_GET['status'] ?? null;
    $category = $_GET['category'] ?? null;
    $favorite = $_GET['favorite'] ?? null;
    $search = sanitizeInput($_GET['search'] ?? '');
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(MAX_RESULTS_PER_PAGE, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $sortBy = $_GET['sort'] ?? 'updated_at';
    $sortOrder = ($_GET['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
    
    // Cache anahtarı oluştur
    $cacheKey = "user_library:$userId:" . md5(serialize([
        $status, $category, $favorite, $search, $page, $limit, $sortBy, $sortOrder
    ]));
    
    // Cache kontrolü
    $cachedData = getCache($cacheKey);
    if ($cachedData) {
        jsonResponse($cachedData);
    }
    
    try {
        // WHERE koşulları oluştur
        $whereConditions = ['ub.user_id = ?'];
        $params = [$userId];
        
        if ($status && in_array($status, ['to_read', 'reading', 'completed'])) {
            $whereConditions[] = 'ub.reading_status = ?';
            $params[] = $status;
        }
        
        if ($favorite === '1') {
            $whereConditions[] = 'ub.is_favorite = 1';
        }
        
        if (!empty($search)) {
            $whereConditions[] = '(ub.book_title LIKE ? OR ub.book_authors LIKE ? OR ub.book_description LIKE ?)';
            $searchTerm = "%$search%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        if (!empty($category)) {
            $whereConditions[] = 'ub.book_categories LIKE ?';
            $params[] = "%$category%";
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        // Sıralama validasyonu
        $allowedSortFields = ['updated_at', 'saved_at', 'book_title', 'book_authors', 'rating'];
        if (!in_array($sortBy, $allowedSortFields)) {
            $sortBy = 'updated_at';
        }
        
        // Toplam kitap sayısını al
        $countSql = "
            SELECT COUNT(*) as total
            FROM user_books ub
            WHERE $whereClause
        ";
        
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($params);
        $totalCount = $stmt->fetchColumn();
        
        // Kitapları getir
        $sql = "
            SELECT 
                ub.id,
                ub.book_title as title,
                ub.book_authors as authors,
                ub.book_description as description,
                ub.book_thumbnail as thumbnail,
                ub.book_info_link as infoLink,
                ub.book_pdf_link as pdf,
                ub.book_google_id as google_id,
                ub.book_isbn as isbn,
                ub.book_page_count as pageCount,
                ub.book_published_date as publishedDate,
                ub.book_language as language,
                ub.book_categories as categories,
                ub.reading_status,
                ub.reading_progress,
                ub.rating,
                ub.notes,
                ub.tags,
                ub.is_favorite,
                ub.saved_at,
                ub.updated_at
            FROM user_books ub
            WHERE $whereClause
            ORDER BY ub.$sortBy $sortOrder
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $books = $stmt->fetchAll();
        
        // Veri işleme
        foreach ($books as &$book) {
            $book['authors'] = !empty($book['authors']) ? explode(', ', $book['authors']) : [];
            $book['categories'] = !empty($book['categories']) ? explode(', ', $book['categories']) : [];
            $book['is_favorite'] = (bool)$book['is_favorite'];
            $book['rating'] = $book['rating'] ? intval($book['rating']) : null;
            $book['reading_progress'] = intval($book['reading_progress']);
            $book['pageCount'] = $book['pageCount'] ? intval($book['pageCount']) : null;
            
            // Tags'i array'e çevir
            $book['tags'] = !empty($book['tags']) ? explode(',', $book['tags']) : [];
        }
        
        $response = [
            'success' => true,
            'books' => $books,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total' => intval($totalCount),
                'total_pages' => ceil($totalCount / $limit),
                'has_next' => $page < ceil($totalCount / $limit),
                'has_prev' => $page > 1
            ],
            'filters' => [
                'status' => $status,
                'category' => $category,
                'favorite' => $favorite,
                'search' => $search
            ]
        ];
        
        // Cache'e kaydet
        setCache($cacheKey, $response, 600); // 10 dakika
        
        jsonResponse($response);
        
    } catch (PDOException $e) {
        logError("Kütüphane listeleme hatası", [
            'user_id' => $userId,
            'error' => $e->getMessage()
        ]);
        jsonResponse(['error' => 'Kütüphane yüklenirken hata oluştu'], 500);
    }
}

function removeBook() {
    requireAuth();
    $pdo = getDbConnection();
    
    $userId = $_SESSION['user_id'];
    
    // POST verilerini al
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        jsonResponse(['error' => 'Geçersiz JSON veri'], 400);
    }
    
    // CSRF token kontrolü
    $csrfToken = $input['csrf_token'] ?? '';
    if (!validateCSRFToken($csrfToken)) {
        jsonResponse(['error' => 'Geçersiz güvenlik token\'ı'], 403);
    }
    
    $bookId = sanitizeInput($input['book_id'] ?? '', 'int');
    
    if (empty($bookId)) {
        jsonResponse(['error' => 'Kitap ID gerekli'], 400);
    }
    
    try {
        // Kitap bilgilerini önce al (log için)
        $stmt = $pdo->prepare("SELECT book_title FROM user_books WHERE id = ? AND user_id = ?");
        $stmt->execute([$bookId, $userId]);
        $book = $stmt->fetch();
        
        if (!$book) {
            jsonResponse(['error' => 'Kitap bulunamadı'], 404);
        }
        
        // Kitabı sil
        $stmt = $pdo->prepare("DELETE FROM user_books WHERE id = ? AND user_id = ?");
        $stmt->execute([$bookId, $userId]);
        
        if ($stmt->rowCount() > 0) {
            // Cache'i temizle
            deleteCache("user_library:$userId");
            deleteCache("user_stats:$userId");
            
            // Log kaydet
            logInfo("Kitap kütüphaneden kaldırıldı", [
                'user_id' => $userId,
                'book_id' => $bookId,
                'book_title' => $book['book_title']
            ]);
            
            jsonResponse([
                'success' => true,
                'message' => 'Kitap kütüphaneden kaldırıldı'
            ]);
        } else {
            jsonResponse(['error' => 'Kitap kaldırılırken hata oluştu'], 500);
        }
        
    } catch (PDOException $e) {
        logError("Kitap kaldırma hatası", [
            'user_id' => $userId,
            'book_id' => $bookId,
            'error' => $e->getMessage()
        ]);
        jsonResponse(['error' => 'Kitap kaldırılırken sistem hatası oluştu'], 500);
    }
}

function checkBookSaved() {
    requireAuth();
    $pdo = getDbConnection();
    
    $userId = $_SESSION['user_id'];
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        jsonResponse(['error' => 'Geçersiz JSON veri'], 400);
    }
    
    $bookTitle = sanitizeInput($input['title'] ?? '');
    $bookAuthors = is_array($input['authors']) ? implode(', ', array_map('sanitizeInput', $input['authors'])) : sanitizeInput($input['authors'] ?? '');
    $bookGoogleId = sanitizeInput($input['google_id'] ?? '');
    
    try {
        $stmt = $pdo->prepare("
            SELECT id, reading_status, is_favorite, rating 
            FROM user_books 
            WHERE user_id = ? AND (
                book_google_id = ? OR 
                (book_title = ? AND book_authors = ?)
            )
        ");
        
        $stmt->execute([$userId, $bookGoogleId, $bookTitle, $bookAuthors]);
        $book = $stmt->fetch();
        
        if ($book) {
            jsonResponse([
                'saved' => true,
                'book_id' => $book['id'],
                'reading_status' => $book['reading_status'],
                'is_favorite' => (bool)$book['is_favorite'],
                'rating' => $book['rating'] ? intval($book['rating']) : null
            ]);
        } else {
            jsonResponse(['saved' => false]);
        }
        
    } catch (PDOException $e) {
        logError("Kitap kontrol hatası", [
            'user_id' => $userId,
            'error' => $e->getMessage()
        ]);
        jsonResponse(['error' => 'Kontrol sırasında hata oluştu'], 500);
    }
}

function getBookCount() {
    requireAuth();
    $pdo = getDbConnection();
    
    $userId = $_SESSION['user_id'];
    
    // Cache kontrolü
    $cacheKey = "user_book_count:$userId";
    $cachedCount = getCache($cacheKey);
    if ($cachedCount !== null) {
        jsonResponse(['success' => true, 'count' => $cachedCount]);
    }
    
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM user_books WHERE user_id = ?");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        $count = intval($result['count']);
        
        // Cache'e kaydet
        setCache($cacheKey, $count, 3600);
        
        jsonResponse([
            'success' => true,
            'count' => $count
        ]);
        
    } catch (PDOException $e) {
        logError("Kitap sayısı alma hatası", [
            'user_id' => $userId,
            'error' => $e->getMessage()
        ]);
        jsonResponse(['error' => 'Kitap sayısı alınırken hata oluştu'], 500);
    }
}

function getUserStats() {
    requireAuth();
    $pdo = getDbConnection();
    
    $userId = $_SESSION['user_id'];
    
    // Cache kontrolü
    $cacheKey = "user_stats:$userId";
    $cachedStats = getCache($cacheKey);
    if ($cachedStats) {
        jsonResponse($cachedStats);
    }
    
    try {
        // Stored procedure kullan (eğer varsa)
        $stmt = $pdo->prepare("CALL GetUserStats(?)");
        $stmt->execute([$userId]);
        $stats = $stmt->fetch();
        
        if (!$stats) {
            // Fallback - normal sorgu
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total_books,
                    SUM(CASE WHEN reading_status = 'completed' THEN 1 ELSE 0 END) as completed_books,
                    SUM(CASE WHEN reading_status = 'reading' THEN 1 ELSE 0 END) as currently_reading,
                    SUM(CASE WHEN reading_status = 'to_read' THEN 1 ELSE 0 END) as to_read_books,
                    SUM(CASE WHEN is_favorite = 1 THEN 1 ELSE 0 END) as favorite_books,
                    AVG(CASE WHEN rating > 0 THEN rating ELSE NULL END) as average_rating
                FROM user_books 
                WHERE user_id = ?
            ");
            $stmt->execute([$userId]);
            $stats = $stmt->fetch();
        }
        
        // Veri dönüştürme
        $response = [
            'success' => true,
            'total_books' => intval($stats['total_books']),
            'completed_books' => intval($stats['completed_books']),
            'currently_reading' => intval($stats['currently_reading']),
            'to_read_books' => intval($stats['to_read_books']),
            'favorite_books' => intval($stats['favorite_books']),
            'average_rating' => $stats['average_rating'] ? round(floatval($stats['average_rating']), 1) : null,
            'completion_rate' => $stats['total_books'] > 0 
                ? round(($stats['completed_books'] / $stats['total_books']) * 100, 1) 
                : 0
        ];
        
        // Cache'e kaydet
        setCache($cacheKey, $response, 1800); // 30 dakika
        
        jsonResponse($response);
        
    } catch (PDOException $e) {
        logError("Kullanıcı istatistikleri hatası", [
            'user_id' => $userId,
            'error' => $e->getMessage()
        ]);
        jsonResponse(['error' => 'İstatistikler alınırken hata oluştu'], 500);
    }
}

// Yeni özellik fonksiyonları

function updateReadingStatus() {
    requireAuth();
    $pdo = getDbConnection();
    
    $userId = $_SESSION['user_id'];
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        jsonResponse(['error' => 'Geçersiz JSON veri'], 400);
    }
    
    $bookId = sanitizeInput($input['book_id'] ?? '', 'int');
    $status = sanitizeInput($input['status'] ?? '');
    $progress = sanitizeInput($input['progress'] ?? 0, 'int');
    
    if (empty($bookId) || !in_array($status, ['to_read', 'reading', 'completed'])) {
        jsonResponse(['error' => 'Geçersiz parametreler'], 400);
    }
    
    try {
        $stmt = $pdo->prepare("
            UPDATE user_books 
            SET reading_status = ?, reading_progress = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND user_id = ?
        ");
        
        $result = $stmt->execute([$status, $progress, $bookId, $userId]);
        
        if ($result && $stmt->rowCount() > 0) {
            // Cache'leri temizle
            deleteCache("user_library:$userId");
            deleteCache("user_stats:$userId");
            
            jsonResponse([
                'success' => true,
                'message' => 'Okuma durumu güncellendi'
            ]);
        } else {
            jsonResponse(['error' => 'Kitap bulunamadı'], 404);
        }
        
    } catch (PDOException $e) {
        logError("Okuma durumu güncelleme hatası", [
            'user_id' => $userId,
            'book_id' => $bookId,
            'error' => $e->getMessage()
        ]);
        jsonResponse(['error' => 'Güncelleme sırasında hata oluştu'], 500);
    }
}

function rateBook() {
    requireAuth();
    $pdo = getDbConnection();
    
    $userId = $_SESSION['user_id'];
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        jsonResponse(['error' => 'Geçersiz JSON veri'], 400);
    }
    
    $bookId = sanitizeInput($input['book_id'] ?? '', 'int');
    $rating = sanitizeInput($input['rating'] ?? 0, 'int');
    
    if (empty($bookId) || $rating < 1 || $rating > 5) {
        jsonResponse(['error' => 'Geçersiz parametreler (Puan 1-5 arası olmalı)'], 400);
    }
    
    try {
        $stmt = $pdo->prepare("
            UPDATE user_books 
            SET rating = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND user_id = ?
        ");
        
        $result = $stmt->execute([$rating, $bookId, $userId]);
        
        if ($result && $stmt->rowCount() > 0) {
            deleteCache("user_stats:$userId");
            
            jsonResponse([
                'success' => true,
                'message' => 'Kitap puanlandı'
            ]);
        } else {
            jsonResponse(['error' => 'Kitap bulunamadı'], 404);
        }
        
    } catch (PDOException $e) {
        logError("Kitap puanlama hatası", [
            'user_id' => $userId,
            'book_id' => $bookId,
            'error' => $e->getMessage()
        ]);
        jsonResponse(['error' => 'Puanlama sırasında hata oluştu'], 500);
    }
}

function addBookNote() {
    requireAuth();
    $pdo = getDbConnection();
    
    $userId = $_SESSION['user_id'];
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        jsonResponse(['error' => 'Geçersiz JSON veri'], 400);
    }
    
    $bookId = sanitizeInput($input['book_id'] ?? '', 'int');
    $note = sanitizeInput($input['note'] ?? '');
    
    if (empty($bookId)) {
        jsonResponse(['error' => 'Kitap ID gerekli'], 400);
    }
    
    try {
        $stmt = $pdo->prepare("
            UPDATE user_books 
            SET notes = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND user_id = ?
        ");
        
        $result = $stmt->execute([$note, $bookId, $userId]);
        
        if ($result && $stmt->rowCount() > 0) {
            jsonResponse([
                'success' => true,
                'message' => 'Not kaydedildi'
            ]);
        } else {
            jsonResponse(['error' => 'Kitap bulunamadı'], 404);
        }
        
    } catch (PDOException $e) {
        logError("Not ekleme hatası", [
            'user_id' => $userId,
            'book_id' => $bookId,
            'error' => $e->getMessage()
        ]);
        jsonResponse(['error' => 'Not kaydedilirken hata oluştu'], 500);
    }
}

function toggleFavorite() {
    requireAuth();
    $pdo = getDbConnection();
    
    $userId = $_SESSION['user_id'];
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        jsonResponse(['error' => 'Geçersiz JSON veri'], 400);
    }
    
    $bookId = sanitizeInput($input['book_id'] ?? '', 'int');
    
    if (empty($bookId)) {
        jsonResponse(['error' => 'Kitap ID gerekli'], 400);
    }
    
    try {
        // Mevcut durumu al
        $stmt = $pdo->prepare("SELECT is_favorite FROM user_books WHERE id = ? AND user_id = ?");
        $stmt->execute([$bookId, $userId]);
        $book = $stmt->fetch();
        
        if (!$book) {
            jsonResponse(['error' => 'Kitap bulunamadı'], 404);
        }
        
        $newFavoriteStatus = $book['is_favorite'] ? 0 : 1;
        
        $stmt = $pdo->prepare("
            UPDATE user_books 
            SET is_favorite = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND user_id = ?
        ");
        
        $result = $stmt->execute([$newFavoriteStatus, $bookId, $userId]);
        
        if ($result) {
            deleteCache("user_stats:$userId");
            
            jsonResponse([
                'success' => true,
                'is_favorite' => (bool)$newFavoriteStatus,
                'message' => $newFavoriteStatus ? 'Favorilere eklendi' : 'Favorilerden kaldırıldı'
            ]);
        } else {
            jsonResponse(['error' => 'Güncelleme sırasında hata oluştu'], 500);
        }
        
    } catch (PDOException $e) {
        logError("Favori güncelleme hatası", [
            'user_id' => $userId,
            'book_id' => $bookId,
            'error' => $e->getMessage()
        ]);
        jsonResponse(['error' => 'Favori durumu güncellenirken hata oluştu'], 500);
    }
}

function getBookCategories() {
    $pdo = getDbConnection();
    
    // Cache kontrolü
    $cacheKey = "book_categories";
    $cachedCategories = getCache($cacheKey);
    if ($cachedCategories) {
        jsonResponse($cachedCategories);
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT id, name, slug, description, color
            FROM book_categories 
            WHERE is_active = 1 
            ORDER BY name ASC
        ");
        $stmt->execute();
        $categories = $stmt->fetchAll();
        
        $response = [
            'success' => true,
            'categories' => $categories
        ];
        
        // Cache'e kaydet (1 saat)
        setCache($cacheKey, $response, 3600);
        
        jsonResponse($response);
        
    } catch (PDOException $e) {
        logError("Kategori listeleme hatası", ['error' => $e->getMessage()]);
        jsonResponse(['error' => 'Kategoriler yüklenirken hata oluştu'], 500);
    }
}
?> 