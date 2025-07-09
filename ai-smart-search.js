/**
 * OMCN E-Kitap AI Öneri Sistemi ve Akıllı Arama
 * Frontend JavaScript Entegrasyonu
 */

// Duplicate loading önleme
if (typeof window.OMCNSmartSearch !== 'undefined') {
    console.warn('⚠️ OMCNSmartSearch zaten yüklenmiş, duplicate loading atlanıyor');
} else {

class OMCNSmartSearch {
    constructor(config = {}) {
        this.config = {
            apiBaseUrl: config.apiBaseUrl || '',
            userId: config.userId || 0,
            debug: config.debug || false,
            autoTrack: config.autoTrack !== false, // Default: true
            cacheTimeout: config.cacheTimeout || 300000, // 5 minutes
            ...config
        };
        
        this.cache = new Map();
        this.activityTracker = null;
        
        if (this.config.autoTrack && this.config.userId > 0) {
            this.initActivityTracker();
        }
        
        this.log('OMCN Smart Search initialized', this.config);
    }
    
    /**
     * Aktivite izleyiciyi başlat
     */
    initActivityTracker() {
        if (typeof ActivityTracker !== 'undefined') {
            this.activityTracker = new ActivityTracker(this.config.userId);
            this.log('Activity tracker initialized');
        } else {
            this.log('ActivityTracker not found, loading...', 'warn');
            this.loadActivityTracker();
        }
    }
    
    /**
     * Aktivite izleyici scriptini yükle
     */
    async loadActivityTracker() {
        try {
            const script = document.createElement('script');
            script.src = `${this.config.apiBaseUrl}user_activity_tracker.php?js=tracker`;
            script.onload = () => {
                this.activityTracker = new ActivityTracker(this.config.userId);
                this.log('Activity tracker loaded and initialized');
            };
            document.head.appendChild(script);
        } catch (error) {
            this.log('Failed to load activity tracker', 'error', error);
        }
    }
    
    /**
     * Akıllı arama (NLP destekli)
     */
    async smartSearch(query, options = {}) {
        const params = {
            q: query,
            user_id: this.config.userId,
            page: options.page || 1,
            max: options.maxResults || 20,
            hybrid: options.hybridMode || false,
            disable_nlp: options.disableNLP || false
        };
        
        const cacheKey = `smart_search_${JSON.stringify(params)}`;
        
        // Cache kontrolü
        if (this.cache.has(cacheKey)) {
            const cached = this.cache.get(cacheKey);
            if (Date.now() - cached.timestamp < this.config.cacheTimeout) {
                this.log('Returning cached smart search results');
                return { ...cached.data, cached: true };
            }
        }
        
        try {
            this.log('Performing smart search', { query, options });
            
            const url = `${this.config.apiBaseUrl}smart_search.php?${new URLSearchParams(params)}`;
            const response = await fetch(url);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const result = await response.json();
            
            // Cache'e kaydet
            this.cache.set(cacheKey, {
                data: result,
                timestamp: Date.now()
            });
            
            // Aktivite kaydet
            if (this.activityTracker) {
                this.activityTracker.trackSearch(query);
            }
            
            this.log('Smart search completed', result);
            return result;
            
        } catch (error) {
            this.log('Smart search failed', 'error', error);
            throw error;
        }
    }
    
    /**
     * AI tabanlı kişisel öneriler al
     */
    async getRecommendations(type = 'hybrid', options = {}) {
        if (this.config.userId <= 0) {
            throw new Error('Öneri sistemi için kullanıcı ID gerekli');
        }
        
        const params = {
            user_id: this.config.userId,
            type: type,
            limit: options.limit || 10,
            refresh: options.refresh || false
        };
        
        const cacheKey = `recommendations_${JSON.stringify(params)}`;
        
        // Cache kontrolü (refresh false ise)
        if (!options.refresh && this.cache.has(cacheKey)) {
            const cached = this.cache.get(cacheKey);
            if (Date.now() - cached.timestamp < this.config.cacheTimeout) {
                this.log('Returning cached recommendations');
                return { ...cached.data, cached: true };
            }
        }
        
        try {
            this.log('Getting AI recommendations', { type, options });
            
            const url = `${this.config.apiBaseUrl}ai_recommendations.php?${new URLSearchParams(params)}`;
            const response = await fetch(url);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const result = await response.json();
            
            // Cache'e kaydet
            this.cache.set(cacheKey, {
                data: result,
                timestamp: Date.now()
            });
            
            this.log('AI recommendations received', result);
            return result;
            
        } catch (error) {
            this.log('AI recommendations failed', 'error', error);
            throw error;
        }
    }
    
    /**
     * Doğal dil işleme testi
     */
    async testNLP(query) {
        try {
            this.log('Testing NLP processing', { query });
            
            const url = `${this.config.apiBaseUrl}nlp_search.php?${new URLSearchParams({
                q: query,
                user_id: this.config.userId
            })}`;
            
            const response = await fetch(url);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const result = await response.json();
            
            this.log('NLP processing completed', result);
            return result;
            
        } catch (error) {
            this.log('NLP testing failed', 'error', error);
            throw error;
        }
    }
    
    /**
     * Kitap görüntüleme aktivitesini kaydet
     */
    trackBookView(bookData) {
        if (this.activityTracker) {
            this.activityTracker.trackView({
                google_id: bookData.id,
                title: bookData.title,
                authors: bookData.authors,
                categories: bookData.categories
            });
        }
    }
    
    /**
     * Favoriye ekleme/çıkarma aktivitesini kaydet
     */
    trackFavorite(bookData, isFavorite = true) {
        if (this.activityTracker) {
            const data = {
                google_id: bookData.id,
                title: bookData.title,
                authors: bookData.authors,
                categories: bookData.categories
            };
            
            if (isFavorite) {
                this.activityTracker.trackFavorite(data);
            } else {
                this.activityTracker.trackUnfavorite(data);
            }
        }
    }
    
    /**
     * Kitap puanlama aktivitesini kaydet
     */
    trackRating(bookData, rating) {
        if (this.activityTracker) {
            this.activityTracker.trackRate({
                google_id: bookData.id,
                title: bookData.title,
                authors: bookData.authors,
                categories: bookData.categories
            }, rating);
        }
    }
    
    /**
     * Okuma başlangıcını kaydet
     */
    trackReadStart(bookData) {
        if (this.activityTracker) {
            this.activityTracker.trackReadStart({
                google_id: bookData.id,
                title: bookData.title,
                authors: bookData.authors,
                categories: bookData.categories
            });
        }
    }
    
    /**
     * Okuma ilerlemesini kaydet
     */
    trackReadProgress(bookData, page) {
        if (this.activityTracker) {
            this.activityTracker.trackReadProgress({
                google_id: bookData.id,
                title: bookData.title,
                authors: bookData.authors,
                categories: bookData.categories
            }, page);
        }
    }
    
    /**
     * Okuma tamamlama aktivitesini kaydet
     */
    trackReadComplete(bookData) {
        if (this.activityTracker) {
            this.activityTracker.trackReadComplete({
                google_id: bookData.id,
                title: bookData.title,
                authors: bookData.authors,
                categories: bookData.categories
            });
        }
    }
    
    /**
     * Kullanıcı aktivite analitiği al
     */
    async getUserAnalytics(options = {}) {
        if (this.config.userId <= 0) {
            throw new Error('Analitik için kullanıcı ID gerekli');
        }
        
        const params = {
            user_id: this.config.userId,
            start_date: options.startDate || '',
            end_date: options.endDate || '',
            limit: options.limit || 100
        };
        
        try {
            this.log('Getting user analytics', options);
            
            const url = `${this.config.apiBaseUrl}user_activity_tracker.php?${new URLSearchParams(params)}`;
            const response = await fetch(url);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const result = await response.json();
            
            this.log('User analytics received', result);
            return result;
            
        } catch (error) {
            this.log('User analytics failed', 'error', error);
            throw error;
        }
    }
    
    /**
     * Cache temizle
     */
    clearCache() {
        this.cache.clear();
        this.log('Cache cleared');
    }
    
    /**
     * Kullanıcı öneri cache'ini temizle
     */
    async clearRecommendationCache() {
        if (this.config.userId <= 0) return;
        
        try {
            // Yeni öneriler al (refresh=true)
            await this.getRecommendations('hybrid', { refresh: true });
            
            // Local cache'i de temizle
            for (const [key] of this.cache) {
                if (key.includes('recommendations_')) {
                    this.cache.delete(key);
                }
            }
            
            this.log('Recommendation cache cleared');
            
        } catch (error) {
            this.log('Failed to clear recommendation cache', 'error', error);
        }
    }
    
    /**
     * Debug log
     */
    log(message, level = 'info', data = null) {
        if (!this.config.debug) return;
        
        const timestamp = new Date().toISOString();
        const logData = data ? [message, data] : [message];
        
        switch (level) {
            case 'error':
                console.error(`[${timestamp}] OMCN Smart Search:`, ...logData);
                break;
            case 'warn':
                console.warn(`[${timestamp}] OMCN Smart Search:`, ...logData);
                break;
            default:
                console.log(`[${timestamp}] OMCN Smart Search:`, ...logData);
        }
    }
}

/**
 * UI Widget'ları
 */
class OMCNSearchWidget {
    constructor(containerId, smartSearch, options = {}) {
        this.container = document.getElementById(containerId);
        this.smartSearch = smartSearch;
        this.options = {
            showNLPToggle: options.showNLPToggle !== false,
            showFilters: options.showFilters !== false,
            showRecommendations: options.showRecommendations !== false,
            autoComplete: options.autoComplete !== false,
            ...options
        };
        
        this.currentQuery = '';
        this.currentResults = [];
        
        this.init();
    }
    
    init() {
        this.container.innerHTML = this.generateHTML();
        this.bindEvents();
        
        if (this.options.showRecommendations) {
            this.loadRecommendations();
        }
    }
    
    generateHTML() {
        return `
            <div class="omcn-search-widget">
                <div class="search-header">
                    <div class="search-input-group">
                        <input type="text" 
                               id="omcn-search-input" 
                               class="search-input" 
                               placeholder="Doğal dilde arama yapın... Örn: 'George Orwell kitapları ama sadece İngilizce olanlar'"
                               autocomplete="off">
                        <button id="omcn-search-btn" class="search-button">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                    
                    ${this.options.showNLPToggle ? `
                    <div class="search-options">
                        <label class="toggle-switch">
                            <input type="checkbox" id="nlp-toggle" checked>
                            <span class="toggle-slider"></span>
                            <span class="toggle-label">Akıllı Arama (AI)</span>
                        </label>
                    </div>
                    ` : ''}
                </div>
                
                ${this.options.showFilters ? `
                <div id="detected-filters" class="detected-filters" style="display: none;">
                    <div class="filters-header">Algılanan Filtreler:</div>
                    <div class="filters-content"></div>
                </div>
                ` : ''}
                
                <div id="search-results" class="search-results"></div>
                
                ${this.options.showRecommendations ? `
                <div id="recommendations-section" class="recommendations-section">
                    <h3>Sizin İçin Öneriler</h3>
                    <div id="recommendations-content" class="recommendations-content">
                        <div class="loading">Öneriler yükleniyor...</div>
                    </div>
                </div>
                ` : ''}
            </div>
        `;
    }
    
    bindEvents() {
        const searchInput = document.getElementById('omcn-search-input');
        const searchBtn = document.getElementById('omcn-search-btn');
        
        // Arama eventi
        const performSearch = () => {
            const query = searchInput.value.trim();
            if (query.length >= 3) {
                this.search(query);
            }
        };
        
        searchBtn.addEventListener('click', performSearch);
        searchInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                performSearch();
            }
        });
        
        // Auto-complete
        if (this.options.autoComplete) {
            let timeout;
            searchInput.addEventListener('input', (e) => {
                clearTimeout(timeout);
                const query = e.target.value.trim();
                
                if (query.length >= 3) {
                    timeout = setTimeout(() => {
                        this.showNLPPreview(query);
                    }, 500);
                }
            });
        }
    }
    
    async search(query) {
        this.currentQuery = query;
        
        const resultsContainer = document.getElementById('search-results');
        resultsContainer.innerHTML = '<div class="loading">Arama yapılıyor...</div>';
        
        try {
            const nlpEnabled = !this.options.showNLPToggle || 
                              document.getElementById('nlp-toggle')?.checked !== false;
            
            const results = await this.smartSearch.smartSearch(query, {
                disableNLP: !nlpEnabled,
                hybridMode: true,
                maxResults: 20
            });
            
            this.currentResults = results;
            this.displayResults(results);
            
            if (this.options.showFilters && results.nlp_result) {
                this.displayDetectedFilters(results.nlp_result);
            }
            
        } catch (error) {
            resultsContainer.innerHTML = `
                <div class="error">
                    Arama sırasında bir hata oluştu: ${error.message}
                </div>
            `;
        }
    }
    
    async showNLPPreview(query) {
        if (!this.options.showFilters) return;
        
        try {
            const nlpResult = await this.smartSearch.testNLP(query);
            this.displayDetectedFilters(nlpResult);
        } catch (error) {
            // Sessizce hata yoksay
        }
    }
    
    displayDetectedFilters(nlpResult) {
        const filtersContainer = document.getElementById('detected-filters');
        const filtersContent = filtersContainer?.querySelector('.filters-content');
        
        if (!filtersContainer || !filtersContent) return;
        
        const filters = nlpResult.filters || {};
        const hasFilters = Object.keys(filters).length > 0;
        
        if (hasFilters) {
            filtersContent.innerHTML = Object.entries(filters)
                .map(([key, value]) => {
                    const label = this.getFilterLabel(key);
                    return `<span class="filter-tag">${label}: ${value}</span>`;
                })
                .join('');
            
            filtersContainer.style.display = 'block';
        } else {
            filtersContainer.style.display = 'none';
        }
    }
    
    getFilterLabel(filterKey) {
        const labels = {
            'author': 'Yazar',
            'category': 'Kategori',
            'language': 'Dil',
            'year': 'Yıl',
            'audience': 'Hedef Kitle',
            'keyword': 'Anahtar Kelime'
        };
        return labels[filterKey] || filterKey;
    }
    
    displayResults(results) {
        const resultsContainer = document.getElementById('search-results');
        
        if (!results.books || results.books.length === 0) {
            resultsContainer.innerHTML = `
                <div class="no-results">
                    "${this.currentQuery}" için sonuç bulunamadı.
                </div>
            `;
            return;
        }
        
        const html = `
            <div class="results-header">
                <h3>${results.total_found} sonuç bulundu</h3>
                ${results.search_type === 'smart_nlp' ? 
                    `<span class="ai-badge">AI Destekli</span>` : ''}
            </div>
            <div class="books-grid">
                ${results.books.map(book => this.generateBookCard(book)).join('')}
            </div>
        `;
        
        resultsContainer.innerHTML = html;
        
        // Kitap kartları için event listener'lar
        this.bindBookEvents();
        
        // Sonuçlara yumuşak scroll
        setTimeout(() => {
            const mainResultsContainer = document.getElementById('results');
            if (mainResultsContainer) {
                mainResultsContainer.scrollIntoView({ 
                    behavior: 'smooth', 
                    block: 'start' 
                });
            }
        }, 100);
    }
    
    generateBookCard(book) {
        const card = document.createElement('div');
        card.className = 'book-card modern-card';
        card.dataset.bookId = book.id;

        card.innerHTML = `
            <img src="${book.cover_image || 'default-cover.jpg'}" alt="${book.title}" class="book-cover">
            <h3 class="book-title">${book.title}</h3>
            <p class="book-author">${book.author || 'Yazar bilgisi yok'}</p>
            <div class="button-group">
                <button class="save-btn">Kaydet</button>
                <button class="chat-btn">Sohbet</button>
                <button class="remove-btn">Kaldır</button>
            </div>
            <div class="button-group">
                <button onclick="readBook('${book.id}')" class="read-book-btn">Kitabı Oku</button>
                <button onclick="previewBook('${book.id}')" class="preview-book-btn">Önizle</button>
            </div>
        `;

        return card;
    }
    
    async loadRecommendations() {
        const recommendationsContent = document.getElementById('recommendations-content');
        
        if (!recommendationsContent) return;
        
        try {
            const recommendations = await this.smartSearch.getRecommendations('hybrid', { limit: 6 });
            
            if (recommendations.recommendations && recommendations.recommendations.length > 0) {
                const html = `
                    <div class="recommendations-grid">
                        ${recommendations.recommendations.map(rec => this.generateRecommendationCard(rec)).join('')}
                    </div>
                `;
                recommendationsContent.innerHTML = html;
            } else {
                recommendationsContent.innerHTML = '<div class="no-recommendations">Henüz öneriniz bulunmamaktadır.</div>';
            }
            
        } catch (error) {
            recommendationsContent.innerHTML = '<div class="error">Öneriler yüklenemedi.</div>';
        }
    }
    
    generateRecommendationCard(rec) {
        return `
            <div class="recommendation-card" data-book-id="${rec.book_google_id}">
                <div class="rec-thumbnail">
                    ${rec.thumbnail ? 
                        `<img src="${rec.thumbnail}" alt="${rec.title}" loading="lazy">` :
                        '<div class="no-image">Resim Yok</div>'
                    }
                </div>
                <div class="rec-info">
                    <h5 class="rec-title">${rec.title}</h5>
                    <p class="rec-authors">${rec.authors || 'Bilinmeyen Yazar'}</p>
                    <div class="rec-reason">
                        ${rec.reason_tags ? rec.reason_tags.join(', ') : 'Sizin için özel'}
                    </div>
                    <div class="confidence-score">Güven: ${Math.round(rec.confidence_score * 100)}%</div>
                </div>
            </div>
        `;
    }
    
    bindBookEvents() {
        // Event delegation kullanarak buton event'lerini bağla
        document.addEventListener('click', (e) => {
            const target = e.target.closest('button');
            if (!target) return;
            
            const bookId = target.getAttribute('data-book-id');
            if (!bookId) return;
            
            if (target.classList.contains('view-book')) {
                const book = this.findBookById(bookId);
                if (book) {
                    this.openBookDetails(book);
                    this.smartSearch.trackBookView(book);
                }
            } else if (target.classList.contains('favorite-book')) {
                const book = this.findBookById(bookId);
                if (book && !target.disabled) {
                    target.disabled = true;
                    target.innerHTML = '<i class="fas fa-heart"></i> Favorilerde';
                    this.smartSearch.trackFavorite(book, true);
                }
            }
        });

        // Kitabı Oku butonu için click handler
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('read-book-btn')) {
                const bookId = e.target.closest('.book-card').dataset.bookId;
                readBook(bookId);
            }
        });

        // Önizle butonu için click handler
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('preview-book-btn')) {
                const bookId = e.target.closest('.book-card').dataset.bookId;
                previewBook(bookId);
            }
        });
    }
    
    findBookById(bookId) {
        const results = this.container.querySelector('.books-grid');
        if (!results) return null;
        
        const bookCard = results.querySelector(`[data-book-id="${bookId}"]`);
        if (!bookCard) return null;
        
        return {
            id: bookId,
            title: bookCard.querySelector('.book-title').textContent,
            authors: bookCard.querySelector('.book-authors').textContent,
            categories: bookCard.querySelector('.book-categories').textContent
        };
    }
    
    openBookDetails(book) {
        // Bu fonksiyon projenizin kitap detay sayfasına göre customize edilmelidir
        if (book.info_link) {
            window.open(book.info_link, '_blank');
        } else {
            alert(`Kitap: ${book.title}\nYazar: ${book.authors}`);
        }
    }
    
    truncateText(text, maxLength) {
        if (text.length <= maxLength) return text;
        return text.substring(0, maxLength) + '...';
    }
}

