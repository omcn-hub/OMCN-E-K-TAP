-- OMCN E-Kitap Gelişmiş Veritabanı Şeması

-- 1. Veritabanını oluştur (düzeltilmiş ad)
CREATE DATABASE IF NOT EXISTS omcn_ebook CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE omcn_ebook;

-- 2. Kullanıcılar tablosu (geliştirilmiş)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    avatar VARCHAR(500) DEFAULT NULL,
    email_verified_at TIMESTAMP NULL DEFAULT NULL,
    remember_token VARCHAR(100) DEFAULT NULL,
    failed_login_attempts INT DEFAULT 0,
    locked_until TIMESTAMP NULL DEFAULT NULL,
    last_login_at TIMESTAMP NULL DEFAULT NULL,
    last_login_ip VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_active TINYINT(1) DEFAULT 1,
    role ENUM('user', 'admin', 'moderator') DEFAULT 'user',
    
    -- İndeksler
    INDEX idx_email (email),
    INDEX idx_active (is_active),
    INDEX idx_role (role),
    INDEX idx_created_at (created_at),
    INDEX idx_email_verified (email_verified_at),
    INDEX idx_locked_until (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Kullanıcı kitapları tablosu (geliştirilmiş)
CREATE TABLE IF NOT EXISTS user_books (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    book_title VARCHAR(255) NOT NULL,
    book_authors TEXT,
    book_description TEXT,
    book_thumbnail VARCHAR(500),
    book_info_link VARCHAR(500),
    book_pdf_link VARCHAR(500),
    book_google_id VARCHAR(100),
    book_isbn VARCHAR(20) DEFAULT NULL,
    book_page_count INT DEFAULT NULL,
    book_published_date DATE DEFAULT NULL,
    book_language VARCHAR(5) DEFAULT 'tr',
    book_categories TEXT,
    reading_status ENUM('to_read', 'reading', 'completed') DEFAULT 'to_read',
    reading_progress INT DEFAULT 0, -- Sayfa numarası
    rating TINYINT DEFAULT NULL, -- 1-5 arası
    notes TEXT,
    tags TEXT,
    is_favorite TINYINT(1) DEFAULT 0,
    saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    -- İndeksler
    INDEX idx_user_id (user_id),
    INDEX idx_saved_at (saved_at),
    INDEX idx_reading_status (reading_status),
    INDEX idx_is_favorite (is_favorite),
    INDEX idx_rating (rating),
    INDEX idx_book_language (book_language),
    INDEX idx_user_status (user_id, reading_status),
    
    -- Unique constraint - bir kullanıcı aynı kitabı birden fazla kaydedemez
    UNIQUE KEY unique_user_book (user_id, book_google_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Oturum tablosu (geliştirilmiş)
CREATE TABLE IF NOT EXISTS user_sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id INT NOT NULL,
    payload TEXT NOT NULL,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    user_agent TEXT,
    is_active TINYINT(1) DEFAULT 1,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    -- İndeksler
    INDEX idx_user_id (user_id),
    INDEX idx_last_activity (last_activity),
    INDEX idx_is_active (is_active),
    INDEX idx_user_active (user_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. API anahtarları tablosu
CREATE TABLE IF NOT EXISTS api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    key_hash VARCHAR(255) NOT NULL UNIQUE,
    permissions JSON,
    last_used_at TIMESTAMP NULL DEFAULT NULL,
    expires_at TIMESTAMP NULL DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    -- İndeksler
    INDEX idx_user_id (user_id),
    INDEX idx_key_hash (key_hash),
    INDEX idx_is_active (is_active),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Rate limiting tablosu
CREATE TABLE IF NOT EXISTS rate_limits (
    id VARCHAR(255) PRIMARY KEY,
    attempts INT DEFAULT 1,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- İndeksler
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Sistem logları tablosu
CREATE TABLE IF NOT EXISTS system_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    level ENUM('DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL') NOT NULL,
    message TEXT NOT NULL,
    context JSON,
    user_id INT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    request_uri VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    
    -- İndeksler
    INDEX idx_level (level),
    INDEX idx_created_at (created_at),
    INDEX idx_user_id (user_id),
    INDEX idx_ip_address (ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Kitap kategorileri tablosu
CREATE TABLE IF NOT EXISTS book_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    slug VARCHAR(120) NOT NULL UNIQUE,
    color VARCHAR(7) DEFAULT '#007bff',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- İndeksler
    INDEX idx_slug (slug),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Arama geçmişi tablosu
CREATE TABLE IF NOT EXISTS search_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    query VARCHAR(255) NOT NULL,
    results_count INT DEFAULT 0,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    
    -- İndeksler
    INDEX idx_user_id (user_id),
    INDEX idx_query (query),
    INDEX idx_created_at (created_at),
    INDEX idx_ip_address (ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Bildirimler tablosu
CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info', 'success', 'warning', 'error') DEFAULT 'info',
    data JSON,
    read_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    -- İndeksler
    INDEX idx_user_id (user_id),
    INDEX idx_read_at (read_at),
    INDEX idx_type (type),
    INDEX idx_created_at (created_at),
    INDEX idx_user_unread (user_id, read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Örnek veriler ekle
INSERT INTO users (name, email, password, role) VALUES 
('Admin Kullanıcı', 'admin@omcn.com', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin'),
('Test Kullanıcı', 'test@omcn.com', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Örnek kategoriler
INSERT INTO book_categories (name, slug, description, color) VALUES 
('Roman', 'roman', 'Roman türü kitaplar', '#e74c3c'),
('Bilim', 'bilim', 'Bilim ve teknoloji kitapları', '#3498db'),
('Tarih', 'tarih', 'Tarih kitapları', '#f39c12'),
('Felsefe', 'felsefe', 'Felsefe kitapları', '#9b59b6'),
('Sanat', 'sanat', 'Sanat ve kültür kitapları', '#1abc9c'),
('Eğitim', 'egitim', 'Eğitim ve öğretim kitapları', '#27ae60')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Performans optimizasyonları için stored procedure'lar

-- Kullanıcı kitaplarını getir (sayfalama ile)
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS GetUserBooks(
    IN p_user_id INT,
    IN p_status VARCHAR(20),
    IN p_offset INT DEFAULT 0,
    IN p_limit INT DEFAULT 20
)
BEGIN
    SELECT 
        ub.*,
        CASE 
            WHEN ub.rating IS NOT NULL THEN ub.rating
            ELSE 0
        END AS user_rating
    FROM user_books ub
    WHERE ub.user_id = p_user_id
    AND (p_status IS NULL OR ub.reading_status = p_status)
    ORDER BY ub.updated_at DESC
    LIMIT p_offset, p_limit;
END //
DELIMITER ;

-- Kullanıcı istatistikleri
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS GetUserStats(IN p_user_id INT)
BEGIN
    SELECT 
        COUNT(*) as total_books,
        SUM(CASE WHEN reading_status = 'completed' THEN 1 ELSE 0 END) as completed_books,
        SUM(CASE WHEN reading_status = 'reading' THEN 1 ELSE 0 END) as currently_reading,
        SUM(CASE WHEN reading_status = 'to_read' THEN 1 ELSE 0 END) as to_read_books,
        SUM(CASE WHEN is_favorite = 1 THEN 1 ELSE 0 END) as favorite_books,
        AVG(CASE WHEN rating > 0 THEN rating ELSE NULL END) as average_rating
    FROM user_books 
    WHERE user_id = p_user_id;
END //
DELIMITER ;

-- Temizlik işlemleri için event scheduler
SET GLOBAL event_scheduler = ON;

-- Eski oturumları temizle (günlük)
CREATE EVENT IF NOT EXISTS cleanup_old_sessions
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO
  DELETE FROM user_sessions 
  WHERE last_activity < DATE_SUB(NOW(), INTERVAL 30 DAY);

-- Eski rate limit kayıtlarını temizle
CREATE EVENT IF NOT EXISTS cleanup_rate_limits
ON SCHEDULE EVERY 1 HOUR
STARTS CURRENT_TIMESTAMP
DO
  DELETE FROM rate_limits 
  WHERE expires_at < NOW();

-- Eski sistem loglarını temizle (90 gün sonra)
CREATE EVENT IF NOT EXISTS cleanup_old_logs
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO
  DELETE FROM system_logs 
  WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY);

-- Tabloları göster
SHOW TABLES;

-- Tabloların yapısını kontrol et
DESCRIBE users;
DESCRIBE user_books;
DESCRIBE user_sessions; 