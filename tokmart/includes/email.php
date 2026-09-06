<?php
/**
 * SMTP config + verification email sending.
 */

// PHPMailer is vendored directly (no Composer) so it works on plain shared
// hosting; guarded by file_exists so a partial upload degrades to the
// mail() fallback below instead of a fatal error on every request.
$phpmailerEntry = __DIR__ . '/lib/PHPMailer/PHPMailer.php';
if (!class_exists('PHPMailer\PHPMailer\PHPMailer') && file_exists($phpmailerEntry)) {
    require_once __DIR__ . '/lib/PHPMailer/Exception.php';
    require_once __DIR__ . '/lib/PHPMailer/SMTP.php';
    require_once $phpmailerEntry;
}
unset($phpmailerEntry);

function getSMTPConfig(): array {
    $row = queryOne("SELECT value FROM settings WHERE `key` = 'smtp'");
    if ($row) {
        return json_decode($row['value'], true);
    }
    // No real credentials ship in source - configure this from the admin
    // panel's settings page after install.
    return [
        'host' => '',
        'port' => 465,
        'username' => '',
        'password' => '',
        'encryption' => 'ssl',
        'from_email' => '',
        'from_name' => 'Tokmart',
        'enabled' => false,
    ];
}

/**
 * Sends a real test email using the given (possibly unsaved) SMTP settings,
 * so the admin can verify credentials from the settings page before saving.
 * Returns the actual SMTP error instead of just true/false.
 */
function sendTestEmail(string $to, array $smtp): array {
    if (empty($smtp['host']) || empty($smtp['from_email'])) {
        return ['success' => false, 'error' => 'الرجاء تعبئة خادم SMTP وبريد المرسل أولاً'];
    }
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        return ['success' => false, 'error' => 'مكتبة إرسال البريد (PHPMailer) غير موجودة على السيرفر، تحقق من رفع مجلد includes/lib/PHPMailer بالكامل'];
    }

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $smtp['host'];
        $mail->SMTPAuth = !empty($smtp['username']);
        $mail->Username = $smtp['username'] ?? '';
        $mail->Password = $smtp['password'] ?? '';
        $mail->SMTPSecure = $smtp['encryption'] ?: false;
        $mail->Port = (int)($smtp['port'] ?: 465);
        $mail->setFrom($smtp['from_email'], $smtp['from_name'] ?: 'Tokmart');
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = '✅ بريد تجريبي - إعدادات SMTP تعمل بنجاح';
        $mail->Body = '<p style="font-family:Arial,sans-serif">هذه رسالة تجريبية من متجرك على Tokmart. وصول هذه الرسالة يعني أن إعدادات SMTP صحيحة وجاهزة لإرسال أكواد التحقق.</p>';
        $mail->AltBody = 'هذه رسالة تجريبية من متجرك على Tokmart. وصول هذه الرسالة يعني أن إعدادات SMTP صحيحة وجاهزة لإرسال أكواد التحقق.';
        $mail->send();
        return ['success' => true, 'error' => ''];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $mail->ErrorInfo ?: $e->getMessage()];
    }
}

/**
 * دالة متقدمة لإرسال البريد الإلكتروني مع تفاصيل التشخيص وحماية من Spam
 */
