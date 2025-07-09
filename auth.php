<?php
require_once 'config.php';

// Session başlat
startSecureSession();

// Rate limiting kontrolü
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!checkRateLimit("auth:$clientIp", 30, 60)) {
    jsonResponse(['error' => 'Çok fazla istek. Lütfen bir dakika sonra tekrar deneyin.'], 429);
}

// CORS ayarları config.php'den geliyor
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

// İstek yöntemini al
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Güvenlik headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

switch ($action) {
    case 'login':
        if ($method === 'POST') {
            handleLogin();
        }
        break;
    
    case 'register':
        if ($method === 'POST') {
            handleRegister();
        }
        break;
    
    case 'logout':
        if ($method === 'POST') {
            handleLogout();
        }
        break;
    
    case 'check':
        if ($method === 'GET') {
            checkAuthStatus();
        }
        break;
    
    case 'change-password':
        if ($method === 'POST') {
            handleChangePassword();
        }
        break;
    
    case 'update-profile':
        if ($method === 'POST') {
            handleUpdateProfile();
        }
        break;
        
    case 'forgot-password':
        if ($method === 'POST') {
            handleForgotPassword();
        }
        break;
        
    case 'reset-password':
        if ($method === 'POST') {
            handleResetPassword();
        }
        break;
        
    case 'verify-email':
        if ($method === 'POST') {
            handleEmailVerification();
        }
        break;
    
    default:
        jsonResponse(['error' => 'Geçersiz işlem'], 400);
}

function handleLogin() {
    $pdo = getDbConnection();
    
    // POST verilerini al (JSON veya form data)
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    if (empty($input)) {
        jsonResponse(['error' => 'Veri bulunamadı'], 400);
    }
    
    $email = sanitizeInput($input['email'] ?? '', 'email');
    $password = $input['password'] ?? '';
    $rememberMe = $input['remember'] ?? false;
    
    // Validasyon
    if (empty($email) || empty($password)) {
        jsonResponse(['error' => 'E-posta ve şifre gerekli'], 400);
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['error' => 'Geçerli bir e-posta adresi girin'], 400);
    }
    
    try {
        // Kullanıcıyı bul ve hesap kilitleme kontrolü
        $stmt = $pdo->prepare("
            SELECT id, name, email, password, is_active, failed_login_attempts, locked_until
            FROM users 
            WHERE email = ?
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            // Kullanıcı yok ama güvenlik için aynı hata mesajı
            jsonResponse(['error' => 'E-posta veya şifre hatalı'], 401);
        }
        
        // Hesap aktif mi?
        if (!$user['is_active']) {
            jsonResponse(['error' => 'Hesabınız devre dışı bırakılmış'], 401);
        }
        
        // Hesap kilitli mi?
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            $lockTimeRemaining = strtotime($user['locked_until']) - time();
            jsonResponse([
                'error' => 'Hesabınız geçici olarak kilitlendi',
                'retry_after' => $lockTimeRemaining
            ], 423);
        }
        
        // Şifre doğru mu?
        if (!password_verify($password, $user['password'])) {
            // Başarısız giriş denemesini kaydet
            handleFailedLogin($user['id'], $email);
            jsonResponse(['error' => 'E-posta veya şifre hatalı'], 401);
        }
        
        // Başarılı giriş - sayacı sıfırla
        resetFailedLoginAttempts($user['id']);
        
        // Oturum bilgilerini kaydet
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['login_time'] = time();
        
        // Son giriş bilgilerini güncelle
        updateLastLogin($user['id']);
        
        // Remember me cookie
        if ($rememberMe) {
            $token = bin2hex(random_bytes(32));
            setcookie('remember_token', $token, time() + (30 * 24 * 3600), '/', '', true, true);
            
            $stmt = $pdo->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
            $stmt->execute([hash('sha256', $token), $user['id']]);
        }
        
        // Güvenli yanıt
        jsonResponse([
            'success' => true,
            'message' => 'Giriş başarılı',
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email']
            ],
            'csrf_token' => generateCSRFToken()
        ]);
        
    } catch (PDOException $e) {
        logError("Login hatası", ['email' => $email, 'error' => $e->getMessage()]);
        jsonResponse(['error' => 'Sistem hatası. Lütfen daha sonra tekrar deneyin.'], 500);
    }
}

