<?php

// Nonaktifkan display_errors agar tidak merusak output JSON jika ada warning/notice
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_error.log');
error_reporting(E_ALL);

// Mulai output buffering untuk menangkap output tak terduga
ob_start();

/**
 * Helper untuk logging debug
 */
function debug_log($message) {
    $log_file = __DIR__ . '/../logs/debug_contact.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
}

debug_log("--- Request baru dimulai ---");
debug_log("IP: " . get_client_ip());

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/../vendor/autoload.php';

// ============================================
// KONFIGURASI
// ============================================
$RECAPTCHA_SECRET_KEY = "6LdqARMtAAAAAAus4flEy72Twc8DsweTxdG_6ypQ"; // Dari Google reCAPTCHA
$EMAIL_RECEIVE = "rimurudotcom@gmail.com"; // Email penerima pesan
$EMAIL_FROM = "nonamebumantara@gmail.com"; // Email pengirim (Gmail)
$EMAIL_PASSWORD = "fhimfmmttxfyndjq"; // App Password Gmail

// Rate limiting (5 pesan per IP per jam)
$rate_limit = 5;
$rate_limit_period = 3600; // 1 jam

// ============================================
// SET HEADER JSON
// ============================================
header('Content-Type: application/json; charset=utf-8');

/**
 * Helper function untuk mengirim response JSON yang bersih
 */
function send_response($success, $message, $code = 200) {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        'message' => $message
    ]);
    exit;
}

// ============================================
// VALIDASI METHOD
// ============================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_response(false, 'Metode request tidak valid', 405);
}

// ============================================
// ANTI SPAM - RATE LIMITING
// ============================================
debug_log("Memulai pengecekan rate limit...");
$ip_address = get_client_ip();
$rate_limit_key = 'contact_form_' . $ip_address;

// Gunakan file untuk rate limiting jika tidak ada Redis
if (!is_dir('../logs')) {
    mkdir('../logs', 0755, true);
}

$rate_limit_file = '../logs/' . md5($rate_limit_key) . '.txt';

if (file_exists($rate_limit_file)) {
    $data = json_decode(file_get_contents($rate_limit_file), true);
    $current_time = time();
    
    // Hapus data lama
    $data['attempts'] = array_filter($data['attempts'], function($time) use ($current_time, $rate_limit_period) {
        return ($current_time - $time) < $rate_limit_period;
    });
    
    // Cek apakah sudah melebihi limit
    if (count($data['attempts']) >= $rate_limit) {
        debug_log("Rate limit tercapai untuk IP: $ip_address");
        send_response(false, 'Terlalu banyak permintaan. Silakan coba lagi dalam 1 jam', 429);
    }
    
    // Tambah attempt baru
    $data['attempts'][] = time();
    file_put_contents($rate_limit_file, json_encode($data));
} else {
    // File baru
    file_put_contents($rate_limit_file, json_encode([
        'attempts' => [time()]
    ]));
}
debug_log("Rate limit OK.");

// ============================================
// AMBIL DATA FORM
// ============================================
$name = isset($_POST['name']) ? trim($_POST['name']) : '';
$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
$subject = isset($_POST['subject']) ? trim($_POST['subject']) : '';
$message = isset($_POST['message']) ? trim($_POST['message']) : '';
$recaptcha_response = isset($_POST['g-recaptcha-response']) ? $_POST['g-recaptcha-response'] : '';

debug_log("Data form diterima: Nama=$name, Email=$email");

// ============================================
// VALIDASI INPUT
// ============================================
$errors = [];

// Validasi field kosong
if (empty($name) || strlen($name) < 3) {
    $errors[] = 'Nama harus diisi (minimal 3 karakter)';
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Email tidak valid';
}

if (empty($subject) || strlen($subject) < 5) {
    $errors[] = 'Subjek harus diisi (minimal 5 karakter)';
}

if (empty($message) || strlen($message) < 10) {
    $errors[] = 'Pesan harus diisi (minimal 10 karakter)';
}

if (!empty($errors)) {
    debug_log("Validasi input gagal: " . implode(", ", $errors));
    send_response(false, implode(', ', $errors), 400);
}
debug_log("Validasi input OK.");

// ============================================
// VALIDASI reCAPTCHA
// ============================================
if (empty($recaptcha_response)) {
    debug_log("reCAPTCHA response kosong.");
    send_response(false, 'Verifikasi reCAPTCHA diperlukan', 400);
}

debug_log("Memulai verifikasi reCAPTCHA ke Google...");
// POST ke Google untuk verifikasi reCAPTCHA
$recaptcha_url = 'https://www.google.com/recaptcha/api/siteverify';
$recaptcha_post = http_build_query([
    'secret' => $RECAPTCHA_SECRET_KEY,
    'response' => $recaptcha_response
]);

