// Tarayıcı ortamında require tanımı - en başta tanımla
if (typeof window.require === 'undefined') {
  window.require = function(module) {
    console.warn('⚠️ require() çağrısı tarayıcıda desteklenmiyor:', module);
    return {};
  };
}

// Resim hata işleyicisi - Console kabarmasını önler
function handleImageError(img) {
  if (!img.dataset.errorHandled) {
    img.dataset.errorHandled = 'true';
    // Local placeholder kullan - DNS hatası yok
    img.src = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iODAiIGhlaWdodD0iMTIwIiB2aWV3Qm94PSIwIDAgODAgMTIwIiBmaWxsPSJub25lIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPgo8cmVjdCB3aWR0aD0iODAiIGhlaWdodD0iMTIwIiBmaWxsPSIjZTJlOGYwIi8+Cjx0ZXh0IHg9IjQwIiB5PSI2MCIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjEwIiBmaWxsPSIjNjQ3NDhiIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBkeT0iMC4zZW0iPktpdGFwIFJlc21pPC90ZXh0Pgo8L3N2Zz4K';
    img.onerror = null; // Tekrar hata vermesin diye onerror'u kaldır
  }
}

document.addEventListener('DOMContentLoaded', function() {
  const searchBtn = document.getElementById('search-btn');
  const searchInput = document.getElementById('search-input');
  const resultsDiv = document.getElementById('results');
  const loadingDiv = document.getElementById('loading');
  const modal = document.getElementById('modal');
  const closeModalBtn = document.getElementById('close-modal');
  const readerFrame = document.getElementById('reader-frame');
  const categoriesDiv = document.getElementById('categories');
  const categoryBtns = categoriesDiv ? categoriesDiv.querySelectorAll('.category-btn') : [];
  const filterSelect = document.getElementById('filter-select');
  const loginForm = document.getElementById('login-form');
  const signupForm = document.getElementById('signup-form');
  const logoutBtn = document.getElementById('logout-btn');
  const toggleAuth = document.getElementById('toggle-auth');
  const authTitle = document.getElementById('auth-title');
  const authDesc = document.getElementById('auth-desc');
  const aboutBtn = document.getElementById('about-btn');
  const aboutModal = document.getElementById('about-modal');
  const closeAboutModal = document.getElementById('close-about-modal');
  
  // Kullanıcı menü elementleri
  const userMenuBtn = document.getElementById('user-menu-btn');
  const userMenu = document.getElementById('user-menu');
  const profileBtn = document.getElementById('profile-btn');
  const profileModal = document.getElementById('profile-modal');
  const closeProfileModal = document.getElementById('close-profile-modal');
  const profileForm = document.getElementById('profile-form');
  const cancelProfileEdit = document.getElementById('cancel-profile-edit');
  const changePasswordBtn = document.getElementById('change-password-btn');
  const changePasswordModal = document.getElementById('change-password-modal');
  const closeChangePasswordModal = document.getElementById('close-change-password-modal');
  const changePasswordForm = document.getElementById('change-password-form');
  const cancelPasswordChange = document.getElementById('cancel-password-change');
  
  let lastBooks = [];
  let isLogin = true;

  // Giriş kontrolü: Backend'den kullanıcı bilgilerini al
  const authModal = document.getElementById('auth-modal');
  const mainContent = document.getElementById('main-content');
  
  // Kullanıcı bilgilerini güncelle - API Manager entegreli
  function updateUserInfo() {
    if (window.api && window.api.currentUser) {
      const userWelcome = document.getElementById('user-welcome');
      if (userWelcome) {
        userWelcome.textContent = 'Hoşgeldin, ' + window.api.currentUser.name;
      }
      
      const userEmail = document.getElementById('user-email');
      if (userEmail) {
        userEmail.textContent = window.api.currentUser.email;
      }
    }
  }
  
  // API Manager'ı bekle ve kullanıcı bilgilerini güncelle
  const waitForAPI = () => {
    if (window.api && window.api.currentUser) {
      updateUserInfo();
      // Eğer kullanıcı giriş yapmışsa kaydedilmiş kitapları kontrol et
      if (window.api.currentUser) {
        setTimeout(checkSavedBooks, 1000);
      }
    } else {
      setTimeout(waitForAPI, 100);
    }
  };
  
  // Sayfa yüklenir yüklenmez API'yi bekle
  waitForAPI();

  // Modern dropdown sistemini başlat
  initPremiumDropdowns();
  
  // Popüler arama butonlarını başlat
  initPopularSearchButtons();

  // Popüler Arama Butonları Sistemi
  function initPopularSearchButtons() {
    const popularBtns = document.querySelectorAll('.popular-search-btn');
    
    popularBtns.forEach(btn => {
      btn.addEventListener('click', function(e) {
        e.preventDefault();
        
        const searchTerm = this.getAttribute('data-search');
        
        // Arama kutusuna terimi yaz
        const aiSearchInput = document.getElementById('search-input');
        if (aiSearchInput) {
          aiSearchInput.value = searchTerm;
          
          // Buton animasyonu
          this.style.transform = 'scale(0.95)';
          setTimeout(() => {
            this.style.transform = 'scale(1.05)';
            setTimeout(() => {
              this.style.transform = '';
            }, 150);
          }, 100);
          
          // Arama yap
          setTimeout(() => {
            performAISearch();
          }, 300);
        }
      });
      
      // Hover efekti
      btn.addEventListener('mouseenter', function() {
        this.style.transform = 'scale(1.05) translateY(-2px)';
      });
      
      btn.addEventListener('mouseleave', function() {
        this.style.transform = '';
      });
    });
    
    // Enter tuşu ile arama
    const aiSearchInput = document.getElementById('search-input');
    if (aiSearchInput) {
      aiSearchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          performAISearch();
        }
      });
    }
  }

  // Modern Premium Dropdown Sistemi
  function initPremiumDropdowns() {
    const dropdownBtns = document.querySelectorAll('.premium-dropdown-btn');
    const dropdownMenus = document.querySelectorAll('.premium-dropdown-menu');
    
    // Her dropdown butonu için event listener ekle
    dropdownBtns.forEach(btn => {
      btn.addEventListener('click', function(e) {
        e.stopPropagation();
        
        const dropdownType = this.getAttribute('data-dropdown');
        const menu = this.nextElementSibling;
        
        // Diğer açık dropdown'ları kapat
        dropdownMenus.forEach(otherMenu => {
          if (otherMenu !== menu) {
            otherMenu.style.opacity = '0';
            otherMenu.style.visibility = 'hidden';
            otherMenu.style.transform = 'translateY(8px)';
          }
        });
        
        // Bu dropdown'u aç/kapat
        if (menu.style.opacity === '1') {
          menu.style.opacity = '0';
          menu.style.visibility = 'hidden';
          menu.style.transform = 'translateY(8px)';
        } else {
          menu.style.opacity = '1';
          menu.style.visibility = 'visible';
          menu.style.transform = 'translateY(0)';
        }
      });
    });
    
    // Dropdown seçeneklerine event listener ekle
    const dropdownOptions = document.querySelectorAll('.dropdown-option');
    dropdownOptions.forEach(option => {
      option.addEventListener('click', function(e) {
        e.stopPropagation();
        
        const value = this.getAttribute('data-value');
        const text = this.querySelector('span').textContent;
        const menu = this.closest('.premium-dropdown-menu');
        const btn = menu.previousElementSibling;
        const dropdownType = btn.getAttribute('data-dropdown');
        
        // Buton metnini güncelle
        if (dropdownType === 'sort') {
          btn.querySelector('.sort-text').textContent = text;
          handleSortChange(value);
        } else if (dropdownType === 'language') {
          btn.querySelector('.language-text').textContent = text;
          handleLanguageChange(value);
        } else if (dropdownType === 'year') {
          btn.querySelector('.year-text').textContent = text;
          handleYearChange(value);
        } else if (dropdownType === 'pages') {
          btn.querySelector('.pages-text').textContent = text;
          handlePagesChange(value);
        }
        
        // Seçili seçeneği vurgula
        const siblings = this.parentElement.querySelectorAll('.dropdown-option');
        siblings.forEach(sibling => sibling.classList.remove('selected'));
        this.classList.add('selected');
        
        // Dropdown'u kapat
        menu.style.opacity = '0';
        menu.style.visibility = 'hidden';
        menu.style.transform = 'translateY(8px)';
      });
    });
    
    // Dışarı tıklandığında dropdown'ları kapat
    document.addEventListener('click', function() {
      dropdownMenus.forEach(menu => {
        menu.style.opacity = '0';
        menu.style.visibility = 'hidden';
        menu.style.transform = 'translateY(8px)';
      });
    });
    
    // ESC tuşu ile dropdown'ları kapat
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        dropdownMenus.forEach(menu => {
          menu.style.opacity = '0';
          menu.style.visibility = 'hidden';
          menu.style.transform = 'translateY(8px)';
        });
      }
    });
  }
  
  // Sıralama değişikliğini handle et
  function handleSortChange(sortValue) {
    const query = getCurrentSearchQuery();
    if (query) {
      performAdvancedSearch(query);
      
      // Sonuçlara yumuşak scroll
      setTimeout(() => {
        const resultsContainer = document.getElementById('results');
        if (resultsContainer) {
          resultsContainer.scrollIntoView({ 
            behavior: 'smooth', 
            block: 'start' 
          });
        }
      }, 300);
    }
  }
  
  // Dil değişikliğini handle et
  function handleLanguageChange(langValue) {
    // Tüm mevcut filtrelerle arama yap
    const query = getCurrentSearchQuery();
    if (query) {
      performAdvancedSearch(query);
      
      // Sonuçlara yumuşak scroll
      setTimeout(() => {
        const resultsContainer = document.getElementById('results');
        if (resultsContainer) {
          resultsContainer.scrollIntoView({ 
            behavior: 'smooth', 
            block: 'start' 
          });
        }
      }, 300);
    }
  }
  
  // Yıl değişikliğini handle et
  function handleYearChange(yearValue) {
    const query = getCurrentSearchQuery();
    if (query) {
      performAdvancedSearch(query);
      
      // Sonuçlara yumuşak scroll
      setTimeout(() => {
        const resultsContainer = document.getElementById('results');
        if (resultsContainer) {
          resultsContainer.scrollIntoView({ 
            behavior: 'smooth', 
            block: 'start' 
          });
        }
      }, 300);
    }
  }
  
  // Sayfa sayısı değişikliğini handle et
  function handlePagesChange(pagesValue) {
    const query = getCurrentSearchQuery();
    if (query) {
      performAdvancedSearch(query);
      
      // Sonuçlara yumuşak scroll
      setTimeout(() => {
        const resultsContainer = document.getElementById('results');
        if (resultsContainer) {
          resultsContainer.scrollIntoView({ 
            behavior: 'smooth', 
            block: 'start' 
          });
        }
      }, 300);
    }
  }
  
  // Sıralamalı arama yap
  async function performSearchWithSort(query, sortValue) {
    showLoading(true);
    resultsDiv.innerHTML = '';
    
    const searchParams = {
      maxResults: 16,
      orderBy: getSortOrderBy(sortValue)
    };
    
    const result = await window.api.searchBooks(query, searchParams);
    
    showLoading(false);
    
    if (result.success && result.data.books) {
      renderResults(result.data.books);
    } else {
      showMessage('Arama sonucu bulunamadı.');
    }
  }
  
  // Dil filtreli arama yap
  async function performSearchWithLanguage(query, langValue) {
    showLoading(true);
    resultsDiv.innerHTML = '';
    
    const searchParams = {
      maxResults: 16,
      langRestrict: langValue === 'all' ? undefined : langValue
    };
    
    const result = await window.api.searchBooks(query, searchParams);
    
    showLoading(false);
    
    if (result.success && result.data.books) {
      renderResults(result.data.books);
    } else {
      showMessage('Arama sonucu bulunamadı.');
    }
  }
  
  // Sıralama değerini Google Books API formatına çevir
  function getSortOrderBy(sortValue) {
    switch (sortValue) {
      case 'newest':
        return 'newest';
      case 'relevance':
        return 'relevance';
      case 'rating':
        return 'relevance'; // Google Books doesn't have direct rating sort
      case 'bestseller':
        return 'relevance';
      case 'classic':
        return 'relevance';
      default:
        return 'newest'; // Varsayılan olarak en yeniler
    }
  }

  function showLoading(show) {
    const loadingElement = loadingDiv || document.getElementById('loading');
    if (loadingElement) {
      loadingElement.classList.toggle('hidden', !show);
    } else {
      console.warn('Loading elementi bulunamadı');
    }
  }

  function showMessage(message) {
    const resultsElement = resultsDiv || document.getElementById('results');
    if (resultsElement) {
      resultsElement.innerHTML = `
        <div class="glass-strong rounded-3xl p-12 text-center border border-white/20 mobile-padding backdrop-light" style="background: rgba(0,0,0,0.4);">
          <div class="w-16 h-16 bg-gradient-to-r from-blue-400 to-purple-500 rounded-2xl flex items-center justify-center mx-auto mb-4">
            <i class="fas fa-info-circle text-white text-2xl"></i>
          </div>
          <h3 class="text-white-super-readable font-heavy text-xl mb-2 text-readable">${message}</h3>
          <p class="text-white-super-readable text-readable">Farklı anahtar kelimeler deneyebilir veya kategorilerden birini seçebilirsiniz</p>
        </div>
      `;
    } else {
      console.warn('Results elementi bulunamadı:', message);
    }
  }

  // Kitap aksiyon butonlarını oluştur
  function getBookActionButtons(book, isLibrary) {
    if (isLibrary) {
      // Kütüphane kitapları için - veritabanı ID'si var
      return `
        <button class="action-btn read-btn" onclick="event.preventDefault(); readBook('${book.id}')">
          <i class="fas fa-book-reader"></i>
          <span>Kitabı Oku</span>
        </button>
        
        <button class="action-btn preview-btn" onclick="event.preventDefault(); previewBook('${book.id}')">
          <i class="fas fa-eye"></i>
          <span>Önizle</span>
        </button>

        <button class="action-btn search-btn" onclick="event.preventDefault(); searchGoogleBooksForPDF('${book.title}', '${book.authors ? book.authors.join(", ") : ""}')">
          <i class="fas fa-file-pdf"></i>
          <span>PDF</span>
        </button>
      `;
    } else {
      // Arama sonuçları için - Google Books kitapları
      const hasLocalPdf = book.pdf && book.pdf.trim() && book.pdf !== 'null';
      const hasPreview = book.previewLink && book.previewLink.trim() && book.previewLink !== 'null';
      const hasInfo = book.infoLink && book.infoLink.trim() && book.infoLink !== 'null';
      const googleId = book.google_id || book.id || '';
      
      // Debug log
      console.log('Kitap buton durumu:', {
        title: book.title,
        hasLocalPdf,
        hasPreview, 
        hasInfo,
        googleId,
        pdf: book.pdf,
        previewLink: book.previewLink,
        infoLink: book.infoLink
      });
      
      const authorsArr = Array.isArray(book.authors) ? book.authors : [];
      const escapedTitle = (book.title || '').replace(/'/g, "\\'");
      const escapedAuthors = authorsArr.join(', ').replace(/'/g, "\\'");
      
      return `
        <button class="action-btn read-btn" onclick="event.preventDefault(); openGoogleBook('${googleId}')">
          <i class="fas fa-book-open"></i>
          <span>Kitabı Oku</span>
        </button>
        
        <button class="action-btn preview-btn" onclick="event.preventDefault(); previewGoogleBook('${googleId}')">
          <i class="fas fa-eye"></i>
          <span>Önizle</span>
        </button>
        
        <button class="action-btn search-btn" onclick="event.preventDefault(); searchGoogleBooksForPDF('${escapedTitle}', '${escapedAuthors}')">
          <i class="fas fa-file-pdf"></i>
          <span>PDF</span>
        </button>
      `;
    }
  }

  // Kütüphane için özel render fonksiyonu
  function renderLibraryResults(books) {
    if (!Array.isArray(books) || !books.length) {
      showMessage('Kütüphaneniz boş.');
      return;
    }
    
    // Başlık ve kitapları birlikte render et
    resultsDiv.innerHTML = `
      <div class="text-center mb-8 p-6 bg-black/40 rounded-2xl backdrop-blur-md border border-white/20">
        <h2 class="text-3xl hero-text-strong mb-2">📚 Kütüphanem</h2>
        <p class="text-white-super-readable font-semibold text-lg">Kaydedilmiş ${books.length} kitap bulundu</p>
      </div>
      <div class="books-grid">
        ${books.map(book => renderBookCard(book, true)).join('')}
      </div>
    `;
    
    bindBookEvents();
    setTimeout(checkSavedBooks, 500);
  }

  // Normal arama sonuçları için render fonksiyonu
  function renderResults(books, isLibrary = false) {
    if (!Array.isArray(books) || !books.length) {
      showMessage(isLibrary ? 'Kütüphaneniz boş.' : 'Kitap bulunamadı.');
      return;
    }
    
    const resultsElement = resultsDiv || document.getElementById('results');
    if (resultsElement) {
      resultsElement.innerHTML = `
        <div class="books-grid">
          ${books.map(book => renderBookCard(book, isLibrary)).join('')}
        </div>
      `;
      
      bindBookEvents();
      setTimeout(checkSavedBooks, 500);
    } else {
      console.error('Results elementi bulunamadı, sonuçlar gösterilemiyor');
      console.log('Bulunan kitaplar:', books.length);
    }
  }

  // Tek kitap kartı render fonksiyonu
  function renderBookCard(book, isLibrary = false) {
    const title = book.title || 'Başlık yok';
    const authorsArr = Array.isArray(book.authors) ? book.authors : [];
    const authors = authorsArr.length ? authorsArr.join(', ') : 'Yazar bilgisi yok';
    // Modern local placeholder - konsol hatası yok, profesyonel görünüm
    const thumbnail = book.thumbnail || 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iODAiIGhlaWdodD0iMTIwIiB2aWV3Qm94PSIwIDAgODAgMTIwIiBmaWxsPSJub25lIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPgo8ZGVmcz4KPGxpbmVhckdyYWRpZW50IGlkPSJncmFkIiB4MT0iMCUiIHkxPSIwJSIgeDI9IjEwMCUiIHkyPSIxMDAlIj4KPHN0b3Agb2Zmc2V0PSIwJSIgc3RvcC1jb2xvcj0iI2YxZjVmOSIvPgo8c3RvcCBvZmZzZXQ9IjEwMCUiIHN0b3AtY29sb3I9IiNlMmU4ZjAiLz4KPC9saW5lYXJHcmFkaWVudD4KPC9kZWZzPgo8cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSJ1cmwoI2dyYWQpIiByeD0iNCIvPgo8Y2lyY2xlIGN4PSI0MCIgY3k9IjQ1IiByPSIxMiIgZmlsbD0iI2NiZDFkYyIvPgo8cGF0aCBkPSJNMzYgNDJINDRWNDhIMzZaIiBmaWxsPSIjZjlmYWZiIi8+Cjx0ZXh0IHg9IjQwIiB5PSI3OCIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjkiIGZvbnQtd2VpZ2h0PSI2MDAiIHRleHQtYW5jaG9yPSJtaWRkbGUiIGZpbGw9IiM2NDc0OGIiPlJlc2ltIFlvazwvdGV4dD4KPHJlY3QgeD0iMTUiIHk9Ijk1IiB3aWR0aD0iNTAiIGhlaWdodD0iNCIgZmlsbD0iI2NiZDFkYyIgcng9IjIiIG9wYWNpdHk9IjAuNSIvPgo8cmVjdCB4PSIyMCIgeT0iMTAyIiB3aWR0aD0iNDAiIGhlaWdodD0iMyIgZmlsbD0iI2NiZDFkYyIgcng9IjEuNSIgb3BhY2l0eT0iMC41Ii8+Cjwvc3ZnPgo=';
    const description = book.description ? book.description.substring(0, 180) + (book.description.length > 180 ? '...' : '') : 'Açıklama yok.';
    const bookId = book.id || book.google_id || '';
    const bookData = encodeURIComponent(JSON.stringify({...book, id: bookId}));
    
    return `
      <div class="book-card">
        <div class="card-content">
          <div class="book-thumbnail">
            <img src="${thumbnail}" alt="${title}" onerror="handleImageError(this)">
          </div>
          
          <div class="book-info">
            <h3 class="book-title">${title}</h3>
            <p class="book-author"><i class="fas fa-user-edit text-gray-500 mr-1"></i>${authors}</p>
            <p class="book-desc">${description}</p>
          </div>
        </div>

        <div class="button-container">
          <div class="primary-actions">
          ${!isLibrary ? `
            <button class="action-btn save-btn" data-book="${bookData}">
              <i class="fas fa-bookmark"></i>
              <span>Kaydet</span>
            </button>
          ` : ''}
          
          <button class="action-btn chat-btn" data-book="${bookData}">
            <i class="fas fa-comments"></i>
            <span>Sohbet</span>
          </button>
          
          ${isLibrary ? `
              <button class="action-btn remove-btn" data-book-id="${bookId}">
              <i class="fas fa-trash"></i>
              <span>Kaldır</span>
            </button>
          ` : ''}
        </div>

          <div class="secondary-actions">
          ${getBookActionButtons(book, isLibrary)}
          </div>
        </div>
      </div>
    `;
  }

  async function readBook(bookId) {
    try {
        // Rate limit kontrolü
        const cacheKey = `read_${bookId}_${Date.now()}`;
        const lastRead = localStorage.getItem(cacheKey);
        
        if (lastRead && (Date.now() - parseInt(lastRead)) < 60000) { // 1 dakika
            throw new Error('Çok sık okuma isteği gönderdiniz. Lütfen biraz bekleyin.');
        }
        
        localStorage.setItem(cacheKey, Date.now().toString());
        
        // Önce API'den kontrol et
        const response = await fetch(`read.php?id=${encodeURIComponent(bookId)}`, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'include'
        });
        
        if (!response.ok) {
            const data = await response.json();
            throw new Error(data.error || 'Kitap açılırken bir hata oluştu');
        }
        
        // Yeni sekmede aç
        window.open(`read.php?id=${encodeURIComponent(bookId)}`, '_blank');
        
    } catch (error) {
        showError(error.message);
    }
  }

  async function previewBook(bookId) {
    try {
        // Rate limit kontrolü
        const cacheKey = `preview_${bookId}_${Date.now()}`;
        const lastPreview = localStorage.getItem(cacheKey);
        
        if (lastPreview && (Date.now() - parseInt(lastPreview)) < 60000) { // 1 dakika
            throw new Error('Çok sık önizleme isteği gönderdiniz. Lütfen biraz bekleyin.');
        }
        
        localStorage.setItem(cacheKey, Date.now().toString());
        
        // Önce API'den kontrol et
        const response = await fetch(`preview.php?id=${encodeURIComponent(bookId)}`, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'include'
        });
        
        if (!response.ok) {
            const data = await response.json();
            throw new Error(data.error || 'Önizleme açılırken bir hata oluştu');
        }
        
        // Yeni sekmede aç
        window.open(`preview.php?id=${encodeURIComponent(bookId)}`, '_blank');
        
    } catch (error) {
        showError(error.message);
    }
  }

  // Google Books kitaplarını okuma fonksiyonu
  async function readGoogleBook(googleId, pdfUrl) {
    try {
        if (!pdfUrl) {
            throw new Error('PDF dosyası bulunamadı');
        }
        
        // Rate limit kontrolü
        const cacheKey = `read_google_${googleId}_${Date.now()}`;
        const lastRead = localStorage.getItem(cacheKey);
        
        if (lastRead && (Date.now() - parseInt(lastRead)) < 60000) { // 1 dakika
            throw new Error('Çok sık okuma isteği gönderdiniz. Lütfen biraz bekleyin.');
        }
        
        localStorage.setItem(cacheKey, Date.now().toString());
        
        // PDF'yi yeni sekmede aç
        window.open(pdfUrl, '_blank');
        
        // Aktiviteyi logla
        if (window.api && window.api.currentUser) {
            logUserActivity('read_google_book', {
                google_id: googleId,
                pdf_url: pdfUrl
            });
        }
        
    } catch (error) {
        showError(error.message);
    }
  }

  // Dış link açma fonksiyonu
  function openExternalLink(url) {
    try {
        if (!url) {
            throw new Error('Geçersiz link');
        }
        
        // HTTP linklerini HTTPS'e çevir
        if (url.startsWith('http://')) {
            url = url.replace('http://', 'https://');
        }
        
        // Güvenlik kontrolü - sadece güvenli domainleri kabul et
        const allowedDomains = [
            'books.google.com',
            'play.google.com',
            'books.google.com.tr',
            'localhost'
        ];
        
        const urlObj = new URL(url);
        const domain = urlObj.hostname.toLowerCase();
        
        const isAllowed = allowedDomains.some(allowedDomain => 
            domain === allowedDomain || domain.endsWith('.' + allowedDomain)
        );
        
        if (!isAllowed && !url.startsWith('https://')) {
            throw new Error('Güvenli olmayan link');
        }
        
        // Yeni sekmede aç
        window.open(url, '_blank', 'noopener,noreferrer');
        
        // Aktiviteyi logla
        if (window.api && window.api.currentUser) {
            logUserActivity('open_external_link', {
                url: url
            });
        }
        
    } catch (error) {
        console.error('Link açma hatası:', error);
        showError(error.message);
    }
  }

  // Google'da kitap arama fonksiyonu
  function searchGoogleForBook(title, author = '') {
    try {
        if (!title) {
            throw new Error('Kitap başlığı gerekli');
        }
        
        // Arama sorgusu oluştur
        let searchQuery = encodeURIComponent(title);
        if (author && author.trim()) {
            searchQuery += '+' + encodeURIComponent(author);
        }
        
        // Google Books arama URL'si
        const searchUrl = `https://books.google.com/books?q=${searchQuery}`;
        
        // Yeni sekmede aç
        window.open(searchUrl, '_blank', 'noopener,noreferrer');
        
        // Aktiviteyi logla
        if (window.api && window.api.currentUser) {
            logUserActivity('google_book_search', {
                title: title,
                author: author
            });
        }
        
    } catch (error) {
        console.error('Google arama hatası:', error);
        showError(error.message);
    }
  }

  // Google Book açma fonksiyonu
  function openGoogleBook(googleId) {
    try {
        if (!googleId) {
            throw new Error('Google ID gerekli');
        }
        
        // Google Books okuma URL'si
        const readUrl = `https://books.google.com/books?id=${googleId}&printsec=frontcover&dq=id:${googleId}&hl=tr&sa=X&ved=0ahUKEwi`;
        
        // Yeni sekmede aç
        window.open(readUrl, '_blank', 'noopener,noreferrer');
        
        // Aktiviteyi logla
        if (window.api && window.api.currentUser) {
            logUserActivity('open_google_book', {
                google_id: googleId
            });
        }
        
    } catch (error) {
        console.error('Google Book açma hatası:', error);
        showError(error.message);
    }
  }

  // Google Book önizleme fonksiyonu
  function previewGoogleBook(googleId) {
    try {
        if (!googleId) {
            throw new Error('Google ID gerekli');
        }
        
        // Google Books preview URL'si
        const previewUrl = `https://books.google.com/books?id=${googleId}&printsec=frontcover&dq=id:${googleId}&hl=tr&sa=X&ved=0ahUKEwi&source=gbs_api`;
        
        // Yeni sekmede aç
        window.open(previewUrl, '_blank', 'noopener,noreferrer');
        
        // Aktiviteyi logla
        if (window.api && window.api.currentUser) {
            logUserActivity('preview_google_book', {
                google_id: googleId
            });
        }
        
    } catch (error) {
        console.error('Google Book önizleme hatası:', error);
        showError(error.message);
    }
  }

  // Mevcut arama sorgusu alma fonksiyonu
  function getCurrentSearchQuery() {
    const searchInput = document.getElementById('search-input');
    return searchInput ? searchInput.value.trim() : '';
  }

  // Kullanıcı aktivitesi loglama fonksiyonu
  function logUserActivity(activity, data = {}) {
    try {
        if (!window.api || !window.api.currentUser) {
            return; // Kullanıcı giriş yapmamış
        }
        
        const activityData = {
            activity: activity,
            timestamp: new Date().toISOString(),
            user_id: window.api.currentUser.id,
            ...data
        };
        
        // Backend'e gönder (fire and forget)
        fetch('user_activity_tracker.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(activityData),
            credentials: 'include'
        }).catch(error => {
            console.warn('Aktivite loglanamadı:', error);
        });
        
    } catch (error) {
        console.warn('Aktivite loglama hatası:', error);
    }
  }

  // Kitap kartı event listener'larını bağla
  function bindBookEvents() {
    // Kaydet butonları
    document.querySelectorAll('.save-btn').forEach(btn => {
      btn.addEventListener('click', async function(e) {
        e.preventDefault();
        const bookData = JSON.parse(decodeURIComponent(this.dataset.book));
        await saveBookToLibrary(bookData, this);
      });
    });

    // Sohbet butonları
    document.querySelectorAll('.chat-btn').forEach(btn => {
      btn.addEventListener('click', async function(e) {
        e.preventDefault();
        const bookData = JSON.parse(decodeURIComponent(this.dataset.book));
        await openBookChat(bookData);
      });
    });

    // Kaldır butonları
    document.querySelectorAll('.remove-btn').forEach(btn => {
      btn.addEventListener('click', async function(e) {
        e.preventDefault();
        const bookId = this.dataset.bookId;
        await removeBookFromLibrary(bookId, this);
      });
    });
  }

  async function searchBooks() {
    if (!searchInput) return;
    const query = searchInput.value.trim();
    if (!query) {
      showMessage('Lütfen bir kitap başlığı girin.');
      return;
    }
    showLoading(true);
    resultsDiv.innerHTML = '';
    
    const result = await window.api.searchBooks(query, { maxResults: 12 });
    showLoading(false);
    
    if (result.success) {
      if (result.data.books && result.data.books.length > 0) {
        renderResults(result.data.books);
        
        // Sonuçlara yumuşak scroll
        setTimeout(() => {
          const resultsContainer = document.getElementById('results');
          if (resultsContainer) {
            resultsContainer.scrollIntoView({ 
              behavior: 'smooth', 
              block: 'start' 
            });
          }
        }, 100);
      } else {
        showMessage('Aradığınız kriterlerde kitap bulunamadı.');
      }
    } else {
      showMessage('Arama sırasında bir hata oluştu: ' + result.error);
    }
  }

  // Bu event listener'lar artık initEventListeners() fonksiyonunda tanımlanıyor

  // Eğer modal yoksa, ilgili kodları çalıştırma
  if (modal && closeModalBtn && readerFrame) {
  closeModalBtn.addEventListener('click', function() {
    modal.classList.add('hidden');
    readerFrame.src = '';
  });

  modal.addEventListener('click', function(e) {
    if (e.target === modal) {
      modal.classList.add('hidden');
      readerFrame.src = '';
    }
  });
  }

  // Kategori butonlarına tıklama
  categoryBtns.forEach(btn => {
    btn.addEventListener('click', function() {
      // Tüm butonlardan seçili stilini kaldır
      categoryBtns.forEach(b => b.classList.remove('category-active'));
      // Seçili butona stil ekle
      this.classList.add('category-active');
      // Arama kutusunu temizle
      if (searchInput) searchInput.value = '';
      // Kategoriye göre popüler kitapları getir
      searchBooksByCategory(this.getAttribute('data-category'));
    });
  });

  async function searchBooksByCategory(category) {
    showLoading(true);
    resultsDiv.innerHTML = '';
    
    try {
      // Google Books API kategori sorgusu
      const result = await window.api.searchBooks(`subject:${category}`, { 
        maxResults: 16,
        langRestrict: 'tr'
      });
      
      showLoading(false);
      
      if (result.success) {
        if (result.data.books && result.data.books.length > 0) {
          renderResults(result.data.books);
          
          // Sonuçlara yumuşak scroll
          setTimeout(() => {
            const resultsContainer = document.getElementById('results');
            if (resultsContainer) {
              resultsContainer.scrollIntoView({ 
                behavior: 'smooth', 
                block: 'start' 
              });
            }
          }, 100);
        } else {
          showMessage(`'${getCategoryDisplayName(category)}' kategorisinde kitap bulunamadı. Başka bir kategori deneyin.`);
        }
      } else {
        showMessage('Kategori araması sırasında hata oluştu: ' + result.error);
      }
    } catch (error) {
      showLoading(false);
      showMessage('Kategori araması sırasında hata oluştu. Lütfen tekrar deneyin.');
    }
  }
  
  // Kategori adlarını Türkçe'ye çevir
  function getCategoryDisplayName(category) {
    const categoryNames = {
      'fiction': 'Roman',
      'history': 'Tarih',
      'science': 'Bilim',
      'philosophy': 'Felsefe',
      'juvenile-fiction': 'Çocuk Kitapları',
      'art': 'Sanat',
      'psychology': 'Psikoloji',
      'self-help': 'Kişisel Gelişim'
    };
    return categoryNames[category] || category;
  }

  // API fonksiyonları - Backend ile entegre
  async function saveBookToLibrary(book, buttonElement) {
    console.log('saveBookToLibrary çağrıldı:', book);
    
    // Oturum kontrolünü backend'e bırak
    
    buttonElement.disabled = true;
    buttonElement.innerHTML = '<i class="fas fa-spinner fa-spin"></i>Kaydediliyor...';
    
          try {
        // CSRF token al - opsiyonel
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || 
                         Math.random().toString(36).substr(2, 15); // fallback token
        
        // Backend API'ye kitap kaydet
        const bookData = {
          title: book.title || 'Bilinmeyen Başlık',
          authors: Array.isArray(book.authors) ? book.authors : (book.authors ? [book.authors] : []),
          description: book.description || '',
          thumbnail: book.thumbnail || '',
          infoLink: book.infoLink || '',
          pdf: book.pdf || null,
          google_id: book.google_id || book.id || Math.random().toString(36).substr(2, 9),
          isbn: book.isbn || '',
          pageCount: book.pageCount || 0,
          publishedDate: book.publishedDate || '',
          language: book.language || 'tr',
          categories: Array.isArray(book.categories) ? book.categories : [],
          csrf_token: csrfToken
        };

      console.log('Gönderilecek kitap verisi:', bookData);

      const response = await fetch('library.php?action=save', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'include',
        body: JSON.stringify(bookData)
      });

      console.log('API yanıt durumu:', response.status);
      
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      }

      const result = await response.json();
      console.log('API yanıtı:', result);
      
      if (result.success) {
        buttonElement.innerHTML = '<i class="fas fa-check"></i>Kaydedildi';
        buttonElement.classList.remove('save-btn');
        buttonElement.classList.add('saved-btn', 'cursor-not-allowed', 'opacity-75');
        buttonElement.style.backgroundColor = '#10b981';
        
        if (window.showMessage) {
          window.showMessage('Kitap kütüphanenize eklendi!');
        } else {
          alert('Kitap kütüphanenize eklendi!');
        }
        
        // Kaydedilmiş kitapları yeniden kontrol et
        setTimeout(checkSavedBooks, 1000);
      } else {
        throw new Error(result.error || 'Bilinmeyen hata');
      }
    } catch (error) {
      console.error('Kitap kaydetme hatası:', error);
      
      // 409 Conflict - Kitap zaten kaydedilmiş
      if (error.message.includes('409') || error.message.includes('zaten kütüphanenizde')) {
        buttonElement.innerHTML = '<i class="fas fa-check"></i>Zaten Kaydedilmiş';
        buttonElement.classList.remove('save-btn');
        buttonElement.classList.add('saved-btn', 'cursor-not-allowed', 'opacity-75');
        buttonElement.style.backgroundColor = '#10b981';
        buttonElement.disabled = true;
        
        if (window.showMessage) {
          window.showMessage('Bu kitap zaten kütüphanenizde!');
        }
        
        // Sessizce checkSavedBooks çağır
        setTimeout(checkSavedBooks, 500);
      } else {
        // Diğer hatalar
        buttonElement.innerHTML = '<i class="fas fa-exclamation-triangle"></i>Hata!';
        buttonElement.style.backgroundColor = '#ef4444';
        
        if (window.showError) {
          window.showError('Kitap kaydedilemedi: ' + error.message);
        } else {
          alert('Kitap kaydedilemedi: ' + error.message);
        }
        
        setTimeout(() => {
          buttonElement.innerHTML = '<i class="fas fa-bookmark"></i>Kaydet';
          buttonElement.style.backgroundColor = '';
          buttonElement.disabled = false;
        }, 3000);
      }
    }
  }
  
  async function checkSavedBooks() {
    const saveButtons = document.querySelectorAll('.save-btn');
    if (saveButtons.length === 0) return;
    
    try {
      // Kullanıcının kütüphanesini al
      const response = await fetch('library.php?action=list', {
        method: 'GET',
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'include'
      });

      if (!response.ok) {
        console.warn('Kütüphane listesi alınamadı:', response.status);
        return;
      }

      const result = await response.json();
      
      if (result.success && result.data && result.data.books) {
        // Farklı ID formatlarını kontrol et
        const savedBookIds = new Set();
        result.data.books.forEach(book => {
          if (book.book_google_id) savedBookIds.add(book.book_google_id);
          if (book.book_title) savedBookIds.add(book.book_title);
          if (book.id) savedBookIds.add(book.id.toString());
        });
        
        saveButtons.forEach(btn => {
          try {
            const rawData = btn.getAttribute('data-book');
            if (!rawData) {
              console.warn('Buton data-book attribute\'u yok');
              return;
            }
            
            const book = JSON.parse(decodeURIComponent(rawData));
            
            // Farklı ID formatlarını kontrol et
            const possibleIds = [
              book.google_id,
              book.id, 
              book.title,
              book.googleId
            ].filter(Boolean);
            
            const isAlreadySaved = possibleIds.some(id => savedBookIds.has(id));
            
            if (isAlreadySaved) {
              btn.innerHTML = '<i class="fas fa-check"></i>Kaydedildi';
              btn.disabled = true;
              btn.classList.remove('save-btn');
              btn.classList.add('saved-btn', 'cursor-not-allowed', 'opacity-75');
              btn.style.backgroundColor = '#10b981';
            }
          } catch (error) {
            console.error('Kitap verisi işlenirken hata:', error, 'Raw data:', rawData.substring(0, 100));
          }
        });
      } else {
        console.warn('Kütüphane verisi alınamadı:', result);
      }
    } catch (error) {
      console.error('checkSavedBooks hatası:', error);
    }
  }
  
  async function removeBookFromLibrary(bookId, buttonElement) {
    if (!window.api.currentUser) {
      window.api.showError('Kitap kaldırmak için giriş yapmalısınız');
      return;
    }
    
    if (!confirm('Bu kitabı kütüphanenizden kaldırmak istediğinizden emin misiniz?')) {
      return;
    }
    
    buttonElement.disabled = true;
    buttonElement.innerHTML = '<i class="fas fa-spinner fa-spin"></i>Kaldırılıyor...';
    
    try {
      const result = await window.api.removeBook(bookId);
      
      if (result.success) {
        // Kitabı DOM'dan kaldır
        const bookCard = buttonElement.closest('.book-card');
        if (bookCard) {
          bookCard.style.transition = 'all 0.5s ease';
          bookCard.style.opacity = '0';
          bookCard.style.transform = 'scale(0.8)';
          setTimeout(() => bookCard.remove(), 500);
        }
        
        window.api.showSuccess('Kitap kütüphanenizden kaldırıldı');
        
        // Kütüphane boşsa mesaj göster
        setTimeout(() => {
          if (resultsDiv.children.length === 0) {
            showMessage('Kütüphaneniz boş. Yeni kitaplar ekleyebilirsiniz!');
          }
        }, 600);
      } else {
        throw new Error(result.error);
      }
    } catch (error) {
      console.error('Remove Book Error:', error);
      window.api.showError('Kitap kaldırılırken hata oluştu: ' + error.message);
      buttonElement.disabled = false;
      buttonElement.innerHTML = '<i class="fas fa-trash"></i>Kaldır';
    }
  }

  // Giriş formu - Backend API entegreli
  if (loginForm) {
    loginForm.addEventListener('submit', async function(e) {
      e.preventDefault();
      const email = document.getElementById('login-email').value;
      const password = document.getElementById('login-password').value;
      
      if (!email || !password) {
        window.api.showError('E-posta ve şifre gerekli');
        return;
      }
      
      const submitBtn = this.querySelector('button[type="submit"]');
      submitBtn.disabled = true;
      const originalText = submitBtn.textContent;
      submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Giriş yapılıyor...';
      
      try {
        const result = await window.api.login({
          email: email,
          password: password
        });
        
        if (result.success) {
          window.api.showSuccess(`Hoşgeldin, ${result.data.user.name}!`);
          updateUserInfo();
          // API Manager UI'yi otomatik günceller
        } else {
          window.api.showError(result.error || 'Giriş başarısız');
        }
      } catch (error) {
        console.error('Login Error:', error);
        window.api.showError('Giriş sırasında bir hata oluştu');
      } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
      }
    });
  }

  // Kayıt formu - Backend API entegreli
  if (signupForm) {
    signupForm.addEventListener('submit', async function(e) {
      e.preventDefault();
      const name = document.getElementById('signup-name').value;
      const email = document.getElementById('signup-email').value;
      const password = document.getElementById('signup-password').value;
      const confirmPassword = document.getElementById('signup-confirm-password')?.value || password;
      
      if (!name || !email || !password) {
        window.api.showError('Tüm alanlar gerekli');
        return;
      }
      
      const submitBtn = this.querySelector('button[type="submit"]');
      submitBtn.disabled = true;
      const originalText = submitBtn.textContent;
      submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Kayıt olunuyor...';
      
      try {
        const result = await window.api.register({
          name: name,
          email: email,
          password: password,
          confirmPassword: confirmPassword
        });
        
        if (result.success) {
          window.api.showSuccess(`Hoşgeldin, ${result.data.user.name}! Hesabınız oluşturuldu.`);
          updateUserInfo();
          // API Manager UI'yi otomatik günceller
        } else {
          window.api.showError(result.error || 'Kayıt sırasında hata oluştu');
        }
      } catch (error) {
        console.error('Register Error:', error);
        window.api.showError('Kayıt sırasında bir hata oluştu');
      } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
      }
    });
  }

  // Kütüphane ve Ana Sayfa butonları - Backend API entegreli
  const libraryBtn = document.getElementById('library-btn');
  if (libraryBtn) {
    libraryBtn.addEventListener('click', async function(e) {
      e.preventDefault();
      
      if (!window.api?.currentUser) {
        window.api?.showError('Kütüphanenizi görüntülemek için giriş yapmalısınız');
        return;
      }
      
      showLoading(true);
      
      try {
        const result = await window.api.getLibrary();
        showLoading(false);
        
        if (result.success) {
          if (result.data.books && result.data.books.length > 0) {
            // Kütüphane için özel render fonksiyonu çağır
            renderLibraryResults(result.data.books);
            
            // Sonuçlara yumuşak scroll
            setTimeout(() => {
              const resultsContainer = document.getElementById('results');
              resultsContainer.scrollIntoView({ 
                behavior: 'smooth', 
                block: 'start' 
              });
            }, 100);
            
          } else {
            // Boş kütüphane mesajı ile başlık
            const resultsContainer = document.getElementById('results');
            resultsContainer.innerHTML = `
              <div class="text-center mb-8 p-6 bg-black/40 rounded-2xl backdrop-blur-md border border-white/20">
                <h2 class="text-3xl hero-text-strong mb-4">📚 Kütüphanem</h2>
                <div class="bg-black/30 backdrop-blur-md rounded-2xl p-8 max-w-md mx-auto border border-white/30">
                  <div class="text-6xl mb-4">📖</div>
                  <h3 class="text-xl text-white-super-readable font-bold mb-2">Kütüphaneniz Boş</h3>
                  <p class="text-white-super-readable font-semibold mb-4">Henüz hiç kitap kaydetmediniz. Kitap arayarak kütüphanenizi oluşturmaya başlayın!</p>
                  <button onclick="document.getElementById('search-input').focus()" 
                          class="bg-blue-600 hover:bg-blue-700 text-white-super-readable px-6 py-3 rounded-xl font-bold shadow-lg transition-all">
                    Kitap Aramaya Başla
                  </button>
                </div>
              </div>
            `;
            
            // Sonuçlara scroll
            setTimeout(() => {
              resultsContainer.scrollIntoView({ 
                behavior: 'smooth', 
                block: 'start' 
              });
            }, 100);
          }
        } else {
          showMessage(result.error || 'Kütüphane yüklenirken hata oluştu');
        }
      } catch (error) {
        showLoading(false);
        console.error('Library Error:', error);
        showMessage('Kütüphane yüklenirken hata oluştu');
      }
      
      // Aktif buton vurgusu
      this.classList.add('bg-blue-600', 'text-white', 'shadow');
      const homeBtn = document.getElementById('home-btn');
      if (homeBtn) homeBtn.classList.remove('bg-blue-600', 'text-white', 'shadow');
    });
  }
  const homeBtn = document.getElementById('home-btn');
  if (homeBtn) {
    homeBtn.addEventListener('click', function(e) {
      e.preventDefault();
      resultsDiv.innerHTML = '';
      showMessage('Arama yapmak için yukarıdan kitap veya yazar girin.');
      this.classList.add('bg-blue-600', 'text-white', 'shadow');
      const libraryBtn = document.getElementById('library-btn');
      if (libraryBtn) libraryBtn.classList.remove('bg-blue-600', 'text-white', 'shadow');
    });
  }

  // Çıkış butonu - Backend API entegreli
  if (logoutBtn && !logoutBtn.hasAttribute('data-listener-added')) {
    console.log('✅ Çıkış butonu bulundu, event listener ekleniyor');
    logoutBtn.setAttribute('data-listener-added', 'true');
    logoutBtn.addEventListener('click', async function(e) {
      e.preventDefault();
      console.log('🔍 Çıkış butonuna tıklandı!');
      
      try {
        await window.api.logout();
        window.api.showSuccess('Başarıyla çıkış yaptınız');
        
        // Formları temizle
        if (loginForm) loginForm.reset();
        if (signupForm) signupForm.reset();
        
        // Sonuçları temizle
        resultsDiv.innerHTML = '';
      } catch (error) {
        console.error('Logout Error:', error);
        window.api.showError('Çıkış sırasında bir hata oluştu');
      }
    });
  }

  // Giriş/Kayıt geçişi
  if (toggleAuth) {
    toggleAuth.addEventListener('click', function(e) {
      e.preventDefault();
      isLogin = !isLogin;
      if (isLogin) {
        if (loginForm) loginForm.classList.remove('hidden');
        if (signupForm) signupForm.classList.add('hidden');
        if (authTitle) authTitle.textContent = 'Giriş Yap';
        if (authDesc) authDesc.textContent = "OMCN HUB'a hoş geldin! Lütfen giriş yap veya kayıt ol.";
        toggleAuth.innerHTML = 'Hesabın yok mu? <a href="#" class="text-blue-600 font-semibold hover:underline">Kayıt Ol</a>';
      } else {
        if (loginForm) loginForm.classList.add('hidden');
        if (signupForm) signupForm.classList.remove('hidden');
        if (authTitle) authTitle.textContent = 'Kayıt Ol';
        if (authDesc) authDesc.textContent = "OMCN HUB'a katıl, binlerce e-kitaba ulaş!";
        toggleAuth.innerHTML = 'Zaten hesabın var mı? <a href="#" class="text-blue-600 font-semibold hover:underline">Giriş Yap</a>';
      }
    });
  }

  // Hakkında modalı
  if (aboutBtn) {
    aboutBtn.addEventListener('click', function(e) {
      e.preventDefault();
      if (aboutModal) aboutModal.classList.remove('hidden');
    });
  }
  
  if (closeAboutModal) {
    closeAboutModal.addEventListener('click', function() {
      if (aboutModal) aboutModal.classList.add('hidden');
    });
  }

  // Kullanıcı menü işlevleri
  if (userMenuBtn && userMenu && !userMenuBtn.hasAttribute('data-listener-added')) {
    console.log('✅ User menu butonları bulundu, event listener ekleniyor');
    userMenuBtn.setAttribute('data-listener-added', 'true');
    userMenuBtn.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      console.log('🔍 User menu butonuna tıklandı!');
      userMenu.classList.toggle('hidden');
    });

    // Menü dışına tıklandığında menüyü kapat
    document.addEventListener('click', function(e) {
      if (!userMenuBtn.contains(e.target) && !userMenu.contains(e.target)) {
        userMenu.classList.add('hidden');
      }
    });
  }

  // Şifre değiştirme modalı işlevleri
  if (changePasswordBtn) {
    changePasswordBtn.addEventListener('click', function(e) {
      e.preventDefault();
      if (userMenu) userMenu.classList.add('hidden');
      if (changePasswordModal) changePasswordModal.classList.remove('hidden');
    });
  }

  if (closeChangePasswordModal) {
    closeChangePasswordModal.addEventListener('click', function() {
      if (changePasswordModal) changePasswordModal.classList.add('hidden');
      resetPasswordForm();
    });
  }

  // Modal arka planına tıklandığında kapat
  if (changePasswordModal) {
    changePasswordModal.addEventListener('click', function(e) {
      if (e.target === changePasswordModal) {
        changePasswordModal.classList.add('hidden');
        resetPasswordForm();
      }
    });
  }

  if (cancelPasswordChange) {
    cancelPasswordChange.addEventListener('click', function() {
      if (changePasswordModal) changePasswordModal.classList.add('hidden');
      resetPasswordForm();
    });
  }

  // Şifre değiştirme formu
  if (changePasswordForm) {
    changePasswordForm.addEventListener('submit', function(e) {
      e.preventDefault();
      
      const currentPassword = document.getElementById('current-password').value;
      const newPassword = document.getElementById('new-password').value;
      const confirmPassword = document.getElementById('confirm-new-password').value;
      const errorDiv = document.getElementById('password-error');
      const successDiv = document.getElementById('password-success');
      
      // Hata ve başarı mesajlarını temizle
      hidePasswordMessages();
      
      // Validasyon
      if (!currentPassword || !newPassword || !confirmPassword) {
        showPasswordError('Tüm alanları doldurun');
        return;
      }
      
      if (newPassword.length < 6) {
        showPasswordError('Yeni şifre en az 6 karakter olmalı');
        return;
      }
      
      if (newPassword !== confirmPassword) {
        showPasswordError('Yeni şifreler eşleşmiyor');
        return;
      }
      
      if (currentPassword === newPassword) {
        showPasswordError('Yeni şifre mevcut şifreden farklı olmalı');
        return;
      }
      
      const submitBtn = this.querySelector('button[type="submit"]');
      submitBtn.disabled = true;
      const originalText = submitBtn.textContent;
      submitBtn.textContent = 'Değiştiriliyor...';
      
      fetch('auth.php?action=change-password', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          currentPassword: currentPassword,
          newPassword: newPassword,
          confirmPassword: confirmPassword
        })
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          showPasswordSuccess(data.message || 'Şifre başarıyla değiştirildi');
          setTimeout(() => {
            changePasswordModal.classList.add('hidden');
            resetPasswordForm();
          }, 2000);
        } else {
          showPasswordError(data.error || 'Şifre değiştirme başarısız');
        }
      })
      .catch(error => {
        console.error('Error:', error);
        showPasswordError('Şifre değiştirme sırasında hata oluştu');
      })
      .finally(() => {
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
      });
    });
  }

  function resetPasswordForm() {
    if (changePasswordForm) {
      changePasswordForm.reset();
      hidePasswordMessages();
    }
  }

  function showPasswordError(message) {
    const errorDiv = document.getElementById('password-error');
    const successDiv = document.getElementById('password-success');
    if (errorDiv) {
      errorDiv.textContent = message;
      errorDiv.classList.remove('hidden');
    }
    if (successDiv) {
      successDiv.classList.add('hidden');
    }
  }

  function showPasswordSuccess(message) {
    const errorDiv = document.getElementById('password-error');
    const successDiv = document.getElementById('password-success');
    if (successDiv) {
      successDiv.textContent = message;
      successDiv.classList.remove('hidden');
    }
    if (errorDiv) {
      errorDiv.classList.add('hidden');
    }
  }

  function hidePasswordMessages() {
    const errorDiv = document.getElementById('password-error');
    const successDiv = document.getElementById('password-success');
    if (errorDiv) errorDiv.classList.add('hidden');
    if (successDiv) successDiv.classList.add('hidden');
  }

  // Profil modalı işlevselliği  
  if (profileBtn && profileModal && !profileBtn.hasAttribute('data-listener-added')) {
    console.log('✅ Profil butonları bulundu, event listener ekleniyor');
    profileBtn.setAttribute('data-listener-added', 'true');
    profileBtn.addEventListener('click', function(e) {
      e.preventDefault();
      console.log('🔍 Profil butonuna tıklandı!');
      if (userMenu) userMenu.classList.add('hidden'); // Kullanıcı menüsünü kapat
      loadProfileData();
      profileModal.classList.remove('hidden');
    });

    if (closeProfileModal) {
      closeProfileModal.addEventListener('click', function() {
        profileModal.classList.add('hidden');
      });
    }

    if (cancelProfileEdit) {
      cancelProfileEdit.addEventListener('click', function() {
        profileModal.classList.add('hidden');
      });
    }

    // Modal dışına tıklayınca kapat
    profileModal.addEventListener('click', function(e) {
      if (e.target === profileModal) {
        profileModal.classList.add('hidden');
      }
    });

    // Profil formu gönderme
    if (profileForm) {
      profileForm.addEventListener('submit', function(e) {
        e.preventDefault();
        updateProfile();
      });
    }
  }

  // Profil verilerini yükleme
  function loadProfileData() {
    if (window.currentUser) {
      const profileName = document.getElementById('profile-name');
      const profileEmail = document.getElementById('profile-email');
      const profileJoinDate = document.getElementById('profile-join-date');
      const savedBooksCount = document.getElementById('saved-books-count');
      
      if (profileName) profileName.value = window.currentUser.name || '';
      if (profileEmail) profileEmail.value = window.currentUser.email || '';
      if (profileJoinDate) {
        const joinDate = window.currentUser.created_at ? 
          new Date(window.currentUser.created_at).toLocaleDateString('tr-TR') : 
          'Bilinmiyor';
        profileJoinDate.value = joinDate;
      }
      
      // Kaydedilen kitap sayısını güncelle
      updateProfileStats();
    }
  }

  // Profil istatistiklerini güncelleme
  function updateProfileStats() {
    const savedBooksCount = document.getElementById('saved-books-count');
    const readingTime = document.getElementById('reading-time');
    
    if (savedBooksCount) {
      // Kullanıcının kaydedilen kitap sayısını al
      fetch('library.php?action=count')
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            savedBooksCount.textContent = data.count || 0;
          }
        })
        .catch(error => {
          console.log('Kitap sayısı alınamadı:', error);
          savedBooksCount.textContent = '0';
        });
    }
    
    if (readingTime) {
      // Basit bir okuma süresi hesaplama (örnek olarak)
      const hours = Math.floor(Math.random() * 50) + 1; // Gerçek uygulama için tracking gerekli
      readingTime.textContent = hours;
    }
  }

  // Profil güncellemesi
  function updateProfile() {
    const profileName = document.getElementById('profile-name');
    const errorDiv = document.getElementById('profile-error');
    const successDiv = document.getElementById('profile-success');
    
    // Hata ve başarı mesajlarını temizle
    hideProfileMessages();
    
    if (!profileName || !profileName.value.trim()) {
      showProfileError('Ad soyad alanı boş bırakılamaz');
      return;
    }
    
    const submitBtn = profileForm.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    const originalText = submitBtn.textContent;
    submitBtn.textContent = 'Kaydediliyor...';
    
    fetch('auth.php?action=update-profile', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        name: profileName.value.trim()
      })
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showProfileSuccess(data.message || 'Profil başarıyla güncellendi');
        // Kullanıcı bilgilerini güncelle
        if (window.currentUser) {
          window.currentUser.name = profileName.value.trim();
          updateUserInfo(); // Header'daki kullanıcı adını güncelle
        }
        setTimeout(() => {
          profileModal.classList.add('hidden');
        }, 2000);
      } else {
        showProfileError(data.error || 'Profil güncellenemedi');
      }
    })
    .catch(error => {
      console.error('Error:', error);
      showProfileError('Profil güncelleme sırasında hata oluştu');
    })
    .finally(() => {
      submitBtn.disabled = false;
      submitBtn.textContent = originalText;
    });
  }

  function showProfileError(message) {
    const errorDiv = document.getElementById('profile-error');
    const successDiv = document.getElementById('profile-success');
    if (errorDiv) {
      errorDiv.textContent = message;
      errorDiv.classList.remove('hidden');
    }
    if (successDiv) {
      successDiv.classList.add('hidden');
    }
  }

  function showProfileSuccess(message) {
    const errorDiv = document.getElementById('profile-error');
    const successDiv = document.getElementById('profile-success');
    if (successDiv) {
      successDiv.textContent = message;
      successDiv.classList.remove('hidden');
    }
    if (errorDiv) {
      errorDiv.classList.add('hidden');
    }
  }

  function hideProfileMessages() {
    const errorDiv = document.getElementById('profile-error');
    const successDiv = document.getElementById('profile-success');
    if (errorDiv) errorDiv.classList.add('hidden');
    if (successDiv) successDiv.classList.add('hidden');
  }

  // Filtre seçenekleri için event listener
  if (filterSelect) {
    filterSelect.addEventListener('change', function() {
      const selectedFilter = this.value;
      
      if (selectedFilter === 'En Yeniler') {
        showLoading(true);
        resultsDiv.innerHTML = '';
        
        // En yeni kitapları getir (2020 sonrası yayınlanan kitaplar)
        fetch('search.php?q=inpublisher:"2020" OR inpublisher:"2021" OR inpublisher:"2022" OR inpublisher:"2023" OR inpublisher:"2024" OR inpublisher:"2025"')
          .then(res => res.json())
          .then(data => {
            showLoading(false);
            if (!data || !Array.isArray(data.books)) {
              showMessage('API veri hatası.');
              return;
            }
            // Yayın tarihine göre sırala (en yeniden eskiye)
            const sortedBooks = data.books.sort((a, b) => {
              const yearA = a.publishedDate ? new Date(a.publishedDate).getFullYear() : 0;
              const yearB = b.publishedDate ? new Date(b.publishedDate).getFullYear() : 0;
              return yearB - yearA;
            });
            renderResults(sortedBooks);
          })
          .catch(() => {
            showLoading(false);
            showMessage('API erişim hatası. Lütfen tekrar deneyin.');
          });
      } else if (selectedFilter === 'En Çok Okunanlar') {
        showLoading(true);
        resultsDiv.innerHTML = '';
        
        // Popüler kitapları getir
        fetch('search.php?q=subject:bestseller OR inauthor:"bestselling"')
          .then(res => res.json())
          .then(data => {
            showLoading(false);
            if (!data || !Array.isArray(data.books)) {
              showMessage('API veri hatası.');
              return;
            }
            renderResults(data.books);
          })
          .catch(() => {
            showLoading(false);
            showMessage('API erişim hatası. Lütfen tekrar deneyin.');
          });
      } else if (selectedFilter === 'En Yüksek Puan') {
        showLoading(true);
        resultsDiv.innerHTML = '';
        
        // Yüksek puanlı kitapları getir
        fetch('search.php?q=averageRating:4 OR averageRating:5')
          .then(res => res.json())
          .then(data => {
            showLoading(false);
            if (!data || !Array.isArray(data.books)) {
              showMessage('API veri hatası.');
              return;
            }
            // Rating'e göre sırala
            const sortedBooks = data.books.sort((a, b) => {
              const ratingA = a.averageRating || 0;
              const ratingB = b.averageRating || 0;
              return ratingB - ratingA;
            });
            renderResults(sortedBooks);
          })
          .catch(() => {
            showLoading(false);
            showMessage('API erişim hatası. Lütfen tekrar deneyin.');
          });
      }
    });
  }

  // Sayfa yüklendiğinde en yeni kitapları göster
  window.addEventListener('load', function() {
    if (filterSelect && window.currentUser) {
      setTimeout(() => {
        filterSelect.value = 'En Yeniler';
        filterSelect.dispatchEvent(new Event('change'));
      }, 500);
    }
  });

  // Yardımcı: aktif arama kutusunu bul
  function getSearchInput() {
    return document.getElementById('search-input');
  }

  // Gelişmiş arama fonksiyonu
  window.performAdvancedSearch = async function(query) {
    if (!query) {
      console.error('❌ Query boş!');
      return;
    }
    
    console.log('🔍 performAdvancedSearch başladı:', query);
    
    showLoading(true);
    const resultsDiv = document.getElementById('results');
    if (resultsDiv) {
      resultsDiv.innerHTML = '';
    }
    
    // Tüm filtreleri al
    const sortValue = getCurrentSortValue();
    const langValue = getCurrentLanguageValue();
    const yearValue = getCurrentYearValue();
    const pagesValue = getCurrentPagesValue();
    
    console.log('🔍 Filtreler:', { query, sortValue, langValue, yearValue, pagesValue });
    
    // Google Books API parametrelerini hazırla
    const searchParams = {
      maxResults: 20,
      orderBy: getSortOrderBy(sortValue),
      langRestrict: langValue === 'all' ? undefined : langValue
    };
    
    // Yıl filtresi ekle
    let searchQuery = query;
    if (yearValue !== 'all') {
      searchQuery = buildQueryWithFilters(query, yearValue, pagesValue);
    }
    
    // Özel filtreler ekle
    searchQuery = applySpecialFilters(searchQuery, sortValue);
    
    console.log('🔍 Final search query:', searchQuery);
    console.log('🔍 Search params:', searchParams);
    
    try {
      if (!window.api) {
        throw new Error('window.api bulunamadı!');
      }
      
      console.log('🔍 API çağrısı yapılıyor...');
      const result = await window.api.searchBooks(searchQuery, searchParams);
      
      console.log('🔍 API sonucu:', result);
      
      showLoading(false);
      
      if (result.success && result.data && result.data.books) {
        let filteredBooks = result.data.books;
        
        console.log('🔍 Bulunan kitap sayısı:', filteredBooks.length);
        
        // Sayfa sayısı filtresini uygula (client-side)
        if (pagesValue !== 'all') {
          filteredBooks = filterBooksByPages(filteredBooks, pagesValue);
          console.log('🔍 Filtreden sonra kitap sayısı:', filteredBooks.length);
        }
        
        if (filteredBooks.length > 0) {
          console.log('✅ Sonuçlar render ediliyor...');
          renderResults(filteredBooks);
          
          // Sonuçlara yumuşak scroll
          setTimeout(() => {
            const resultsContainer = document.getElementById('results');
            if (resultsContainer) {
              resultsContainer.scrollIntoView({ 
                behavior: 'smooth', 
                block: 'start' 
              });
            }
          }, 100);
        } else {
          console.log('❌ Filtrelenmiş sonuç yok');
          showMessage(`Seçtiğiniz filtrelerle kitap bulunamadı. Filtreleri değiştirmeyi deneyin.`);
        }
      } else {
        console.error('❌ API başarısız veya data yok:', result);
        showMessage(`Arama sonucu bulunamadı. Hata: ${result.error || 'Bilinmeyen hata'}`);
      }
    } catch (error) {
      console.error('❌ Arama hatası:', error);
      showLoading(false);
      showMessage(`Arama sırasında hata oluştu: ${error.message}`);
    }
  };

  // Event listener'ları başlat
  function initEventListeners() {
    // Kategori butonları
    const categoryBtns = document.querySelectorAll('.category-btn');
    console.log('✅ Kategori butonları bulundu:', categoryBtns.length);
    
    categoryBtns.forEach(btn => {
      btn.addEventListener('click', function(e) {
        e.preventDefault();
        const category = this.getAttribute('data-category');
        console.log('🔍 Kategori arama:', category);
        
        if (category) {
          // Kategori ismini Türkçe'ye çevir
          const categoryMap = {
            'fiction': 'roman',
            'history': 'tarih',
            'science': 'bilim',
            'philosophy': 'felsefe',
            'juvenile-fiction': 'çocuk kitapları',
            'art': 'sanat',
            'psychology': 'psikoloji',
            'self-help': 'kişisel gelişim'
          };
          
          const searchTerm = categoryMap[category] || category;
          performAdvancedSearch(searchTerm);
        }
      });
    });

    // Popüler arama butonları
    const popularSearchBtns = document.querySelectorAll('.popular-search-btn');
    console.log('✅ Popüler arama butonları bulundu:', popularSearchBtns.length);
    
    popularSearchBtns.forEach(btn => {
      btn.addEventListener('click', function(e) {
        e.preventDefault();
        const searchTerm = this.getAttribute('data-search');
        console.log('🔍 Popüler arama:', searchTerm);
        
        if (searchTerm) {
          performAdvancedSearch(searchTerm);
        }
      });
    });

    // Ana arama butonu
    const mainSearchBtn = document.getElementById('search-btn');
    const searchInputEl = document.getElementById('search-input');
    
    // Önceki event listener'ları temizle
    if (mainSearchBtn) {
      mainSearchBtn.replaceWith(mainSearchBtn.cloneNode(true));
      const newSearchBtn = document.getElementById('search-btn');
      
      newSearchBtn.addEventListener('click', function(e) {
        e.preventDefault();
        console.log('🔍 Arama butonuna tıklandı!');
        
        const query = searchInputEl ? searchInputEl.value.trim() : '';
        console.log('🔍 Arama terimi:', query);
        
        if (!query) {
          showMessage('Lütfen bir arama terimi girin!');
          return;
        }
        
        performAdvancedSearch(query);
      });
    } else {
      console.error('❌ Ana arama butonu bulunamadı!');
    }
    
    // Enter tuşu ile arama
    if (searchInputEl) {
      searchInputEl.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          const query = this.value.trim();
          console.log('🔍 Enter ile arama:', query);
          
          if (!query) {
            showMessage('Lütfen bir arama terimi girin!');
            return;
          }
          
          performAdvancedSearch(query);
        }
      });
    } else {
      console.error('❌ Search input bulunamadı!');
    }
  }

  // require() tanımı en başta yapıldı

  // Event listener'ları DOM yüklendikten sonra başlat
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initEventListeners);
  } else {
    initEventListeners();
  }

  // Yardımcı fonksiyonlar
  function getCurrentSortValue() {
    const selectedOption = document.querySelector('[data-dropdown="sort"]')?.parentElement?.querySelector('.dropdown-option.selected');
    return selectedOption?.getAttribute('data-value') || 'newest';
  }

  function getCurrentLanguageValue() {
    const selectedOption = document.querySelector('[data-dropdown="language"]')?.parentElement?.querySelector('.dropdown-option.selected');
    return selectedOption?.getAttribute('data-value') || 'all';
  }

  function getCurrentYearValue() {
    const selectedOption = document.querySelector('[data-dropdown="year"]')?.parentElement?.querySelector('.dropdown-option.selected');
    return selectedOption?.getAttribute('data-value') || 'all';
  }

  function getCurrentPagesValue() {
    const selectedOption = document.querySelector('[data-dropdown="pages"]')?.parentElement?.querySelector('.dropdown-option.selected');
    return selectedOption?.getAttribute('data-value') || 'all';
  }

  // PDF arama fonksiyonu - ai-smart-search.js'de tanımlanmış
  // window.searchGoogleBooksForPDF kullanılacak

  // Kitap sohbeti fonksiyonu
  async function openBookChat(book) {
    try {
      // Rate limit kontrolü
      const cacheKey = `chat_${book.id}_${Date.now()}`;
      const lastChat = localStorage.getItem(cacheKey);
      
      if (lastChat && (Date.now() - parseInt(lastChat)) < 60000) { // 1 dakika
        throw new Error('Çok sık sohbet isteği gönderdiniz. Lütfen biraz bekleyin.');
      }
      
      localStorage.setItem(cacheKey, Date.now().toString());

      // Kitap ID'sini kontrol et
      const bookId = book.id || book.google_id;
      if (!bookId) {
        throw new Error('Kitap ID bulunamadı');
      }

      // Varolan popup'ları temizle
      const existingPopup = document.querySelector('.chat-popup');
      const existingOverlay = document.querySelector('.chat-popup-overlay');
      if (existingPopup) existingPopup.remove();
      if (existingOverlay) existingOverlay.remove();

      // Popup HTML oluştur
      const popupHTML = `
        <div class="chat-popup-overlay" onclick="closePopup()"></div>
        <div class="chat-popup">
          <div class="chat-popup-header">
            <h3 class="chat-popup-title">${book.title} - Kitap Sohbeti</h3>
            <button class="chat-popup-close" onclick="closePopup()">
              <i class="fas fa-times"></i>
            </button>
          </div>
          <div class="chat-popup-content">
            <p class="text-gray-600 mb-4">Bu kitap hakkında sohbet etmek ister misiniz?</p>
            <div class="flex flex-col gap-3">
              <div class="flex items-center gap-2">
                <i class="fas fa-book text-indigo-500"></i>
                <span class="font-medium">${book.title}</span>
              </div>
              ${book.authors ? `
                <div class="flex items-center gap-2">
                  <i class="fas fa-user-edit text-indigo-500"></i>
                  <span>${book.authors}</span>
                </div>
              ` : ''}
            </div>
          </div>
          <div class="chat-popup-actions">
            <button class="action-btn chat-btn" onclick="startBookChat('${bookId}', '${book.title.replace(/'/g, "\\'")}')">
              <i class="fas fa-comments"></i>
              <span>Sohbete Başla</span>
            </button>
            <button class="action-btn" onclick="closePopup()" style="background: #64748b;">
              <i class="fas fa-times"></i>
              <span>Vazgeç</span>
            </button>
          </div>
        </div>
      `;
      
      // Popup'ı ekle ve göster
      document.body.insertAdjacentHTML('beforeend', popupHTML);
      
      // Animasyon için timeout
      setTimeout(() => {
        document.querySelector('.chat-popup-overlay').classList.add('show');
        document.querySelector('.chat-popup').classList.add('show');
      }, 10);

      // ESC tuşu ile kapatma
      document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
          closePopup();
        }
      });

    } catch (error) {
      showError(error.message);
    }
  }

  // Popup kapatma fonksiyonu
  window.closePopup = function() {
    const popup = document.querySelector('.chat-popup');
    const overlay = document.querySelector('.chat-popup-overlay');
    
    if (popup && overlay) {
      popup.classList.remove('show');
      overlay.classList.remove('show');
      
      setTimeout(() => {
        popup.remove();
        overlay.remove();
      }, 300);
    }
  };

  // Kitap sohbeti başlatma fonksiyonu
  window.startBookChat = async function(bookId, bookTitle = null) {
    try {
      if (!bookId) {
        throw new Error('Kitap ID bulunamadı');
      }

      // Popup'ı kapat
      closePopup();

      // Modern sohbet modal'ını oluştur
      const chatModal = createChatModal(bookId, bookTitle);
      document.body.appendChild(chatModal);
      
      // Modal'ı göster
      setTimeout(() => {
        chatModal.classList.add('show');
      }, 10);

    } catch (error) {
      showNotification(`Sohbet hatası: ${error.message}`, 'error');
    }
  };

  // Modern sohbet modalı oluştur
  function createChatModal(bookId, bookTitle = null) {
    const modal = document.createElement('div');
    modal.className = 'chat-modal-overlay';
    const displayTitle = bookTitle ? bookTitle : `Kitap ${bookId}`;
    modal.innerHTML = `
      <div class="chat-modal">
        <div class="chat-modal-header">
          <h3 class="chat-modal-title">🤖 ${displayTitle} - AI Sohbet</h3>
          <button class="chat-modal-close" onclick="closeChatModal()">
            <i class="fas fa-times"></i>
          </button>
        </div>
        <div class="chat-modal-content">
          <div class="chat-messages" id="chat-messages">
            <div class="bot-message">
              <div class="message-avatar">🤖</div>
              <div class="message-content">
                <p>Merhaba! Bu kitap hakkında ne sormak istiyorsunuz?</p>
              </div>
            </div>
          </div>
          <div class="chat-input-area">
            <div class="chat-input-container">
              <input type="text" id="chat-input" placeholder="Kitap hakkında bir şey sorun..." maxlength="500">
              <button id="send-message" onclick="sendChatMessage('${bookId}', '${displayTitle.replace(/'/g, "\\'")}')">
                <i class="fas fa-paper-plane"></i>
              </button>
            </div>
          </div>
        </div>
      </div>
    `;

    // Enter tuşu ile mesaj gönder
    const input = modal.querySelector('#chat-input');
    input.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendChatMessage(bookId, displayTitle);
      }
    });

    return modal;
  }

  // Sohbet modalını kapat
  window.closeChatModal = function() {
    const modal = document.querySelector('.chat-modal-overlay');
    if (modal) {
      modal.classList.remove('show');
      setTimeout(() => modal.remove(), 300);
    }
  };

  // Sohbet mesajı gönder
  window.sendChatMessage = async function(bookId, bookTitle = null) {
    const input = document.getElementById('chat-input');
    const message = input.value.trim();
    
    if (!message) return;

    try {
      // Kullanıcı mesajını ekle
      addChatMessage(message, 'user');
      input.value = '';
      
      // Yükleniyor mesajı ekle
      const loadingMsg = addChatMessage('Yanıt hazırlanıyor...', 'bot', true);
      
      // Form data oluştur (PHP application/x-www-form-urlencoded bekliyor)
      const formData = new FormData();
      const titleToSend = bookTitle ? bookTitle : `Kitap ${bookId}`;
      formData.append('title', titleToSend);
      formData.append('message', message);
      
      const response = await fetch('book_chat.php', {
        method: 'POST',
        body: formData
      });
      
      const result = await response.json();
      
      // Yükleniyor mesajını kaldır
      loadingMsg.remove();
      
      if (result.success) {
        addChatMessage(result.reply || result.response, 'bot');
      } else {
        addChatMessage('Üzgünüm, yanıt oluşturamadım. Lütfen tekrar deneyin.', 'bot');
      }
      
    } catch (error) {
      console.error('Sohbet hatası:', error);
      addChatMessage('Bağlantı hatası oluştu. Lütfen tekrar deneyin.', 'bot');
    }
  };

  // Sohbet mesajı ekle
  function addChatMessage(message, type, isLoading = false) {
    const messagesContainer = document.getElementById('chat-messages');
    const messageDiv = document.createElement('div');
    messageDiv.className = `${type}-message${isLoading ? ' loading' : ''}`;
    
    messageDiv.innerHTML = `
      <div class="message-avatar">${type === 'user' ? '👤' : '🤖'}</div>
      <div class="message-content">
        <p>${message}</p>
        ${isLoading ? '<div class="typing-indicator"><span></span><span></span><span></span></div>' : ''}
      </div>
    `;
    
    messagesContainer.appendChild(messageDiv);
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
    
    return messageDiv;
  };

  // Özel filtreler uygulama fonksiyonu
  function applySpecialFilters(query, sortValue) {
    if (!query || !sortValue) return query;

    switch (sortValue) {
      case 'bestseller':
        return `${query} bestseller`;
      case 'classic':
        return `${query} classic`;
      case 'popular':
        return `${query} popular`;
      case 'trending':
        return `${query} trending`;
      default:
        return query;
    }
  }

  // Sorgu string'ini filtrelerle oluştur
  function buildQueryWithFilters(baseQuery, yearValue, pagesValue) {
    let query = baseQuery;
    
    // Yıl filtreleri
    if (yearValue === '2024') {
      query += ' publishedDate:2024';
    } else if (yearValue === '2023') {
      query += ' publishedDate:2023';
    } else if (yearValue === '2020s') {
      query += ' publishedDate:2020..2024';
    } else if (yearValue === '2010s') {
      query += ' publishedDate:2010..2019';
    } else if (yearValue === 'classic') {
      query += ' publishedDate:1800..1999';
    }
    
    return query;
  }

  // Kitapları sayfa sayısına göre filtrele
  function filterBooksByPages(books, pagesValue) {
    if (!books || !Array.isArray(books)) return [];
    
    return books.filter(book => {
      const pageCount = book.pageCount || 0;
      
      switch (pagesValue) {
        case 'short':
          return pageCount > 0 && pageCount < 200;
        case 'medium':
          return pageCount >= 200 && pageCount <= 400;
        case 'long':
          return pageCount > 400 && pageCount <= 600;
        case 'epic':
          return pageCount > 600;
        default:
          return true;
      }
    });
  }

  // Sıralama değerini API formatına çevir
  function getSortOrderBy(sortValue) {
    switch (sortValue) {
      case 'newest':
        return 'newest';
      case 'oldest':
        return 'oldest';
      case 'relevance':
        return 'relevance';
      default:
        return 'newest';
    }
  }

  // Bildirim gösterme fonksiyonu
  function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `fixed top-4 right-4 p-4 rounded-lg shadow-lg text-white transition-all transform translate-y-0 opacity-100 z-50 ${
      type === 'error' ? 'bg-red-500' : 
      type === 'warning' ? 'bg-yellow-500' : 
      type === 'success' ? 'bg-green-500' : 
      'bg-indigo-500'
    }`;
    
    notification.innerHTML = `
      <div class="flex items-center">
        <i class="fas fa-${
          type === 'error' ? 'exclamation-circle' : 
          type === 'warning' ? 'exclamation-triangle' : 
          type === 'success' ? 'check-circle' : 
          'info-circle'
        } mr-2"></i>
        <span>${message}</span>
      </div>
    `;
    
    document.body.appendChild(notification);
    
    // 3 saniye sonra bildirimi kaldır
    setTimeout(() => {
      notification.classList.add('opacity-0', 'translate-y-2');
      setTimeout(() => notification.remove(), 300);
    }, 3000);
  }

  // Hata gösterme fonksiyonu
  function showError(message) {
    const errorDiv = document.createElement('div');
    errorDiv.className = 'fixed bottom-4 right-4 bg-red-500 text-white px-6 py-3 rounded-xl shadow-lg z-50';
    errorDiv.textContent = message;
    document.body.appendChild(errorDiv);
    setTimeout(() => errorDiv.remove(), 5000);
  }

  // Yükleniyor göstergesi - ai-smart-search.js'de tanımlanmış
  // window.showLoading kullanılacak
  
  // Mesaj gösterme fonksiyonu - ai-smart-search.js'de tanımlanmış  
  // window.showMessage kullanılacak

  // Global arama fonksiyonu
  window.performAISearch = async function(query) {
    try {
        if (window.showLoading) window.showLoading(true);
        const searchInput = document.getElementById('search-input');
        if (!searchInput) {
            throw new Error('Arama kutusu bulunamadı');
        }

        const searchQuery = query || searchInput.value.trim();
        if (!searchQuery) {
            if (window.showMessage) window.showMessage('Lütfen bir arama terimi girin.');
            return;
        }

        const results = await window.smartSearch.smartSearch(searchQuery, {
            hybridMode: true,
            maxResults: 20
        });
        
        if (results.error) {
            if (window.showError) window.showError('Arama hatası: ' + results.error);
            return null;
        }
        
        // Sonuçlara yumuşak scroll (eğer sonuç varsa)
        if (results.books && results.books.length > 0) {
            setTimeout(() => {
                const resultsContainer = document.getElementById('results');
                if (resultsContainer) {
                    resultsContainer.scrollIntoView({ 
                        behavior: 'smooth', 
                        block: 'start' 
                    });
                }
            }, 200);
        }
        
        return results;
    } catch (error) {
        console.error('AI Arama hatası:', error);
        if (window.showError) window.showError('Arama yapılamadı: ' + error.message);
        return null;
    } finally {
        if (window.showLoading) window.showLoading(false);
    }
  };

}); // DOMContentLoaded event listener'ın kapanması