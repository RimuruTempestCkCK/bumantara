<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../vendor/autoload.php';

// Hanya menerima POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.html');
    exit;
}

// ==========================
// AMBIL DATA FORM
// ==========================
$name    = trim($_POST['name'] ?? '');
$email   = trim($_POST['email'] ?? '');
$phone   = trim($_POST['phone'] ?? '');
$subject = trim($_POST['subject'] ?? '');
$message = trim($_POST['message'] ?? '');

// ==========================
// VALIDASI
// ==========================
if (
    empty($name) ||
    empty($email) ||
    empty($subject) ||
    empty($message)
) {
    die("
    <script>
        alert('Semua field wajib diisi!');
        window.history.back();
    </script>
    ");
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    die("
    <script>
        alert('Format email tidak valid!');
        window.history.back();
    </script>
    ");
}

// Sanitasi
$nameSafe    = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
$emailSafe   = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
$phoneSafe   = htmlspecialchars($phone, ENT_QUOTES, 'UTF-8');
$subjectSafe = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
$messageSafe = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

$mail = new PHPMailer(true);

try {

    // ==========================
    // SMTP GMAIL
    // ==========================
    $mail->isSMTP();

    // Host Gmail
    $mail->Host = 'smtp.gmail.com';

    // Auth login
    $mail->SMTPAuth = true;

    // Email Gmail
    $mail->Username = 'nonamebumantara@gmail.com';

    // App Password Gmail (tanpa spasi)
    $mail->Password = 'fhimfmmttxfyndjq';

    // Gunakan STARTTLS + 587
    // Lebih stabil di Windows/XAMPP
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;

    // Charset
    $mail->CharSet = 'UTF-8';

    // Timeout
    $mail->Timeout = 60;

    // Debug ON sementara
    $mail->SMTPDebug = 2;
    $mail->Debugoutput = 'html';

    // SSL options untuk localhost Windows
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ];

    // ==========================
    // PENGIRIM & TUJUAN
    // ==========================
    $mail->setFrom(
        'rimurudotcom@gmail.com',
        'Website PT Bumantara Bumi Sejahtera'
    );

    // Email tujuan
    $mail->addAddress('rimurudotcom@gmail.com');

    // Reply ke email user
    $mail->addReplyTo($email, $name);

    // ==========================
    // ISI EMAIL
    // ==========================
    $mail->isHTML(true);
    $mail->Subject = 'Pesan Baru Website - ' . $subjectSafe;

    $mail->Body = "
    <div style='font-family:Arial,sans-serif;line-height:1.8'>
        <h2>Pesan Baru dari Website</h2>

        <table cellpadding='8'>
            <tr>
                <td><strong>Nama</strong></td>
                <td>: {$nameSafe}</td>
            </tr>

            <tr>
                <td><strong>Email</strong></td>
                <td>: {$emailSafe}</td>
            </tr>

            <tr>
                <td><strong>WhatsApp</strong></td>
                <td>: {$phoneSafe}</td>
            </tr>

            <tr>
                <td><strong>Subjek</strong></td>
                <td>: {$subjectSafe}</td>
            </tr>
        </table>

        <hr>

        <h3>Isi Pesan:</h3>
        <p>{$messageSafe}</p>
    </div>
    ";

    // Versi text biasa
    $mail->AltBody =
        "Pesan Baru Website\n\n" .
        "Nama: {$name}\n" .
        "Email: {$email}\n" .
        "WhatsApp: {$phone}\n" .
        "Subjek: {$subject}\n\n" .
        "Pesan:\n{$message}";

    // ==========================
    // KIRIM EMAIL
    // ==========================
    $mail->send();

    echo "
    <script>
        alert('Pesan berhasil dikirim!');
        window.location.href = '../index.html#contact';
    </script>
    ";

} catch (Exception $e) {

    echo '<pre>';
    echo 'SMTP ERROR:<br><br>';
    echo $mail->ErrorInfo;
    echo '</pre>';
}
?>