$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'content' => $recaptcha_post,
        'header' => 'Content-type: application/x-www-form-urlencoded',
        'timeout' => 10
    ]
]);

$recaptcha_raw = @file_get_contents($recaptcha_url, false, $context);
debug_log("Raw response reCAPTCHA: " . ($recaptcha_raw ?: "KOSONG"));

$recaptcha_result = json_decode($recaptcha_raw, true);

if (
    !isset($recaptcha_result['success']) ||
    !$recaptcha_result['success']
) {
    debug_log("Verifikasi reCAPTCHA gagal.");
    send_response(false, 'Verifikasi reCAPTCHA gagal. Silakan coba lagi', 400);
}
debug_log("Verifikasi reCAPTCHA berhasil.");

// ============================================
// SANITASI & ESCAPE DATA
// ============================================
$nameSafe = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
$emailSafe = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
$phoneSafe = !empty($phone) ? htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') : 'Tidak diisi';
$subjectSafe = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
$messageSafe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

// ============================================
// KIRIM EMAIL DENGAN PHPMailer
// ============================================
try {
    debug_log("Memulai konfigurasi PHPMailer...");
    $mail = new PHPMailer(true);

    // SMTP Configuration
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = $EMAIL_FROM;
    $mail->Password = $EMAIL_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;
    $mail->CharSet = 'UTF-8';
    $mail->Timeout = 30;

    // SSL/TLS Options
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ];

    // Set From & To
    $mail->setFrom($EMAIL_FROM, 'Website PT Bumantara Bumi Sejahtera');
    $mail->addAddress($EMAIL_RECEIVE);
    $mail->addReplyTo($email, $nameSafe);

    // Email Content
    $mail->isHTML(true);
    $mail->Subject = 'Pesan Baru: ' . $subjectSafe;

    // HTML Body
    $mail->Body = "
        <div style='font-family: Arial, sans-serif; line-height: 1.8; color: #333;'>
            <h2 style='color: #1a73e8;'>📧 Pesan Baru dari Website</h2>
            
            <table cellpadding='10' style='border-collapse: collapse; width: 100%; max-width: 500px;'>
                <tr style='background-color: #f5f5f5;'>
                    <td style='border: 1px solid #ddd; font-weight: bold;'>Nama</td>
                    <td style='border: 1px solid #ddd;'>$nameSafe</td>
                </tr>
                <tr>
                    <td style='border: 1px solid #ddd; font-weight: bold;'>Email</td>
                    <td style='border: 1px solid #ddd;'>$emailSafe</td>
                </tr>
                <tr style='background-color: #f5f5f5;'>
                    <td style='border: 1px solid #ddd; font-weight: bold;'>WhatsApp</td>
                    <td style='border: 1px solid #ddd;'>$phoneSafe</td>
                </tr>
                <tr>
                    <td style='border: 1px solid #ddd; font-weight: bold;'>Subjek</td>
                    <td style='border: 1px solid #ddd;'>$subjectSafe</td>
                </tr>
            </table>

            <hr style='border: none; border-top: 2px solid #ddd; margin: 20px 0;'>

            <h3 style='color: #1a73e8;'>Isi Pesan:</h3>
            <p style='white-space: pre-wrap; background-color: #f9f9f9; padding: 15px; border-radius: 5px;'>$messageSafe</p>

            <hr style='border: none; border-top: 1px solid #ddd; margin: 20px 0;'>

            <p style='font-size: 12px; color: #666;'>
                <strong>IP Address:</strong> $ip_address<br>
                <strong>Waktu:</strong> " . date('d-m-Y H:i:s') . " WIB
            </p>
        </div>
    ";

    // Plain Text Body
    $mail->AltBody = sprintf(
        "Pesan Baru dari Website\n\nNama: %s\nEmail: %s\nWhatsApp: %s\nSubjek: %s\n\nPesan:\n%s",
        $name,
        $email,
        $phone ?: 'Tidak diisi',
        $subject,
        $message
    );

    // Send Email
    $mail->send();

    // SUCCESS RESPONSE
    send_response(true, 'Pesan Anda telah berhasil dikirim! Kami akan segera membalas.');

} catch (Exception $e) {
    // ERROR RESPONSE
    error_log('PHPMailer Error: ' . $mail->ErrorInfo, 3, '../logs/email_errors.log');
    send_response(false, 'Terjadi kesalahan saat mengirim email. Tim kami telah diberitahu. Silakan coba lagi nanti.', 500);
}

// ============================================
// HELPER FUNCTION - GET CLIENT IP
// ============================================
function get_client_ip() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}
?>