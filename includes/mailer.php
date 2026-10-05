<?php
/**
 * mailer.php
 * ----------
 * Sends real emails through SMTP using PHPMailer (installed with Composer).
 * The SMTP server, login and sender come from config/.env.
 *
 * Every function returns '' when the email was handed to the SMTP server
 * successfully, or an error message. We never claim an email was sent
 * when it was not.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

/** Are the minimum SMTP settings filled in? */
function smtp_configured(): bool
{
    return SMTP_HOST !== '' && SMTP_PORT > 0 && filter_var(MAIL_FROM, FILTER_VALIDATE_EMAIL);
}

/** Message shown when SMTP has not been set up yet. */
function smtp_missing_message(): string
{
    return 'Email is not set up on this server yet, so no email was sent. '
        . 'Developer: fill in SMTP_HOST, SMTP_USERNAME, SMTP_PASSWORD and MAIL_FROM in config/.env (see config/.env.example).';
}

/**
 * Reserved example domains (example.com, .test, ...) can never receive mail.
 * The demo accounts use them on purpose so that no real person is emailed.
 */
function is_reserved_email(string $email): bool
{
    $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
    return in_array($domain, ['example.com', 'example.org', 'example.net'], true)
        || preg_match('/\.(test|example|invalid|localhost)$/', $domain) === 1;
}

/** Reasons an email cannot be sent at all (checked before creating any token). '' = OK. */
function mail_problem(string $toEmail): string
{
    if (is_reserved_email($toEmail)) {
        return 'This is a demo account with a reserved example address (' . $toEmail . '), so no email can be delivered to it.';
    }
    if (!smtp_configured()) {
        return smtp_missing_message();
    }
    return '';
}

/**
 * Send one email. Returns '' on success or an error message.
 */
function send_mail(string $toEmail, string $toName, string $subject, string $html, string $text): string
{
    if ($problem = mail_problem($toEmail)) {
        return $problem;
    }

    $mail = new PHPMailer(true);   // true = throw exceptions on errors
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->Port       = SMTP_PORT;
        $mail->SMTPAuth   = SMTP_USERNAME !== '';
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->Timeout    = 15;
        if (SMTP_ENCRYPTION === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif (SMTP_ENCRYPTION === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure  = '';
            $mail->SMTPAutoTLS = false;   // only for local test servers
        }

        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $text;
        $mail->send();
        return '';
    } catch (MailException $ex) {
        // Full detail goes to the log; the screen shows detail only in debug mode.
        error_log('[' . date('Y-m-d H:i:s') . '] Mail to ' . $toEmail . ' failed: ' . $mail->ErrorInfo);
        return DEBUG_MODE
            ? 'The email could not be sent: ' . $mail->ErrorInfo
            : 'The email could not be sent right now. Please try again in a few minutes. (Developer: see logs/error.log)';
    }
}

/** Simple branded HTML email with one big button. */
function email_template(string $name, string $intro, string $buttonText, string $link, string $outro): string
{
    $e = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    return '<!DOCTYPE html><html><body style="margin:0;background:#f7f9f9;font-family:Segoe UI,Arial,sans-serif;color:#1b1c1c">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:32px 12px"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border:1px solid #e4e8e6;border-radius:16px">'
        . '<tr><td style="padding:32px 32px 8px;font-size:26px;font-weight:800;color:#006a4e">Chol Ghuri</td></tr>'
        . '<tr><td style="padding:8px 32px;font-size:16px;line-height:1.6;color:#3f4944">'
        . '<p>Hello ' . $e($name) . ',</p>' . $intro
        . '<p style="text-align:center;margin:28px 0"><a href="' . $e($link) . '" style="background:#006a4e;color:#ffffff;text-decoration:none;font-weight:600;padding:14px 28px;border-radius:8px;display:inline-block">' . $e($buttonText) . '</a></p>'
        . '<p style="font-size:13px;color:#6f7973">If the button does not work, copy this link into your browser:<br><a href="' . $e($link) . '" style="color:#006a4e;word-break:break-all">' . $e($link) . '</a></p>'
        . $outro . '<p>Chol Ghuri</p></td></tr>'
        . '</table></td></tr></table></body></html>';
}

/**
 * Create a fresh verification token and email the link.
 * Returns '' on success or an error message.
 */
function send_verification_email(array $user): string
{
    if ($problem = mail_problem($user['email'])) {
        return $problem;   // nothing can be sent, so no token is created
    }
    $link = create_verification_link((int) $user['id']);

    $html = email_template(
        $user['full_name'],
        '<p>Welcome to Chol Ghuri.</p><p>Please click the button below to verify your account:</p>',
        'Verify My Email',
        $link,
        '<p>This link expires in ' . VERIFY_TOKEN_HOURS . ' hours and can be used once.</p><p>If you did not create this account, you can ignore this email.</p>'
    );
    $text = "Hello {$user['full_name']},\n\nWelcome to Chol Ghuri.\n\nPlease open this link to verify your account:\n$link\n\n"
          . 'This link expires in ' . VERIFY_TOKEN_HOURS . " hours and can be used once.\n\nIf you did not create this account, you can ignore this email.\n\nChol Ghuri";

    $error = send_mail($user['email'], $user['full_name'], 'Verify your Chol Ghuri account', $html, $text);
    if ($error) {
        // Nothing was sent: forget the token and allow an immediate retry.
        db()->prepare('UPDATE users SET verify_token_hash = NULL, verify_token_expires = NULL, verify_sent_at = NULL WHERE id = ?')
            ->execute([$user['id']]);
    }
    return $error;
}

/** Create a password reset token and email the link. '' on success. */
function send_password_reset_email(array $user): string
{
    if ($problem = mail_problem($user['email'])) {
        return $problem;
    }
    $link = create_reset_link((int) $user['id']);

    $html = email_template(
        $user['full_name'],
        '<p>We received a request to reset your Chol Ghuri password.</p><p>Click the button below to choose a new password:</p>',
        'Reset My Password',
        $link,
        '<p>This link expires in ' . RESET_TOKEN_MINUTES . ' minutes and can be used once.</p><p>If you did not ask for this, you can ignore this email. Your password will not change.</p>'
    );
    $text = "Hello {$user['full_name']},\n\nWe received a request to reset your Chol Ghuri password.\n\nOpen this link to choose a new password:\n$link\n\n"
          . 'This link expires in ' . RESET_TOKEN_MINUTES . " minutes.\n\nIf you did not ask for this, you can ignore this email.\n\nChol Ghuri";

    $error = send_mail($user['email'], $user['full_name'], 'Reset your Chol Ghuri password', $html, $text);
    if ($error) {
        db()->prepare('UPDATE users SET reset_token_hash = NULL, reset_token_expires = NULL WHERE id = ?')->execute([$user['id']]);
    }
    return $error;
}
