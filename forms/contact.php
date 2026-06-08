<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../vendor/autoload.php';

// ============================================
// KONFIGURASI
// ============================================
$RECAPTCHA_SECRET_KEY = "6LdqARMtAAAAAAus4flEy72Twc8DsweTxdG_6ypQ"; // Dari Google reCAPTCHA
$EMAIL_RECEIVE = "info@bumantara.com"; // Email penerima pesan
$EMAIL_FROM = "nonamebumantara@gmail.com"; // Email pengirim (Gmail)
$EMAIL_PASSWORD = "fhimfmmttxfyndjq"; // App Password Gmail

// Rate limiting (5 pesan per IP per jam)
$rate_limit = 5;
$rate_limit_period = 3600; // 1 jam

// ============================================
// SET HEADER JSON
// ============================================
header('Content-Type: application/json; charset=utf-8');
http_response_code(200);

// ============================================
// VALIDASI METHOD
// ============================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode([
        'success' => false,
        'message' => 'Metode request tidak valid'
    ]));
}

// ============================================
// ANTI SPAM - RATE LIMITING
// ============================================
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
        http_response_code(429);
        die(json_encode([
            'success' => false,
            'message' => 'Terlalu banyak permintaan. Silakan coba lagi dalam 1 jam'
        ]));
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

// ============================================
// AMBIL DATA FORM
// ============================================
$name = isset($_POST['name']) ? trim($_POST['name']) : '';
$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
$subject = isset($_POST['subject']) ? trim($_POST['subject']) : '';
$message = isset($_POST['message']) ? trim($_POST['message']) : '';
$recaptcha_response = isset($_POST['g-recaptcha-response']) ? $_POST['g-recaptcha-response'] : '';

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

// Validasi panjang maksimal
if (strlen($name) > 100) {
    $errors[] = 'Nama terlalu panjang (max 100 karakter)';
}

if (strlen($email) > 100) {
    $errors[] = 'Email terlalu panjang (max 100 karakter)';
}

if (strlen($subject) > 200) {
    $errors[] = 'Subjek terlalu panjang (max 200 karakter)';
}

if (strlen($message) > 5000) {
    $errors[] = 'Pesan terlalu panjang (max 5000 karakter)';
}

if (!empty($errors)) {
    http_response_code(400);
    die(json_encode([
        'success' => false,
        'message' => implode(', ', $errors)
    ]));
}

// ============================================
// VALIDASI reCAPTCHA
// ============================================
if (empty($recaptcha_response)) {
    http_response_code(400);
    die(json_encode([
        'success' => false,
        'message' => 'Verifikasi reCAPTCHA diperlukan'
    ]));
}

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

$recaptcha_result = @json_decode(
    file_get_contents($recaptcha_url, false, $context),
    true
);

if (
    !isset($recaptcha_result['success']) ||
    !$recaptcha_result['success'] ||
    (isset($recaptcha_result['score']) && $recaptcha_result['score'] < 0.5)
) {
    http_response_code(400);
    die(json_encode([
        'success' => false,
        'message' => 'Verifikasi reCAPTCHA gagal. Silakan coba lagi'
    ]));
}

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
    $mail->Body = sprintf(
        "
        <div style='font-family: Arial, sans-serif; line-height: 1.8; color: #333;'>
            <h2 style='color: #1a73e8;'>📧 Pesan Baru dari Website</h2>
            
            <table cellpadding='10' style='border-collapse: collapse; width: 100%; max-width: 500px;'>
                <tr style='background-color: #f5f5f5;'>
                    <td style='border: 1px solid #ddd; font-weight: bold;'>Nama</td>
                    <td style='border: 1px solid #ddd;'>%s</td>
                </tr>
                <tr>
                    <td style='border: 1px solid #ddd; font-weight: bold;'>Email</td>
                    <td style='border: 1px solid #ddd;'>%s</td>
                </tr>
                <tr style='background-color: #f5f5f5;'>
                    <td style='border: 1px solid #ddd; font-weight: bold;'>WhatsApp</td>
                    <td style='border: 1px solid #ddd;'>%s</td>
                </tr>
                <tr>
                    <td style='border: 1px solid #ddd; font-weight: bold;'>Subjek</td>
                    <td style='border: 1px solid #ddd;'>%s</td>
                </tr>
            </table>

            <hr style='border: none; border-top: 2px solid #ddd; margin: 20px 0;'>

            <h3 style='color: #1a73e8;'>Isi Pesan:</h3>
            <p style='white-space: pre-wrap; background-color: #f9f9f9; padding: 15px; border-radius: 5px;'>%s</p>

            <hr style='border: none; border-top: 1px solid #ddd; margin: 20px 0;'>

            <p style='font-size: 12px; color: #666;'>
                <strong>IP Address:</strong> %s<br>
                <strong>Waktu:</strong> %s WIB
            </p>
        </div>
        ",
        $nameSafe,
        $emailSafe,
        $phoneSafe,
        $subjectSafe,
        $messageSafe,
        $ip_address,
        date('d-m-Y H:i:s')
    );

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
    http_response_code(200);
    die(json_encode([
        'success' => true,
        'message' => 'Pesan Anda telah berhasil dikirim! Kami akan segera membalas.'
    ]));

} catch (Exception $e) {
    // ERROR RESPONSE
    error_log('PHPMailer Error: ' . $mail->ErrorInfo, 3, '../logs/email_errors.log');
    
    http_response_code(500);
    die(json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan saat mengirim email. Tim kami telah diberitahu. Silakan coba lagi nanti.'
    ]));
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