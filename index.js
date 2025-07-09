// OMCN E-Kitap Frontend Entry Point

// Global fonksiyonları tanımla
window.addEventListener('DOMContentLoaded', async function() {
    try {
        console.log('🚀 OMCN E-Kitap Frontend başlatılıyor...');
        
        // API Manager instance'ını oluştur veya mevcut olanı kullan
        if (!window.api) {
            if (window.APIManager) {
                window.api = new window.APIManager();
            } else {
                console.error('❌ APIManager bulunamadı!');
                throw new Error('APIManager yüklenemedi');
            }
        }
        
        // API'yi başlat
        await window.api.init();
        console.log('✅ API Manager başlatıldı');
        
        // Smart Search instance'ını oluştur
        if (window.OMCNSmartSearch) {
            window.smartSearch = new window.OMCNSmartSearch({
                apiBaseUrl: window.location.origin + '/',
                userId: window.api.currentUser?.id || 0,
                debug: true
            });
            console.log('✅ Smart Search başlatıldı');
        } else {
            console.warn('⚠️ OMCNSmartSearch bulunamadı');
        }
        
        console.log('✅ OMCN E-Kitap Frontend başlatıldı');
        
        // Arama butonunun çalışıp çalışmadığını test et
        const searchBtn = document.getElementById('search-btn');
        if (searchBtn) {
            console.log('✅ Arama butonu bulundu');
        } else {
            console.error('❌ Arama butonu bulunamadı!');
        }
        
    } catch (error) {
        console.error('Frontend başlatma hatası:', error);
        // showError henüz tanımlanmamış olabilir, alert kullan
        if (window.showError) {
            window.showError('Sistem başlatılırken bir hata oluştu: ' + error.message);
        } else {
            alert('Sistem başlatılırken bir hata oluştu: ' + error.message);
        }
    }
}); 