-- AI Öneri Sistemi için Yeni Tablolar

-- 1. Kullanıcı Aktiviteleri Tablosu (Detaylı takip için)
CREATE TABLE IF NOT EXISTS user_activities (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    activity_type ENUM('view', 'search', 'favorite', 'unfavorite', 'read_start', 'read_progress', 'read_complete', 'rate') NOT NULL,
    book_google_id VARCHAR(100),
    book_title VARCHAR(255),
    book_authors TEXT,
    book_categories TEXT,
    search_query VARCHAR(255), -- arama sorguları için
    activity_data JSON, -- ek veriler (sayfa numarası, puanlama vb.)
    activity_score DECIMAL(3,2) DEFAULT 1.0, -- aktivite ağırlığı (0.1-5.0)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    -- İndeksler
    INDEX idx_user_id (user_id),
    INDEX idx_activity_type (activity_type),
    INDEX idx_book_google_id (book_google_id),
    INDEX idx_created_at (created_at),
    INDEX idx_user_activity (user_id, activity_type),
    INDEX idx_user_date (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. AI Öneriler Cache Tablosu
CREATE TABLE IF NOT EXISTS ai_recommendations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    recommendation_type ENUM('collaborative', 'content_based', 'hybrid', 'trending', 'category_based') NOT NULL,
    book_google_id VARCHAR(100) NOT NULL,
    book_title VARCHAR(255) NOT NULL,
    book_authors TEXT,
    book_categories TEXT,
    book_description TEXT,
    book_thumbnail VARCHAR(500),
    confidence_score DECIMAL(4,3) NOT NULL, -- 0.000-1.000
    reason_tags JSON, -- önerilme nedenleri ['similar_users', 'favorite_category', 'author_preference']
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    -- İndeksler
    INDEX idx_user_id (user_id),
    INDEX idx_recommendation_type (recommendation_type),
    INDEX idx_confidence_score (confidence_score),
    INDEX idx_expires_at (expires_at),
    INDEX idx_user_score (user_id, confidence_score),
    UNIQUE KEY unique_user_book_type (user_id, book_google_id, recommendation_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Kullanıcı Tercihleri Profili Tablosu
CREATE TABLE IF NOT EXISTS user_preference_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    preferred_categories JSON, -- [{"category": "Fiction", "weight": 0.8}, ...]
    preferred_authors JSON, -- [{"author": "George Orwell", "weight": 0.9}, ...]
    preferred_languages JSON, -- [{"language": "tr", "weight": 1.0}, ...]
    reading_patterns JSON, -- {"avg_pages": 250, "reading_speed": "medium", "preferred_length": "medium"}
    keyword_preferences JSON, -- sık aranan kelimeler ve ağırlıkları
    last_calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    -- İndeksler
    INDEX idx_user_id (user_id),
    INDEX idx_last_calculated (last_calculated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Doğal Dil İşleme Cache Tablosu
CREATE TABLE IF NOT EXISTS nlp_query_cache (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    original_query VARCHAR(500) NOT NULL,
    processed_filters JSON NOT NULL, -- {"author": "George Orwell", "category": "Fiction", "language": "English"}
    confidence_score DECIMAL(4,3) NOT NULL,
    processing_time_ms INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    
    -- İndeksler
    UNIQUE KEY unique_query (original_query),
    INDEX idx_expires_at (expires_at),
    INDEX idx_confidence_score (confidence_score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Kitap Benzerlik Matrisi (Opsiyonel - performance için)
CREATE TABLE IF NOT EXISTS book_similarities (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    book_id_1 VARCHAR(100) NOT NULL,
    book_id_2 VARCHAR(100) NOT NULL,
    similarity_score DECIMAL(4,3) NOT NULL,
    similarity_type ENUM('content', 'collaborative', 'hybrid') NOT NULL,
    calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- İndeksler
    INDEX idx_book1 (book_id_1),
    INDEX idx_book2 (book_id_2),
    INDEX idx_similarity_score (similarity_score),
    INDEX idx_similarity_type (similarity_type),
    UNIQUE KEY unique_book_pair (book_id_1, book_id_2, similarity_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Örnek veriler ekle
INSERT INTO book_categories (name, description, slug, color) VALUES
('Bilim Kurgu', 'Science Fiction kitapları', 'bilim-kurgu', '#9c27b0'),
('Fantastik', 'Fantasy türü kitaplar', 'fantastik', '#e91e63'),
('Polisiye', 'Cinayet ve gizem kitapları', 'polisiye', '#795548'),
('Romantik', 'Aşk hikâyeleri', 'romantik', '#f06292'),
('Tarih', 'Tarihî konular', 'tarih', '#8bc34a'),
('Felsefe', 'Felsefi eserler', 'felsefe', '#607d8b'),
('Çocuk', 'Çocuklar için kitaplar', 'cocuk', '#ffeb3b')
ON DUPLICATE KEY UPDATE description = VALUES(description); 