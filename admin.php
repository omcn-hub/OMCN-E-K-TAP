<?php
require_once 'config.php';

// Sadece admin kullanıcıları için
function requireAdmin() {
    if (!isset($_SESSION['user_id'])) {
        jsonResponse(['error' => 'Giriş yapmış kullanıcı gerekli'], 401);
    }
    
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user || $user['role'] !== 'admin') {
        jsonResponse(['error' => 'Admin yetkisi gerekli'], 403);
    }
}

// Rate limiting - admin için daha yüksek
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!checkRateLimit("admin:$clientIp", 200, 60)) {
    jsonResponse(['error' => 'Çok fazla istek'], 429);
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Güvenlik headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

switch ($action) {
    case 'stats':
        if ($method === 'GET') {
            getSystemStats();
        }
        break;
        
    case 'users':
        if ($method === 'GET') {
            getUsers();
        } elseif ($method === 'POST') {
            updateUser();
        }
        break;
        
    case 'logs':
        if ($method === 'GET') {
            getLogs();
        }
        break;
        
    case 'cache':
        if ($method === 'DELETE') {
            clearCache();
        }
        break;
        
    case 'backup':
        if ($method === 'POST') {
            createBackup();
        }
        break;
        
    case 'health':
        if ($method === 'GET') {
            checkSystemHealth();
        }
        break;
        
    case 'categories':
        if ($method === 'GET') {
            getCategoriesAdmin();
        } elseif ($method === 'POST') {
            addCategory();
        } elseif ($method === 'PUT') {
            updateCategory();
        } elseif ($method === 'DELETE') {
            deleteCategory();
        }
        break;
        
    default:
        jsonResponse(['error' => 'Geçersiz işlem'], 400);
}

function getSystemStats() {
    requireAdmin();
    $pdo = getDbConnection();
    
    // Cache kontrolü
    $cacheKey = "admin_stats";
    $cachedStats = getCache($cacheKey);
    if ($cachedStats) {
        jsonResponse($cachedStats);
    }
    
    try {
        // Kullanıcı istatistikleri
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_users,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_users,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as new_users_week,
                SUM(CASE WHEN last_login_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) as active_users_today
            FROM users
        ");
        $stmt->execute();
        $userStats = $stmt->fetch();
        
        // Kitap istatistikleri
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_books,
                COUNT(DISTINCT user_id) as users_with_books,
                AVG(books_per_user) as avg_books_per_user
            FROM (
                SELECT user_id, COUNT(*) as books_per_user 
                FROM user_books 
                GROUP BY user_id
            ) as user_book_counts
        ");
        $stmt->execute();
        $bookStats = $stmt->fetch();
        
        // Arama istatistikleri
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_searches,
                COUNT(DISTINCT query) as unique_queries,
                COUNT(DISTINCT ip_address) as unique_ips
            FROM search_history 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute();
        $searchStats = $stmt->fetch();
        
        // En popüler kitaplar
        $stmt = $pdo->prepare("
            SELECT 
                book_title, 
                COUNT(*) as save_count,
                AVG(rating) as avg_rating
            FROM user_books 
            WHERE book_title IS NOT NULL 
            GROUP BY book_title 
            ORDER BY save_count DESC 
            LIMIT 10
        ");
        $stmt->execute();
        $popularBooks = $stmt->fetchAll();
        
        // Sistem kaynak kullanımı
        $systemInfo = [
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'disk_free_space' => formatBytes(disk_free_space('.')),
            'disk_total_space' => formatBytes(disk_total_space('.')),
            'server_time' => date('Y-m-d H:i:s'),
            'timezone' => date_default_timezone_get()
        ];
        
        $response = [
            'success' => true,
            'user_stats' => $userStats,
            'book_stats' => $bookStats,
            'search_stats' => $searchStats,
            'popular_books' => $popularBooks,
            'system_info' => $systemInfo
        ];
        
        // Cache'e kaydet (15 dakika)
        setCache($cacheKey, $response, 900);
        
        jsonResponse($response);
        
    } catch (PDOException $e) {
        logError("Admin istatistikleri hatası", ['error' => $e->getMessage()]);
        jsonResponse(['error' => 'İstatistikler alınırken hata oluştu'], 500);
    }
}

function getUsers() {
    requireAdmin();
    $pdo = getDbConnection();
    
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(100, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $search = sanitizeInput($_GET['search'] ?? '');
    $status = $_GET['status'] ?? 'all'; // all, active, inactive
    
    try {
        // WHERE koşulları
        $whereConditions = ['1 = 1'];
        $params = [];
        
        if (!empty($search)) {
            $whereConditions[] = '(name LIKE ? OR email LIKE ?)';
            $searchTerm = "%$search%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        if ($status === 'active') {
            $whereConditions[] = 'is_active = 1';
        } elseif ($status === 'inactive') {
            $whereConditions[] = 'is_active = 0';
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        // Toplam kullanıcı sayısı
        $countSql = "SELECT COUNT(*) FROM users WHERE $whereClause";
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($params);
        $totalCount = $stmt->fetchColumn();
        
        // Kullanıcıları getir
        $sql = "
            SELECT 
                id, name, email, role, is_active, 
                created_at, last_login_at, last_login_ip,
                failed_login_attempts, locked_until,
                (SELECT COUNT(*) FROM user_books WHERE user_id = users.id) as book_count
            FROM users 
            WHERE $whereClause
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll();
        
        jsonResponse([
            'success' => true,
            'users' => $users,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total' => intval($totalCount),
                'total_pages' => ceil($totalCount / $limit)
            ]
        ]);
        
    } catch (PDOException $e) {
        logError("Kullanıcı listeleme hatası", ['error' => $e->getMessage()]);
        jsonResponse(['error' => 'Kullanıcılar listelenirken hata oluştu'], 500);
    }
}

function updateUser() {
    requireAdmin();
    $pdo = getDbConnection();
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        jsonResponse(['error' => 'Geçersiz JSON veri'], 400);
    }
    
    $userId = sanitizeInput($input['user_id'] ?? '', 'int');
    $action = sanitizeInput($input['action'] ?? '');
    
    if (empty($userId) || empty($action)) {
        jsonResponse(['error' => 'Kullanıcı ID ve işlem gerekli'], 400);
    }
    
    try {
        switch ($action) {
            case 'activate':
                $stmt = $pdo->prepare("UPDATE users SET is_active = 1 WHERE id = ?");
                $stmt->execute([$userId]);
                $message = 'Kullanıcı aktif edildi';
                break;
                
            case 'deactivate':
                $stmt = $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ?");
                $stmt->execute([$userId]);
                $message = 'Kullanıcı devre dışı bırakıldı';
                break;
                
            case 'unlock':
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET failed_login_attempts = 0, locked_until = NULL 
                    WHERE id = ?
                ");
                $stmt->execute([$userId]);
                $message = 'Kullanıcı kilidi kaldırıldı';
                break;
                
            case 'make_admin':
                $stmt = $pdo->prepare("UPDATE users SET role = 'admin' WHERE id = ?");
                $stmt->execute([$userId]);
                $message = 'Kullanıcı admin yapıldı';
                break;
                
            case 'remove_admin':
                $stmt = $pdo->prepare("UPDATE users SET role = 'user' WHERE id = ?");
                $stmt->execute([$userId]);
                $message = 'Admin yetkisi kaldırıldı';
                break;
                
            default:
                jsonResponse(['error' => 'Geçersiz işlem'], 400);
        }
        
        if ($stmt->rowCount() > 0) {
            logInfo("Admin kullanıcı işlemi", [
                'admin_id' => $_SESSION['user_id'],
                'target_user_id' => $userId,
                'action' => $action
            ]);
            
            jsonResponse([
                'success' => true,
                'message' => $message
            ]);
        } else {
            jsonResponse(['error' => 'Kullanıcı bulunamadı'], 404);
        }
        
    } catch (PDOException $e) {
        logError("Kullanıcı güncelleme hatası", [
            'user_id' => $userId,
            'action' => $action,
            'error' => $e->getMessage()
        ]);
        jsonResponse(['error' => 'Kullanıcı güncellenirken hata oluştu'], 500);
    }
}

function getLogs() {
    requireAdmin();
    $pdo = getDbConnection();
    
    $level = $_GET['level'] ?? 'all';
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(100, max(1, intval($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;
    
    try {
        // WHERE koşulları
        $whereConditions = ['1 = 1'];
        $params = [];
        
        if ($level !== 'all') {
            $whereConditions[] = 'level = ?';
            $params[] = strtoupper($level);
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        // Toplam log sayısı
        $countSql = "SELECT COUNT(*) FROM system_logs WHERE $whereClause";
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($params);
        $totalCount = $stmt->fetchColumn();
        
        // Logları getir
        $sql = "
            SELECT 
                id, level, message, context, user_id, 
                ip_address, request_uri, created_at
            FROM system_logs 
            WHERE $whereClause
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll();
        
        // Context'i JSON'dan çöz
        foreach ($logs as &$log) {
            if (!empty($log['context'])) {
                $log['context'] = json_decode($log['context'], true);
            }
        }
        
        jsonResponse([
            'success' => true,
            'logs' => $logs,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total' => intval($totalCount),
                'total_pages' => ceil($totalCount / $limit)
            ]
        ]);
        
    } catch (PDOException $e) {
        logError("Log listeleme hatası", ['error' => $e->getMessage()]);
        jsonResponse(['error' => 'Loglar listelenirken hata oluştu'], 500);
    }
}

function clearCache() {
    requireAdmin();
    
    try {
        $redis = getRedisConnection();
        if ($redis) {
            $redis->flushDB();
            jsonResponse([
                'success' => true,
                'message' => 'Önbellek temizlendi'
            ]);
        } else {
            jsonResponse(['error' => 'Redis bağlantısı bulunamadı'], 500);
        }
    } catch (Exception $e) {
        logError("Cache temizleme hatası", ['error' => $e->getMessage()]);
        jsonResponse(['error' => 'Önbellek temizlenirken hata oluştu'], 500);
    }
}

function createBackup() {
    requireAdmin();
    
    try {
        $timestamp = date('Y-m-d_H-i-s');
        $backupFile = "backup_{$timestamp}.sql";
        $backupPath = __DIR__ . "/logs/{$backupFile}";
        
        // mysqldump komutu
        $command = sprintf(
            'mysqldump --user=%s --password=%s --host=%s %s > %s',
            escapeshellarg(DB_USER),
            escapeshellarg(DB_PASS),
            escapeshellarg(DB_HOST),
            escapeshellarg(DB_NAME),
            escapeshellarg($backupPath)
        );
        
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0 && file_exists($backupPath)) {
            logInfo("Veritabanı yedeklendi", [
                'admin_id' => $_SESSION['user_id'],
                'backup_file' => $backupFile,
                'file_size' => filesize($backupPath)
            ]);
            
            jsonResponse([
                'success' => true,
                'message' => 'Veritabanı yedeklendi',
                'backup_file' => $backupFile,
                'file_size' => formatBytes(filesize($backupPath))
            ]);
        } else {
            jsonResponse(['error' => 'Yedekleme başarısız'], 500);
        }
        
    } catch (Exception $e) {
        logError("Backup hatası", ['error' => $e->getMessage()]);
        jsonResponse(['error' => 'Yedekleme sırasında hata oluştu'], 500);
    }
}

function checkSystemHealth() {
    requireAdmin();
    
    $health = [
        'status' => 'healthy',
        'checks' => []
    ];
    
    // Veritabanı bağlantısı
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->query('SELECT 1');
        $health['checks']['database'] = [
            'status' => 'ok',
            'message' => 'Veritabanı bağlantısı başarılı'
        ];
    } catch (Exception $e) {
        $health['status'] = 'unhealthy';
        $health['checks']['database'] = [
            'status' => 'error',
            'message' => 'Veritabanı bağlantı hatası'
        ];
    }
    
    // Redis bağlantısı
    try {
        $redis = getRedisConnection();
        if ($redis && $redis->ping()) {
            $health['checks']['redis'] = [
                'status' => 'ok',
                'message' => 'Redis bağlantısı başarılı'
            ];
        } else {
            $health['checks']['redis'] = [
                'status' => 'warning',
                'message' => 'Redis bağlantısı yok (opsiyonel)'
            ];
        }
    } catch (Exception $e) {
        $health['checks']['redis'] = [
            'status' => 'warning',
            'message' => 'Redis bağlantı hatası (opsiyonel)'
        ];
    }
    
    // Disk alanı kontrolü
    $freeSpace = disk_free_space('.');
    $totalSpace = disk_total_space('.');
    $usagePercent = (($totalSpace - $freeSpace) / $totalSpace) * 100;
    
    if ($usagePercent > 90) {
        $health['status'] = 'warning';
        $health['checks']['disk_space'] = [
            'status' => 'warning',
            'message' => 'Disk alanı %' . round($usagePercent, 1) . ' dolu'
        ];
    } else {
        $health['checks']['disk_space'] = [
            'status' => 'ok',
            'message' => 'Disk alanı yeterli (%' . round($usagePercent, 1) . ')'
        ];
    }
    
    // Log dosyası yazılabilirlik kontrolü
    if (is_writable(__DIR__ . '/logs/')) {
        $health['checks']['logs'] = [
            'status' => 'ok',
            'message' => 'Log dosyaları yazılabilir'
        ];
    } else {
        $health['status'] = 'warning';
        $health['checks']['logs'] = [
            'status' => 'warning',
            'message' => 'Log dizini yazılabilir değil'
        ];
    }
    
    jsonResponse($health);
}

// Yardımcı fonksiyonlar
function formatBytes($size, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $base = log($size, 1024);
    return round(pow(1024, $base - floor($base)), $precision) . ' ' . $units[floor($base)];
}

function getCategoriesAdmin() {
    requireAdmin();
    $pdo = getDbConnection();
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                id, name, slug, description, color, is_active, 
                created_at, updated_at,
                (SELECT COUNT(*) FROM user_books WHERE book_categories LIKE CONCAT('%', name, '%')) as usage_count
            FROM book_categories 
            ORDER BY name ASC
        ");
        $stmt->execute();
        $categories = $stmt->fetchAll();
        
        jsonResponse([
            'success' => true,
            'categories' => $categories
        ]);
        
    } catch (PDOException $e) {
        logError("Admin kategori listeleme hatası", ['error' => $e->getMessage()]);
        jsonResponse(['error' => 'Kategoriler listelenirken hata oluştu'], 500);
    }
}

function addCategory() {
    requireAdmin();
    $pdo = getDbConnection();
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        jsonResponse(['error' => 'Geçersiz JSON veri'], 400);
    }
    
    $name = sanitizeInput($input['name'] ?? '');
    $description = sanitizeInput($input['description'] ?? '');
    $color = sanitizeInput($input['color'] ?? '#007bff');
    
    if (empty($name)) {
        jsonResponse(['error' => 'Kategori adı gerekli'], 400);
    }
    
    $slug = strtolower(str_replace(' ', '-', 
        strtr($name, ['ş'=>'s','ı'=>'i','ç'=>'c','ü'=>'u','ö'=>'o','ğ'=>'g'])
    ));
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO book_categories (name, slug, description, color) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$name, $slug, $description, $color]);
        
        jsonResponse([
            'success' => true,
            'message' => 'Kategori eklendi',
            'category_id' => $pdo->lastInsertId()
        ]);
        
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            jsonResponse(['error' => 'Bu kategori adı zaten mevcut'], 409);
        }
        
        logError("Kategori ekleme hatası", ['error' => $e->getMessage()]);
        jsonResponse(['error' => 'Kategori eklenirken hata oluştu'], 500);
    }
}