// Global kullanım için export
window.OMCNSmartSearch = OMCNSmartSearch;
window.OMCNSearchWidget = OMCNSearchWidget;

// Duplicate loading önleme kapanış
}

// Global fonksiyonlar
window.showLoading = function(show = true) {
    const loadingElement = document.getElementById('loading');
    if (loadingElement) {
        loadingElement.style.display = show ? 'block' : 'none';
    }
};

window.showError = function(message) {
    console.error(message);
    // Toast notification göster
    const toast = document.createElement('div');
    toast.className = 'toast error';
    toast.textContent = message;
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: #ef4444;
        color: white;
        padding: 12px 20px;
        border-radius: 8px;
        z-index: 10000;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    `;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 5000);
};

window.showMessage = function(message) {
    console.log(message);
    // Toast notification göster
    const toast = document.createElement('div');
    toast.className = 'toast success';
    toast.textContent = message;
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: #10b981;
        color: white;
        padding: 12px 20px;
        border-radius: 8px;
        z-index: 10000;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    `;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
};

// Google Book açma fonksiyonu
window.openGoogleBook = function(googleId) {
    try {
        if (!googleId) {
            throw new Error('Google ID gerekli');
        }
        
        // Google Books okuma URL'si
        const readUrl = `https://books.google.com/books?id=${googleId}&printsec=frontcover&dq=id:${googleId}&hl=tr&sa=X&ved=0ahUKEwi`;
        
        // Yeni sekmede aç
        window.open(readUrl, '_blank', 'noopener,noreferrer');
        
        // Mesaj göster
        window.showMessage('Kitap Google Books\'ta açıldı');
        
    } catch (error) {
        console.error('Google Book açma hatası:', error);
        window.showError(error.message);
    }
};

