/**
 * OMCN E-Kitap API Manager
 * Backend entegrasyonu için merkezi API yönetim sistemi
 */

// Duplicate loading önleme
if (typeof window.APIManager !== 'undefined') {
    console.warn('⚠️ APIManager zaten yüklenmiş, duplicate loading atlanıyor');
} else {

class APIManager {
    constructor() {
        this.baseUrl = window.location.origin;
        this.csrfToken = null;
        this.currentUser = null;
        this.init();
    }

    async init() {
        // Sayfa yüklendiğinde auth durumunu kontrol et
        try {
            await this.checkAuthStatus();
        } catch (error) {
            console.error('Auth check failed:', error);
            // Hata olursa logged out state'e geç
            this.updateUIForLoggedOutUser();
        }
    }

    /**
     * HTTP istek gönder
     */
    async request(url, options = {}) {
        const config = {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin', // Session cookie'lerini dahil et
            ...options
        };

        // CSRF token ekle
        if (this.csrfToken && ['POST', 'PUT', 'DELETE', 'PATCH'].includes(config.method)) {
            if (config.body && typeof config.body === 'string') {
                const data = JSON.parse(config.body);
                data.csrf_token = this.csrfToken;
                config.body = JSON.stringify(data);
            } else if (config.body instanceof FormData) {
                config.body.append('csrf_token', this.csrfToken);
            }
        }

        try {
            const response = await fetch(`${this.baseUrl}/${url}`, config);
            const data = await response.json();

            // CSRF token güncelle
            if (data.csrf_token) {
                this.csrfToken = data.csrf_token;
            }

            if (!response.ok) {
                throw new Error(data.error || 'API isteği başarısız');
            }

            return { success: true, data };
        } catch (error) {
            console.error('API Error:', error);
            return { success: false, error: error.message };
        }
    }

    /**
     * Auth durumunu kontrol et
     */
    async checkAuthStatus() {
        const result = await this.request('auth.php?action=check');
        if (result.success && result.data.authenticated) {
            this.currentUser = result.data.user;
            this.csrfToken = result.data.csrf_token;
            this.updateUIForLoggedInUser();
            return true;
        } else {
            this.currentUser = null;
            this.csrfToken = null;
            this.updateUIForLoggedOutUser();
            return false;
        }
    }

    /**
     * Kullanıcı kaydı
     */
    async register(userData) {
        const result = await this.request('auth.php?action=register', {
            method: 'POST',
            body: JSON.stringify(userData)
        });

        if (result.success) {
            this.currentUser = result.data.user;
            this.csrfToken = result.data.csrf_token;
            this.updateUIForLoggedInUser();
        }

        return result;
    }

    /**
     * Kullanıcı girişi
     */
    async login(credentials) {
        const result = await this.request('auth.php?action=login', {
            method: 'POST',
            body: JSON.stringify(credentials)
        });

        if (result.success) {
            this.currentUser = result.data.user;
            this.csrfToken = result.data.csrf_token;
            this.updateUIForLoggedInUser();
        }

        return result;
    }

    /**
     * Kullanıcı çıkışı
     */
    async logout() {
        const result = await this.request('auth.php?action=logout', {
            method: 'POST',
            body: JSON.stringify({})
        });

        this.currentUser = null;
        this.csrfToken = null;
        this.updateUIForLoggedOutUser();
        
        return result;
    }

    /**
     * Şifre değiştir
     */
    async changePassword(passwordData) {
        return await this.request('auth.php?action=change-password', {
            method: 'POST',
            body: JSON.stringify(passwordData)
        });
    }

    /**
     * Profil güncelle
     */
    async updateProfile(profileData) {
        return await this.request('auth.php?action=update-profile', {
            method: 'POST',
            body: JSON.stringify(profileData)
        });
    }

    /**
     * Kitap ara
     */
    async searchBooks(query, options = {}) {
        console.log('🔍 API Manager searchBooks çağrıldı:', { query, options });
        
        const params = new URLSearchParams({
            q: query,
            max: options.maxResults || 10,
            page: options.page || 1,
            ...options
        });

        console.log('🔍 API URL:', `search.php?${params}`);
        
        const result = await this.request(`search.php?${params}`);
        console.log('🔍 API Manager sonucu:', result);
        
        return result;
    }

    /**
     * Kitap kaydet
     */
    async saveBook(bookData) {
        return await this.request('library.php?action=save', {
            method: 'POST',
            body: JSON.stringify(bookData)
        });
    }

    /**
     * Kütüphane listesi
     */
    async getLibrary(options = {}) {
        const params = new URLSearchParams({
            page: options.page || 1,
            limit: options.limit || 20,
            status: options.status || '',
            category: options.category || '',
            search: options.search || ''
        });

        return await this.request(`library.php?action=list&${params}`);
    }

    /**
     * Kitap sil
     */
    async removeBook(bookId) {
        return await this.request('library.php?action=remove', {
            method: 'POST',
            body: JSON.stringify({ book_id: bookId })
        });
    }

    /**
     * Kitap durumu güncelle
     */
    async updateReadingStatus(bookId, status) {
        return await this.request('library.php?action=updateReadingStatus', {
            method: 'POST',
            body: JSON.stringify({ book_id: bookId, status })
        });
    }

    /**
     * Kitap puanla
     */
    async rateBook(bookId, rating) {
        return await this.request('library.php?action=rateBook', {
            method: 'POST',
            body: JSON.stringify({ book_id: bookId, rating })
        });
    }

    /**
     * Kullanıcı istatistikleri
     */
    async getUserStats() {
        return await this.request('library.php?action=stats');
    }

    /**
     * Giriş yapmış kullanıcı için UI güncellemeleri
     */
    updateUIForLoggedInUser() {
        // Auth modalını gizle
        const authModal = document.getElementById('auth-modal');
        if (authModal) authModal.classList.add('hidden');

        // Ana içeriği göster
        const mainContent = document.getElementById('main-content');
        if (mainContent) mainContent.classList.remove('hidden');

        // Kullanıcı adını güncelle
        if (this.currentUser) {
            const userWelcome = document.getElementById('user-welcome');
            if (userWelcome) {
                userWelcome.textContent = `Hoşgeldin, ${this.currentUser.name}`;
            }

            const userEmail = document.getElementById('user-email');
            if (userEmail) {
                userEmail.textContent = this.currentUser.email;
            }
        }

        // Auth butonlarını gizle/göster
        const authBtn = document.getElementById('auth-btn');
        const userMenuBtn = document.getElementById('user-menu-btn');
        if (authBtn) authBtn.classList.add('hidden');
        if (userMenuBtn) userMenuBtn.classList.remove('hidden');

        // Body'e loaded class ekle
        document.body.classList.add('loaded');
    }

    /**
     * Çıkış yapmış kullanıcı için UI güncellemeleri
     */
    updateUIForLoggedOutUser() {
        // Ana içeriği gizle
        const mainContent = document.getElementById('main-content');
        if (mainContent) mainContent.classList.add('hidden');

        // Auth modalını göster
        const authModal = document.getElementById('auth-modal');
        if (authModal) authModal.classList.remove('hidden');

        // Auth butonlarını göster/gizle
        const authBtn = document.getElementById('auth-btn');
        const userMenuBtn = document.getElementById('user-menu-btn');
        if (authBtn) authBtn.classList.remove('hidden');
        if (userMenuBtn) userMenuBtn.classList.add('hidden');

        // Body'e loaded class ekle
        document.body.classList.add('loaded');
    }

    /**
     * Başarı mesajı göster
     */
    showSuccess(message) {
        const toast = this.createToast('success', message);
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 5000);
    }

    /**
     * Hata mesajı göster
     */
    showError(message) {
        const toast = this.createToast('error', message);
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 7000);
    }

    /**
     * Toast bildirim oluştur
     */
    createToast(type, message) {
        const toast = document.createElement('div');
        toast.className = `fixed top-4 right-4 z-50 p-4 rounded-xl shadow-lg glass ${
            type === 'success' ? 'text-green-800' : 'text-red-800'
        } border-l-4 ${
            type === 'success' ? 'border-green-500' : 'border-red-500'
        } max-w-md`;
        
        toast.innerHTML = `
            <div class="flex items-center gap-3">
                <div class="flex-shrink-0">
                    <i class="fas ${type === 'success' ? 'fa-check-circle text-green-500' : 'fa-exclamation-circle text-red-500'} text-xl"></i>
                </div>
                <div class="flex-1">
                    <p class="font-semibold">${type === 'success' ? 'Başarılı!' : 'Hata!'}</p>
                    <p class="text-sm opacity-80">${message}</p>
                </div>
                <button class="flex-shrink-0 text-gray-500 hover:text-gray-700" onclick="this.parentElement.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;

        return toast;
    }
}

// Global API Manager instance
window.APIManager = APIManager;

// Conditional instance creation
if (!window.api) {
    window.api = new APIManager();
}

// Duplicate loading önleme kapanış
} 