function updateCategory() {
    requireAdmin();
    $pdo = getDbConnection();
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        jsonResponse(['error' => 'Geçersiz JSON veri'], 400);
    }
    
    $categoryId = sanitizeInput($input['id'] ?? '', 'int');
    $name = sanitizeInput($input['name'] ?? '');
    $description = sanitizeInput($input['description'] ?? '');
    $color = sanitizeInput($input['color'] ?? '#007bff');
    $isActive = $input['is_active'] ?? true;
    
    if (empty($categoryId) || empty($name)) {
        jsonResponse(['error' => 'Kategori ID ve adı gerekli'], 400);
    }
    
    try {
        $stmt = $pdo->prepare("
            UPDATE book_categories 
            SET name = ?, description = ?, color = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $result = $stmt->execute([$name, $description, $color, $isActive ? 1 : 0, $categoryId]);
        
        if ($stmt->rowCount() > 0) {
            jsonResponse([
                'success' => true,
                'message' => 'Kategori güncellendi'
            ]);
        } else {
            jsonResponse(['error' => 'Kategori bulunamadı'], 404);
        }
        
    } catch (PDOException $e) {
        logError("Kategori güncelleme hatası", ['error' => $e->getMessage()]);
        jsonResponse(['error' => 'Kategori güncellenirken hata oluştu'], 500);
    }
}

