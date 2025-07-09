<?php
echo "=== JavaScript Dosya Kontrolü ===\n";

$jsFiles = ['main.js', 'api-manager.js', 'ai-smart-search.js'];

foreach ($jsFiles as $file) {
    if (file_exists($file)) {
        echo "✅ $file mevcut - " . number_format(filesize($file) / 1024, 2) . " KB\n";
        
        // Basit syntax kontrolü
        $content = file_get_contents($file);
        $openBraces = substr_count($content, '{');
        $closeBraces = substr_count($content, '}');
        $openParens = substr_count($content, '(');
        $closeParens = substr_count($content, ')');
        
        echo "  - Açık parantez: $openBraces, Kapalı parantez: $closeBraces";
        if ($openBraces !== $closeBraces) {
            echo " ❌ UYUMSUZ!";
        } else {
            echo " ✅";
        }
        echo "\n";
        
        echo "  - Açık yuvarlak parantez: $openParens, Kapalı yuvarlak parantez: $closeParens";
        if ($openParens !== $closeParens) {
            echo " ❌ UYUMSUZ!";
        } else {
            echo " ✅";
        }
        echo "\n";
        
    } else {
        echo "❌ $file bulunamadı\n";
    }
}

echo "\n=== HTML Dosya Kontrolü ===\n";
if (file_exists('index.html')) {
    echo "✅ index.html mevcut - " . number_format(filesize('index.html') / 1024, 2) . " KB\n";
    
    $content = file_get_contents('index.html');
    
    // Script tag'lerini kontrol et
    preg_match_all('/<script[^>]*src=["\']([^"\']*)["\'][^>]*>/i', $content, $matches);
    echo "Script dosyaları:\n";
    foreach ($matches[1] as $script) {
        if (!preg_match('/^https?:\/\//', $script)) {
            // Local script
            if (file_exists($script)) {
                echo "  ✅ $script\n";
            } else {
                echo "  ❌ $script (dosya bulunamadı)\n";
            }
        } else {
            echo "  🌐 $script (external)\n";
        }
    }
} else {
    echo "❌ index.html bulunamadı\n";
}

echo "\n=== Backend Dosya Kontrolü ===\n";
$backendFiles = ['auth.php', 'config.php', 'search.php', 'library.php'];

foreach ($backendFiles as $file) {
    if (file_exists($file)) {
        echo "✅ $file mevcut\n";
        
        // PHP syntax kontrolü
        $output = shell_exec("php -l $file 2>&1");
        if (strpos($output, 'No syntax errors') !== false) {
            echo "  ✅ Syntax OK\n";
        } else {
            echo "  ❌ Syntax Error: " . trim($output) . "\n";
        }
    } else {
        echo "❌ $file bulunamadı\n";
    }
}

echo "\n=== Test Tamamlandı ===\n";
?> 