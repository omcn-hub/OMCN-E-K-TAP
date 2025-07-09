<?php
// book_chat.php
// Basit "Kitapla Sohbet" uç noktası
// İstek: POST title, message
// Yanıt: { success: bool, reply: string }

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$title  = isset($_POST['title']) ? trim($_POST['title']) : '';
$message = isset($_POST['message']) ? trim($_POST['message']) : '';

if ($title === '' || $message === '') {
    echo json_encode([
        'success' => false,
        'error'   => 'Geçersiz parametreler'
    ]);
    exit;
}

try {
    $reply = generateBookReply($title, $message);
    echo json_encode([
        'success' => true,
        'reply'   => $reply
    ]);
} catch (Throwable $e) {
    logError('Kitap sohbeti hatası', ['exception' => $e->getMessage()]);
    echo json_encode([
        'success' => false,
        'error'   => 'Sohbet sırasında beklenmeyen hata oluştu.'
    ]);
}

/**
 * Kitaptan yanıt üretir. OpenAI API varsa kullanır, yoksa sabit yanıt döner.
 *
 * @param string $title   Kitap başlığı
 * @param string $message Kullanıcının sorusu/mesajı
 * @return string         Kitaptan yanıt
 */
function generateBookReply(string $title, string $message): string
{
    // 1) Gemini API (Google) varsa önce onu dene
    if (defined('GEMINI_API_KEY') && GEMINI_API_KEY) {
        $prompt = "Sen bir kitapsın. Kitabın adı: '{$title}'. Kullanıcıyla arkadaşça ve açıklayıcı bir tonda konuş. Kullanıcının mesajı: '{$message}'.";

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ]
        ];

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . urlencode(GEMINI_API_KEY);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new Exception('cURL: ' . curl_error($ch));
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status >= 200 && $status < 300 && $response) {
            $json = json_decode($response, true);
            if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
                return trim($json['candidates'][0]['content']['parts'][0]['text']);
            }
        } else {
            // API hata detayını logla
            logError("Gemini API HTTP hata", [
                'status' => $status,
                'body' => $response ?: 'Empty response'
            ]);
        }
        // Gemini başarısızsa OpenAI'ye düş
    }

    // 2) OpenAI entegrasyonu : .env veya config.php içinde OPENAI_API_KEY tanımlıysa kullan
    if (defined('OPENAI_API_KEY') && OPENAI_API_KEY) {
        $prompt = "Sen bir kitapsın. Kitabın adı: '{$title}'. Kullanıcıyla arkadaşça ve açıklayıcı bir tonda konuş. Kullanıcının mesajı: '{$message}'.";
        $payload = [
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                ['role' => 'system', 'content' => "Kitap asistanı, sıcak ve öğretici"],
                ['role' => 'user',   'content' => $prompt]
            ],
            'temperature' => 0.7
        ];
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer '.OPENAI_API_KEY
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new Exception('cURL: '.curl_error($ch));
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status >= 200 && $status < 300 && $response) {
            $json = json_decode($response, true);
            if (isset($json['choices'][0]['message']['content'])) {
                return trim($json['choices'][0]['message']['content']);
            }
        }
        // API başarısız ise aşağıya düş
    }

    // 3) Yedek: Basit kural tabanlı yanıt
    $templates = [
        "Merhaba! Ben \"%s\" kitabıyım. Sorduğunuz şeye şöyle cevap verebilirim: %s",
        "Benim sayfalarım arasında bu konuyla ilgili çok şey bulabilirsiniz. Kısaca: %s",
        "\"%s\" olarak, bu soruya yanıtım: %s"
    ];
    $template = $templates[array_rand($templates)];
    return sprintf($template, $title, generateDummyAnswer($message));
}

/**
 * Dummy answer generator (simple heuristic for offline mode)
 */
function generateDummyAnswer(string $msg): string
{
    // Basit anahtar kelime kontrolü
    $lower = mb_strtolower($msg);
    if (strpos($lower, 'özet') !== false) {
        return 'Kitabın ana teması, insanın içsel yolculuğu ve keşfidir.';
    }
    if (strpos($lower, 'karakter') !== false) {
        return 'Ana karakter, kendi kimliğini arayan, meraklı ve cesur biridir.';
    }
    // Varsayılan
    return 'Bu konuda detaylıca sayfalarımda bahsediyorum!';
} 