function sendEmailWithDebug(string $to, string $subject, string $htmlMessage, string $code): bool {
    $smtp = getSMTPConfig();

    if (empty($smtp['enabled']) || empty($smtp['host'])) {
        error_log("SMTP not configured - skipped sending to {$to}");
        return false;
    }

    $textMessage = "كود التحقق الخاص بك هو: {$code}\n\n";
    $textMessage .= "هذا الكود صالح لمدة 5 دقائق.\n";
    $textMessage .= "إذا لم تطلب هذا الكود، يرجى تجاهل هذه الرسالة.";

    $boundary = "----=" . md5(uniqid((string)mt_rand(), true));

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $headers .= "From: \"" . $smtp['from_name'] . "\" <" . $smtp['from_email'] . ">\r\n";
    $headers .= "Reply-To: " . $smtp['from_email'] . "\r\n";
    $headers .= "Return-Path: " . $smtp['from_email'] . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    $headers .= "X-Priority: 3\r\n";
    $headers .= "Message-ID: <" . time() . "-" . md5(uniqid()) . "@" . parse_url(SITE_URL, PHP_URL_HOST) . ">\r\n";
    $headers .= "Date: " . date('r') . "\r\n";

    $fullMessage = "--{$boundary}\r\n";
    $fullMessage .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $fullMessage .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $fullMessage .= $textMessage . "\r\n\r\n";

    $fullMessage .= "--{$boundary}\r\n";
    $fullMessage .= "Content-Type: text/html; charset=UTF-8\r\n";
    $fullMessage .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $fullMessage .= $htmlMessage . "\r\n\r\n";
    $fullMessage .= "--{$boundary}--\r\n";

    $additionalParameters = "-f " . $smtp['from_email'];

    if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $smtp['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $smtp['username'];
            $mail->Password = $smtp['password'];
            $mail->SMTPSecure = $smtp['encryption'] ?? 'ssl';
            $mail->Port = $smtp['port'] ?? 465;
            $mail->setFrom($smtp['from_email'], $smtp['from_name']);
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlMessage;
            $mail->AltBody = $textMessage;
            $mail->CharSet = 'UTF-8';
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("SMTP Error: " . $e->getMessage());
            return false;
        }
    }

    return mail($to, $subject, $fullMessage, $headers, $additionalParameters);
}

function sendVerificationEmail(string $email, string $code): bool {
    $smtp = getSMTPConfig();
    $subject = "🔐 كود التحقق الخاص بك - " . $smtp['from_name'];

    $htmlMessage = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>كود التحقق</title>
        <style>
            body { font-family: Arial, sans-serif; direction: rtl; background: #f4f4f4; margin: 0; padding: 20px; }
            .container { max-width: 600px; margin: 0 auto; padding: 30px; background: #ffffff; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
            .header { text-align: center; padding-bottom: 20px; border-bottom: 3px solid #058693; }
            .header h2 { color: #333; margin: 0; }
            .content { padding: 20px 0; }
            .code-box { background: linear-gradient(135deg, #058693 0%, #0AA6B5 100%); padding: 25px; border-radius: 12px; text-align: center; margin: 20px 0; }
            .code-box .code { font-size: 42px; font-weight: bold; color: #ffffff; letter-spacing: 12px; font-family: 'Courier New', monospace; text-shadow: 2px 2px 4px rgba(0,0,0,0.2); }
            .info { background: #f0f8f8; padding: 15px; border-radius: 8px; margin: 15px 0; }
            .info p { margin: 5px 0; color: #555; }
            .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; font-size: 12px; color: #999; text-align: center; }
            .warning { color: #ff6b6b; font-size: 13px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>🔐 كود التحقق الخاص بك</h2>
            </div>
            <div class='content'>
                <p style='font-size: 16px; color: #333;'>مرحباً بك في <strong>{$smtp['from_name']}</strong>،</p>
                <p style='font-size: 16px; color: #333;'>لقد قمت بطلب كود تحقق من نظامنا الآلي.</p>
                <div class='info'>
                    <p>📌 <strong>كود التحقق المكون من 6 أرقام هو:</strong></p>
                </div>
                <div class='code-box'>
                    <div class='code'>{$code}</div>
                </div>
                <div class='info'>
                    <p>⏱️ <strong>هذا الكود صالح لمدة 10 دقائق</strong></p>
                    <p>🔒 <strong>لا تشارك هذا الكود مع أي شخص</strong></p>
                </div>
                <p style='color: #888; font-size: 14px; text-align: center;'>إذا لم تطلب هذا الكود، يرجى تجاهل هذه الرسالة.</p>
            </div>
            <div class='footer'>
                <p>تم الإرسال من نظام التحقق الآلي - {$smtp['from_name']}</p>
                <p>📧 {$smtp['from_email']}</p>
                <p class='warning'>⚠️ هذا بريد آلي، يرجى عدم الرد على هذه الرسالة</p>
            </div>
        </div>
    </body>
    </html>
    ";

    return sendEmailWithDebug($email, $subject, $htmlMessage, $code);
}