function handleRegister() {
    $pdo = getDbConnection();
    
    // POST verilerini al (JSON veya form data)
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    if (empty($input)) {
        jsonResponse(['error' => 'Veri bulunamadı'], 400);
    }
    
    $name = sanitizeInput($input['name'] ?? '');
    $email = sanitizeInput($input['email'] ?? '', 'email');
    $password = $input['password'] ?? '';
    $confirmPassword = $input['confirmPassword'] ?? '';
    
    // Validasyon
    $errors = validateRegistrationData($name, $email, $password, $confirmPassword);
    if (!empty($errors)) {
        jsonResponse(['error' => implode(' ', $errors)], 400);
    }
    
    try {
        // E-posta zaten var mı kontrol et
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            jsonResponse(['error' => 'Bu e-posta adresi zaten kayıtlı'], 409);
        }
        
        // Şifreyi güvenli bir şekilde hash'le
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT, ['cost' => BCRYPT_COST]);
        
        // Kullanıcıyı kaydet
        $stmt = $pdo->prepare("
            INSERT INTO users (name, email, password) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$name, $email, $hashedPassword]);
        
        $userId = $pdo->lastInsertId();
        
        // E-posta doğrulama token'ı oluştur (opsiyonel)
        $verificationToken = bin2hex(random_bytes(32));
        
        // Oturum başlat
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_name'] = $name;
        $_SESSION['user_email'] = $email;
        $_SESSION['login_time'] = time();
        
        // Log kaydet
        logInfo("Yeni kullanıcı kaydı", ['user_id' => $userId, 'email' => $email]);
        
        // Başarılı yanıt
        jsonResponse([
            'success' => true,
            'message' => 'Kayıt başarılı',
            'user' => [
                'id' => $userId,
                'name' => $name,
                'email' => $email
            ],
            'csrf_token' => generateCSRFToken()
        ]);
        
    } catch (PDOException $e) {
        logError("Kayıt hatası", ['email' => $email, 'error' => $e->getMessage()]);
        jsonResponse(['error' => 'Kayıt sırasında sistem hatası oluştu'], 500);
    }
}

