-- OMCN E-Kitap Temel Veritabanı Şeması
USE omcn_ebook;

-- 1. Kullanıcılar tablosu
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
    
    INDEX idx_email (email),
    INDEX idx_active (is_active),
    INDEX idx_role (role),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Kullanıcı kitapları tablosu
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
    reading_progress INT DEFAULT 0,
    rating TINYINT DEFAULT NULL,
    notes TEXT,
    tags TEXT,
    is_favorite TINYINT(1) DEFAULT 0,
    saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    INDEX idx_user_id (user_id),
    INDEX idx_saved_at (saved_at),
    INDEX idx_reading_status (reading_status),
    UNIQUE KEY unique_user_book (user_id, book_google_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Oturum tablosu
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
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Sistem logları tablosu
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
    INDEX idx_level (level),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Örnek kullanıcılar ekle
INSERT INTO users (name, email, password, role) VALUES 
('Admin Kullanıcı', 'admin@omcn.com', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin'),
('Test Kullanıcı', 'test@omcn.com', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user')
ON DUPLICATE KEY UPDATE name = VALUES(name);

SHOW TABLES; 