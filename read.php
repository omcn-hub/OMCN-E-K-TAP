<?php
require_once 'config.php';
require_once 'auth.php';

// Hata raporlamayı aç ama sadece log'a yaz
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

try {
    // Oturum kontrolü
    if (!isLoggedIn()) {
        jsonResponse(['error' => 'Oturum açmanız gerekiyor'], 401);
    }

    // Veritabanı bağlantısını kur
    $pdo = getDbConnection();

    // Kitap ID'sini al ve doğrula
    $bookId = sanitizeInput($_GET['id'] ?? '', 'id');
    
    if (!$bookId) {
        throw new Exception('Geçersiz kitap ID');
    }

    // Rate limit kontrolü
    if (!checkRateLimit('read_' . $bookId, 5, 60)) { // 1 dakikada max 5 istek
        throw new Exception('Çok fazla istek gönderdiniz. Lütfen biraz bekleyin.', 429);
    }

    // Kitap bilgilerini veritabanından al
    $stmt = $pdo->prepare("
        SELECT b.*, u.subscription_type 
        FROM books b 
        LEFT JOIN users u ON u.id = ? 
        WHERE b.id = ? AND b.status = 'active'
    ");
    $stmt->execute([$_SESSION['user_id'], $bookId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
        throw new Exception('Kitap bulunamadı', 404);
    }

    // Abonelik kontrolü
    if ($result['premium'] && $result['subscription_type'] !== 'premium') {
        throw new Exception('Bu kitabı okumak için premium üyelik gerekiyor', 403);
    }

    // PDF dosyasının yolunu doğrula
    $pdfPath = securePath($result['pdf_path']);
    
    if (!$pdfPath || !file_exists($pdfPath)) {
        throw new Exception('PDF dosyası bulunamadı', 404);
    }

    // Aktivite logla
    logUserActivity('read_book', [
        'book_id' => $bookId,
        'title' => $result['title']
    ]);

    // PDF görüntüleyici sayfasını göster
    ?>
    <!DOCTYPE html>
    <html lang="tr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($result['title']); ?> - OMCN E-Kitap</title>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js" integrity="sha512-q+4liFwdPC/bNdhUpZx6aXDx/h77yEQtn4I1slHydcbZK34nLaR3cAeYSJshoxIOq3mjEf7xJE8YWIUHMn+oCQ==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
        <style>
            body {
                margin: 0;
                padding: 0;
                background: #333;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            }
            #toolbar {
                background: #444;
                padding: 10px;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                z-index: 100;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            #toolbar button {
                padding: 8px 15px;
                border: none;
                border-radius: 4px;
                background: #666;
                color: white;
                cursor: pointer;
                transition: background 0.3s;
            }
            #toolbar button:hover {
                background: #777;
            }
            #page-num {
                color: white;
                margin: 0 10px;
            }
            #viewer-container {
                margin-top: 60px;
                text-align: center;
            }
            #pdf-viewer {
                max-width: 100%;
                height: calc(100vh - 70px);
            }
            #loading {
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: rgba(0,0,0,0.8);
                color: white;
                padding: 20px;
                border-radius: 10px;
                display: none;
            }
            .error-message {
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: #e74c3c;
                color: white;
                padding: 20px;
                border-radius: 10px;
                text-align: center;
                max-width: 80%;
            }
        </style>
    </head>
    <body>
        <div id="toolbar">
            <button onclick="prevPage()">Önceki Sayfa</button>
            <span id="page-num"></span>
            <button onclick="nextPage()">Sonraki Sayfa</button>
            <button onclick="zoomIn()">Yakınlaştır</button>
            <button onclick="zoomOut()">Uzaklaştır</button>
            <button onclick="window.location.href='index.html'" style="margin-left: auto;">Ana Sayfa</button>
        </div>
        <div id="viewer-container">
            <canvas id="pdf-viewer"></canvas>
        </div>
        <div id="loading">Yükleniyor...</div>

        <script>
            // PDF.js worker'ı güvenli şekilde yükle
            pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

            let pdfDoc = null,
                pageNum = 1,
                scale = 1.5,
                loading = document.getElementById('loading');

            function showLoading() {
                loading.style.display = 'block';
            }

            function hideLoading() {
                loading.style.display = 'none';
            }

            function showError(message) {
                const error = document.createElement('div');
                error.className = 'error-message';
                error.textContent = message;
                document.body.appendChild(error);
                setTimeout(() => error.remove(), 5000);
            }

            showLoading();

            // PDF'yi yükle ve hata yönetimi ekle
            pdfjsLib.getDocument('<?php echo $pdfPath; ?>').promise
                .then(function(pdf) {
                    pdfDoc = pdf;
                    document.getElementById('page-num').textContent = pageNum + ' / ' + pdf.numPages;
                    renderPage(pageNum);
                })
                .catch(function(error) {
                    console.error('PDF yükleme hatası:', error);
                    showError('PDF yüklenirken bir hata oluştu. Lütfen sayfayı yenileyin veya daha sonra tekrar deneyin.');
                })
                .finally(hideLoading);

            function renderPage(num) {
                showLoading();
                pdfDoc.getPage(num).then(function(page) {
                    const canvas = document.getElementById('pdf-viewer');
                    const ctx = canvas.getContext('2d');
                    const viewport = page.getViewport({scale: scale});

                    canvas.height = viewport.height;
                    canvas.width = viewport.width;

                    page.render({
                        canvasContext: ctx,
                        viewport: viewport
                    }).promise
                    .catch(function(error) {
                        console.error('Sayfa render hatası:', error);
                        showError('Sayfa görüntülenirken bir hata oluştu.');
                    })
                    .finally(hideLoading);
                });
            }

            function prevPage() {
                if (pageNum <= 1) return;
                pageNum--;
                document.getElementById('page-num').textContent = pageNum + ' / ' + pdfDoc.numPages;
                renderPage(pageNum);
            }

            function nextPage() {
                if (pageNum >= pdfDoc.numPages) return;
                pageNum++;
                document.getElementById('page-num').textContent = pageNum + ' / ' + pdfDoc.numPages;
                renderPage(pageNum);
            }

            function zoomIn() {
                scale *= 1.2;
                renderPage(pageNum);
            }

            function zoomOut() {
                scale *= 0.8;
                renderPage(pageNum);
            }

            // Klavye kontrollerini ekle
            document.addEventListener('keydown', function(e) {
                if (e.key === 'ArrowLeft') prevPage();
                else if (e.key === 'ArrowRight') nextPage();
                else if (e.key === '+') zoomIn();
                else if (e.key === '-') zoomOut();
            });
        </script>
    </body>
    </html>
    <?php
} catch (Exception $e) {
    $statusCode = $e->getCode() ?: 500;
    
    // Hata mesajını error.log'a yaz
    error_log('Read Error: ' . $e->getMessage());
    
    // AJAX isteği ise JSON yanıt dön
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        jsonResponse(['error' => $e->getMessage()], $statusCode);
    }
    
    // Normal istek ise hata sayfası göster
    ?>
    <!DOCTYPE html>
    <html lang="tr">
    <head>
        <meta charset="UTF-8">
        <title>Hata - OMCN E-Kitap</title>
        <style>
            body {
                font-family: 'Segoe UI', Arial, sans-serif;
                display: flex;
                align-items: center;
                justify-content: center;
                height: 100vh;
                margin: 0;
                background: #f5f5f5;
            }
            .error-container {
                text-align: center;
                padding: 2rem;
                background: white;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                max-width: 500px;
                width: 90%;
            }
            h1 { color: #e74c3c; margin-bottom: 1rem; }
            p { color: #666; line-height: 1.6; }
            .back-btn {
                display: inline-block;
                padding: 10px 20px;
                background: #3498db;
                color: white;
                text-decoration: none;
                border-radius: 4px;
                margin-top: 1rem;
                transition: background 0.3s;
            }
            .back-btn:hover {
                background: #2980b9;
            }
        </style>
    </head>
    <body>
        <div class="error-container">
            <h1>Bir Hata Oluştu</h1>
            <p><?php echo htmlspecialchars($e->getMessage()); ?></p>
            <a href="index.html" class="back-btn">Ana Sayfaya Dön</a>
        </div>
    </body>
    </html>
    <?php
}
?> 