function handleLogout() {
    $pdo = getDbConnection();
    
    // Remember me token'ını temizle
    if (isset($_COOKIE['remember_token']) && isset($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("UPDATE users SET remember_token = NULL WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        
        setcookie('remember_token', '', time() - 3600, '/', '', true, true);
    }
    
    // Oturum verilerini log'la
    $userId = $_SESSION['user_id'] ?? null;
    
    // Oturumu güvenli bir şekilde temizle
    $_SESSION = array();
    
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_destroy();
    
    // Log kaydet
    if ($userId) {
        logInfo("Kullanıcı çıkış yaptı", ['user_id' => $userId]);
    }
    
    jsonResponse([
        'success' => true,
        'message' => 'Çıkış başarılı'
    ]);
}

function checkAuthStatus() {
    $pdo = getDbConnection();
    
    if (isset($_SESSION['user_id'])) {
        try {
            $stmt = $pdo->prepare("
                SELECT id, name, email, created_at, role, avatar
                FROM users 
                WHERE id = ? AND is_active = 1
            ");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();
            
            if ($user) {
                jsonResponse([
                    'authenticated' => true,
                    'user' => [
                        'id' => $user['id'],
                        'name' => $user['name'],
                        'email' => $user['email'],
                        'role' => $user['role'],
                        'avatar' => $user['avatar'],
                        'created_at' => $user['created_at']
                    ],
                    'csrf_token' => generateCSRFToken()
                ]);
            } else {
                // Kullanıcı bulunamadı, oturumu temizle
                session_destroy();
                jsonResponse(['authenticated' => false]);
            }
        } catch (PDOException $e) {
            logError("Auth check hatası", ['user_id' => $_SESSION['user_id'], 'error' => $e->getMessage()]);
            jsonResponse(['authenticated' => false]);
        }
    } else {
        // Remember me token kontrolü
        if (isset($_COOKIE['remember_token'])) {
            $user = checkRememberToken($_COOKIE['remember_token']);
            if ($user) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['login_time'] = time();
                
                jsonResponse([
                    'authenticated' => true,
                    'user' => [
                        'id' => $user['id'],
                        'name' => $user['name'],
                        'email' => $user['email'],
                        'role' => $user['role'],
                        'avatar' => $user['avatar']
                    ],
                    'csrf_token' => generateCSRFToken()
                ]);
            }
        }
        
        jsonResponse(['authenticated' => false]);
    }
}

function handleChangePassword() {
    requireAuth();
    $pdo = getDbConnection();
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        jsonResponse(['error' => 'Geçersiz JSON veri'], 400);
    }
    
    // CSRF token kontrolü
    $csrfToken = $input['csrf_token'] ?? '';
    if (!validateCSRFToken($csrfToken)) {
        jsonResponse(['error' => 'Geçersiz güvenlik token\'ı'], 403);
    }
    
    $currentPassword = $input['currentPassword'] ?? '';
    $newPassword = $input['newPassword'] ?? '';
    $confirmPassword = $input['confirmPassword'] ?? '';
    
    // Validasyon
    $errors = validatePasswordChange($currentPassword, $newPassword, $confirmPassword);
    if (!empty($errors)) {
        jsonResponse(['error' => implode(' ', $errors)], 400);
    }
    
    try {
        // Mevcut şifreyi kontrol et
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        
        if (!$user || !password_verify($currentPassword, $user['password'])) {
            jsonResponse(['error' => 'Mevcut şifre hatalı'], 400);
        }
        
        // Yeni şifreyi güvenli bir şekilde hash'le
        $hashedNewPassword = password_hash($newPassword, PASSWORD_DEFAULT, ['cost' => BCRYPT_COST]);
        
        $stmt = $pdo->prepare("
            UPDATE users 
            SET password = ?, updated_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");
        $result = $stmt->execute([$hashedNewPassword, $_SESSION['user_id']]);
        
        if ($result) {
            // Tüm remember me token'larını geçersiz kıl
            $stmt = $pdo->prepare("UPDATE users SET remember_token = NULL WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            
            logInfo("Şifre değiştirildi", ['user_id' => $_SESSION['user_id']]);
            
            jsonResponse([
                'success' => true,
                'message' => 'Şifre başarıyla güncellendi'
            ]);
        } else {
            jsonResponse(['error' => 'Şifre güncellenirken hata oluştu'], 500);
        }
        
    } catch (PDOException $e) {
        logError("Şifre değiştirme hatası", ['user_id' => $_SESSION['user_id'], 'error' => $e->getMessage()]);
        jsonResponse(['error' => 'Sistem hatası'], 500);
    }
}

function handleUpdateProfile() {
    requireAuth();
    $pdo = getDbConnection();
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        jsonResponse(['error' => 'Geçersiz JSON veri'], 400);
    }
    
    // CSRF token kontrolü
    $csrfToken = $input['csrf_token'] ?? '';
    if (!validateCSRFToken($csrfToken)) {
        jsonResponse(['error' => 'Geçersiz güvenlik token\'ı'], 403);
    }
    
    $name = sanitizeInput($input['name'] ?? '');
    $avatar = sanitizeInput($input['avatar'] ?? '', 'url');
    
    // Validasyon
    if (empty($name)) {
        jsonResponse(['error' => 'Ad soyad alanı boş bırakılamaz'], 400);
    }
    
    if (strlen($name) < 2 || strlen($name) > 100) {
        jsonResponse(['error' => 'Ad 2-100 karakter arasında olmalı'], 400);
    }
    
    try {
        $stmt = $pdo->prepare("
            UPDATE users 
            SET name = ?, avatar = ?, updated_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");
        $result = $stmt->execute([$name, $avatar, $_SESSION['user_id']]);
        
        if ($result) {
            $_SESSION['user_name'] = $name;
            
            logInfo("Profil güncellendi", ['user_id' => $_SESSION['user_id']]);
            
            jsonResponse([
                'success' => true,
                'message' => 'Profil başarıyla güncellendi',
                'user' => [
                    'id' => $_SESSION['user_id'],
                    'name' => $name,
                    'email' => $_SESSION['user_email'],
                    'avatar' => $avatar
                ]
            ]);
        } else {
            jsonResponse(['error' => 'Profil güncellenirken hata oluştu'], 500);
        }
        
    } catch (PDOException $e) {
        logError("Profil güncelleme hatası", ['user_id' => $_SESSION['user_id'], 'error' => $e->getMessage()]);
        jsonResponse(['error' => 'Sistem hatası'], 500);
    }
}

// Yardımcı fonksiyonlar

function requireAuth() {
    if (!isset($_SESSION['user_id'])) {
        jsonResponse(['error' => 'Giriş yapmış kullanıcı gerekli'], 401);
    }
}

function handleFailedLogin($userId, $email) {
    $pdo = getDbConnection();
    
    try {
        // Başarısız giriş sayısını artır
        $stmt = $pdo->prepare("
            UPDATE users 
            SET failed_login_attempts = failed_login_attempts + 1 
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        
        // Maksimum deneme sayısına ulaşıldı mı?
        $stmt = $pdo->prepare("SELECT failed_login_attempts FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $attempts = $stmt->fetchColumn();
        
        if ($attempts >= MAX_LOGIN_ATTEMPTS) {
            // Hesabı geçici olarak kilitle
            $lockUntil = date('Y-m-d H:i:s', time() + LOGIN_LOCKOUT_TIME);
            $stmt = $pdo->prepare("UPDATE users SET locked_until = ? WHERE id = ?");
            $stmt->execute([$lockUntil, $userId]);
            
            logError("Hesap kilitlendi", ['user_id' => $userId, 'email' => $email, 'attempts' => $attempts]);
        }
        
    } catch (PDOException $e) {
        logError("Failed login kayıt hatası", ['error' => $e->getMessage()]);
    }
}

function resetFailedLoginAttempts($userId) {
    $pdo = getDbConnection();
    
    try {
        $stmt = $pdo->prepare("
            UPDATE users 
            SET failed_login_attempts = 0, locked_until = NULL 
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
    } catch (PDOException $e) {
        logError("Failed login reset hatası", ['error' => $e->getMessage()]);
    }
}

function updateLastLogin($userId) {
    $pdo = getDbConnection();
    
    try {
        $stmt = $pdo->prepare("
            UPDATE users 
            SET last_login_at = CURRENT_TIMESTAMP, last_login_ip = ? 
            WHERE id = ?
        ");
        $stmt->execute([$_SERVER['REMOTE_ADDR'] ?? 'unknown', $userId]);
    } catch (PDOException $e) {
        logError("Last login güncelleme hatası", ['error' => $e->getMessage()]);
    }
}

function checkRememberToken($token) {
    $pdo = getDbConnection();
    
    try {
        $stmt = $pdo->prepare("
            SELECT id, name, email, role, avatar 
            FROM users 
            WHERE remember_token = ? AND is_active = 1
        ");
        $stmt->execute([hash('sha256', $token)]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        logError("Remember token kontrol hatası", ['error' => $e->getMessage()]);
        return false;
    }
}

function validateRegistrationData($name, $email, $password, $confirmPassword) {
    $errors = [];
    
    if (empty($name) || empty($email) || empty($password) || empty($confirmPassword)) {
        $errors[] = 'Tüm alanlar gerekli.';
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Geçerli bir e-posta adresi girin.';
    }
    
    if (strlen($password) < 8) {
        $errors[] = 'Şifre en az 8 karakter olmalı.';
    }
    
    if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/', $password)) {
        $errors[] = 'Şifre büyük harf, küçük harf ve rakam içermeli.';
    }
    
    if ($password !== $confirmPassword) {
        $errors[] = 'Şifreler eşleşmiyor.';
    }
    
    if (strlen($name) < 2 || strlen($name) > 100) {
        $errors[] = 'Ad 2-100 karakter arasında olmalı.';
    }
    
    return $errors;
}

function validatePasswordChange($currentPassword, $newPassword, $confirmPassword) {
    $errors = [];
    
    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        $errors[] = 'Tüm alanlar gerekli.';
    }
    
    if (strlen($newPassword) < 8) {
        $errors[] = 'Yeni şifre en az 8 karakter olmalı.';
    }
    
    if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/', $newPassword)) {
        $errors[] = 'Yeni şifre büyük harf, küçük harf ve rakam içermeli.';
    }
    
    if ($newPassword !== $confirmPassword) {
        $errors[] = 'Yeni şifreler eşleşmiyor.';
    }
    
    if ($currentPassword === $newPassword) {
        $errors[] = 'Yeni şifre mevcut şifreden farklı olmalı.';
    }
    
    return $errors;
}

// Gelişmiş şifre sıfırlama ve e-posta doğrulama fonksiyonları
function handleForgotPassword() {
    $pdo = getDbConnection();
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        jsonResponse(['error' => 'Geçersiz JSON veri'], 400);
    }
    
    $email = sanitizeInput($input['email'] ?? '', 'email');
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['error' => 'Geçerli bir e-posta adresi girin'], 400);
    }
    
    try {
        $stmt = $pdo->prepare("SELECT id, name FROM users WHERE email = ? AND is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            // Güvenlik için aynı mesajı döndür
            jsonResponse(['message' => 'Eğer bu e-posta adresine kayıtlı bir hesap varsa, şifre sıfırlama talimatları gönderilecek.']);
        }
        
        // Reset token oluştur
        $resetToken = bin2hex(random_bytes(32));
        $resetExpiry = date('Y-m-d H:i:s', time() + 3600); // 1 saat
        
        $stmt = $pdo->prepare("UPDATE users SET remember_token = ?, locked_until = ? WHERE id = ?");
        $stmt->execute([$resetToken, $resetExpiry, $user['id']]);
        
        // E-posta gönderme simülasyonu (gerçekte mail() fonksiyonu veya PHPMailer kullanın)
        logInfo("Şifre sıfırlama isteği", [
            'user_id' => $user['id'],
            'email' => $email,
            'reset_token' => $resetToken,
            'expires_at' => $resetExpiry
        ]);
        
        jsonResponse(['message' => 'Eğer bu e-posta adresine kayıtlı bir hesap varsa, şifre sıfırlama talimatları gönderilecek.']);
        
    } catch (PDOException $e) {
        logError("Şifre sıfırlama hatası", ['email' => $email, 'error' => $e->getMessage()]);
        jsonResponse(['error' => 'Sistem hatası. Lütfen daha sonra tekrar deneyin.'], 500);
    }
}

function handleResetPassword() {
    $pdo = getDbConnection();
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        jsonResponse(['error' => 'Geçersiz JSON veri'], 400);
    }
    
    $token = sanitizeInput($input['token'] ?? '');
    $newPassword = $input['password'] ?? '';
    $confirmPassword = $input['confirm_password'] ?? '';
    
    if (empty($token) || empty($newPassword) || empty($confirmPassword)) {
        jsonResponse(['error' => 'Tüm alanlar gerekli'], 400);
    }
    
    if ($newPassword !== $confirmPassword) {
        jsonResponse(['error' => 'Şifreler eşleşmiyor'], 400);
    }
    
    // Şifre güçlülük kontrolü
    if (strlen($newPassword) < 8) {
        jsonResponse(['error' => 'Şifre en az 8 karakter olmalı'], 400);
    }
    
    if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/', $newPassword)) {
        jsonResponse(['error' => 'Şifre en az bir büyük harf, bir küçük harf ve bir rakam içermeli'], 400);
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT id, email FROM users 
            WHERE remember_token = ? AND locked_until > NOW() AND is_active = 1
        ");
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        
        if (!$user) {
            jsonResponse(['error' => 'Geçersiz veya süresi dolmuş token'], 400);
        }
        
        // Şifreyi güncelle ve token'ı temizle
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT, ['cost' => BCRYPT_COST]);
        
        $stmt = $pdo->prepare("
            UPDATE users 
            SET password = ?, remember_token = NULL, locked_until = NULL, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$hashedPassword, $user['id']]);
        
        logInfo("Şifre başarıyla sıfırlandı", ['user_id' => $user['id'], 'email' => $user['email']]);
        
        jsonResponse(['message' => 'Şifreniz başarıyla güncellendi. Artık yeni şifrenizle giriş yapabilirsiniz.']);
        
    } catch (PDOException $e) {
        logError("Şifre sıfırlama hatası", ['token' => $token, 'error' => $e->getMessage()]);
        jsonResponse(['error' => 'Sistem hatası. Lütfen daha sonra tekrar deneyin.'], 500);
    }
}