// Google Book önizleme fonksiyonu
window.previewGoogleBook = function(googleId) {
    try {
        if (!googleId) {
            throw new Error('Google ID gerekli');
        }
        
        // Google Books preview URL'si
        const previewUrl = `https://books.google.com/books?id=${googleId}&printsec=frontcover&dq=id:${googleId}&hl=tr&sa=X&ved=0ahUKEwi&source=gbs_api`;
        
        // Yeni sekmede aç
        window.open(previewUrl, '_blank', 'noopener,noreferrer');
        
        // Mesaj göster
        window.showMessage('Kitap önizlemesi Google Books\'ta açıldı');
        
    } catch (error) {
        console.error('Google Book önizleme hatası:', error);
        window.showError(error.message);
    }
};

// PDF arama fonksiyonu
window.searchGoogleBooksForPDF = async function(title, author = '') {
    try {
        if (!title) {
            throw new Error('Kitap başlığı gerekli');
        }
        
        // Arama sorgusu oluştur
        let searchQuery = encodeURIComponent(title);
        if (author && author.trim()) {
            searchQuery += '+' + encodeURIComponent(author);
        }
        
        // Google Books'ta PDF arama
        const googleUrl = `https://books.google.com/books?q=${searchQuery}&tbm=bks`;
        
        // Yeni sekmede aç
        window.open(googleUrl, '_blank', 'noopener,noreferrer');
        
        // Mesaj göster
        window.showMessage('PDF arama sonuçları Google Books\'ta açıldı');
        
        return true;
    } catch (error) {
        console.error('PDF arama hatası:', error);
        window.showError('PDF arama sırasında bir hata oluştu: ' + error.message);
        return false;
    }
};

// Kitap okuma fonksiyonu
function readBook(bookId) {
    window.open(`read.php?id=${bookId}`, '_blank');
}

// Kitap önizleme fonksiyonu
function previewBook(bookId) {
    window.open(`preview.php?id=${bookId}`, '_blank');
}

// Diğer kitap işlemleri
function saveBook(bookId) {
    // Kaydetme işlemi
}

function chatBook(bookId) {
    // Sohbet işlemi
}

function removeBook(bookId) {
    // Kaldırma işlemi
} 