function deleteCategory() {
    requireAdmin();
    $pdo = getDbConnection();
    
    $categoryId = sanitizeInput($_GET['id'] ?? '', 'int');
    
    if (empty($categoryId)) {
        jsonResponse(['error' => 'Kategori ID gerekli'], 400);
    }
    
    try {
        // Kategori kullanımda mı kontrol et
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM user_books 
            WHERE book_categories LIKE CONCAT('%', (SELECT name FROM book_categories WHERE id = ?), '%')
        ");
        $stmt->execute([$categoryId]);
        $usageCount = $stmt->fetchColumn();
        
        if ($usageCount > 0) {
            jsonResponse(['error' => 'Bu kategori ' . $usageCount . ' kitapta kullanılıyor, silinemez'], 409);
        }
        
        // Kategoriyi sil
        $stmt = $pdo->prepare("DELETE FROM book_categories WHERE id = ?");
        $stmt->execute([$categoryId]);
        
        if ($stmt->rowCount() > 0) {
            jsonResponse([
                'success' => true,
                'message' => 'Kategori silindi'
            ]);
        } else {
            jsonResponse(['error' => 'Kategori bulunamadı'], 404);
        }
        
    } catch (PDOException $e) {
        logError("Kategori silme hatası", ['error' => $e->getMessage()]);
        jsonResponse(['error' => 'Kategori silinirken hata oluştu'], 500);
    }
}
?> 