function handleEmailVerification() {
    $pdo = getDbConnection();
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        jsonResponse(['error' => 'Geçersiz JSON veri'], 400);
    }
    
    $token = sanitizeInput($input['token'] ?? '');
    $email = sanitizeInput($input['email'] ?? '', 'email');
    
    if (empty($token) || empty($email)) {
        jsonResponse(['error' => 'Token ve e-posta gerekli'], 400);
    }
    
    try {
        // Hash'lenmiş token ile karşılaştır
        $hashedToken = hash('sha256', $token);
        
        $stmt = $pdo->prepare("
            SELECT id, name FROM users 
            WHERE email = ? AND remember_token = ? AND email_verified_at IS NULL AND is_active = 1
        ");
        $stmt->execute([$email, $hashedToken]);
        $user = $stmt->fetch();
        
        if (!$user) {
            jsonResponse(['error' => 'Geçersiz doğrulama bağlantısı veya e-posta zaten doğrulanmış'], 400);
        }
        
        // E-postayı doğrulanmış olarak işaretle
        $stmt = $pdo->prepare("
            UPDATE users 
            SET email_verified_at = NOW(), remember_token = NULL, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$user['id']]);
        
        logInfo("E-posta doğrulandı", ['user_id' => $user['id'], 'email' => $email]);
        
        jsonResponse([
            'message' => 'E-posta adresiniz başarıyla doğrulandı!',
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $email,
                'email_verified' => true
            ]
        ]);
        
    } catch (PDOException $e) {
        logError("E-posta doğrulama hatası", ['email' => $email, 'error' => $e->getMessage()]);
        jsonResponse(['error' => 'Sistem hatası. Lütfen daha sonra tekrar deneyin.'], 500);
    }
}
?> 