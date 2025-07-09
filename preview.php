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
    if (!checkRateLimit('preview_' . $bookId, 10, 60)) { // 1 dakikada max 10 istek
        throw new Exception('Çok fazla istek gönderdiniz. Lütfen biraz bekleyin.', 429);
    }

    // Kitap bilgilerini veritabanından al
    $stmt = $pdo->prepare("SELECT * FROM books WHERE id = ? AND status = 'active'");
    $stmt->execute([$bookId]);
    $book = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$book) {
        throw new Exception('Kitap bulunamadı', 404);
    }

    // PDF dosyasının yolunu doğrula
    $pdfPath = securePath($book['pdf_path']);
    
    if (!$pdfPath || !file_exists($pdfPath)) {
        throw new Exception('PDF dosyası bulunamadı', 404);
    }

    // Önizleme klasörünü kontrol et
    if (!is_dir('previews')) {
        if (!mkdir('previews', 0755, true)) {
            throw new Exception('Önizleme klasörü oluşturulamadı');
        }
    }

    $previewPath = 'previews/' . $bookId . '_preview.pdf';
    $previewPath = securePath($previewPath);

    if (!$previewPath) {
        throw new Exception('Geçersiz önizleme dosya yolu');
    }

    // Önizleme dosyası yoksa oluştur (ilk 10 sayfa)
    if (!file_exists($previewPath)) {
        // GhostScript komutunu güvenli şekilde oluştur
        $cmd = sprintf(
            'gs -sDEVICE=pdfwrite -dNOPAUSE -dBATCH -dFirstPage=1 -dLastPage=10 -sOutputFile=%s %s 2>&1',
            escapeshellarg($previewPath),
            escapeshellarg($pdfPath)
        );
        
        exec($cmd, $output, $returnCode);
        
        if ($returnCode !== 0) {
            error_log('PDF önizleme oluşturma hatası: ' . implode("\n", $output));
            throw new Exception('PDF önizleme oluşturulamadı');
        }
    }

    // Aktivite logla
    logUserActivity('preview_book', [
        'book_id' => $bookId,
        'title' => $book['title']
    ]);

    // PDF görüntüleyici sayfasını göster
    ?>
    <!DOCTYPE html>
    <html lang="tr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($book['title']); ?> (Önizleme) - OMCN E-Kitap</title>
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
            .preview-notice {
                position: fixed;
                bottom: 20px;
                left: 50%;
                transform: translateX(-50%);
                background: rgba(0,0,0,0.8);
                color: white;
                padding: 10px 20px;
                border-radius: 20px;
                font-size: 14px;
            }
            .read-full {
                background: #3498db;
                color: white;
                text-decoration: none;
                padding: 5px 10px;
                border-radius: 4px;
                margin-left: 10px;
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
            <a href="read.php?id=<?php echo $bookId; ?>" style="margin-left: auto;" class="read-full">Tam Kitabı Oku</a>
        </div>
        <div id="viewer-container">
            <canvas id="pdf-viewer"></canvas>
        </div>
        <div class="preview-notice">
            Bu bir önizlemedir. Sadece ilk 10 sayfa gösterilmektedir.
            <a href="read.php?id=<?php echo $bookId; ?>" class="read-full">Tam Kitabı Oku</a>
        </div>

        <script>
            // PDF.js worker'ı güvenli şekilde yükle
            pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

            let pdfDoc = null,
                pageNum = 1,
                scale = 1.5;

            // PDF'yi yükle ve hata yönetimi ekle
            pdfjsLib.getDocument('<?php echo $previewPath; ?>').promise
                .then(function(pdf) {
                    pdfDoc = pdf;
                    document.getElementById('page-num').textContent = pageNum + ' / ' + pdf.numPages;
                    renderPage(pageNum);
                })
                .catch(function(error) {
                    console.error('PDF yükleme hatası:', error);
                    alert('PDF yüklenirken bir hata oluştu. Lütfen sayfayı yenileyin veya daha sonra tekrar deneyin.');
                });

            function renderPage(num) {
                pdfDoc.getPage(num).then(function(page) {
                    const canvas = document.getElementById('pdf-viewer');
                    const ctx = canvas.getContext('2d');
                    const viewport = page.getViewport({scale: scale});

                    canvas.height = viewport.height;
                    canvas.width = viewport.width;

                    page.render({
                        canvasContext: ctx,
                        viewport: viewport
                    }).promise.catch(function(error) {
                        console.error('Sayfa render hatası:', error);
                        alert('Sayfa görüntülenirken bir hata oluştu.');
                    });
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
        </script>
    </body>
    </html>
    <?php
} catch (Exception $e) {
    $statusCode = $e->getCode() ?: 500;
    
    // Hata mesajını error.log'a yaz
    error_log('Preview Error: ' . $e->getMessage());
    
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