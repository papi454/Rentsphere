<?php
/**
 * RentSphere - Shared Functions
 */

// ---------------------------------------------------------
// Auth Helpers
// ---------------------------------------------------------
function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

function current_user(): ?array
{
    if (!is_logged_in()) return null;
    static $cached = null;
    if ($cached !== null) return $cached;

    $db = Database::getConnection();
    $stmt = $db->prepare("SELECT id, company_id, role, first_name, last_name, email, photo_path
                           FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute([$_SESSION['user_id']]);
    $cached = $stmt->fetch() ?: null;
    return $cached;
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect(APP_URL . '/auth/login.php');
    }
}

function require_role(array $roles): void
{
    require_login();
    $user = current_user();
    if (!$user || !in_array($user['role'], $roles, true)) {
        http_response_code(403);
        die('Access denied. You do not have permission to view this page.');
    }
}

// ---------------------------------------------------------
// Activity / Audit Logging
// ---------------------------------------------------------
function log_activity(string $action, string $description = '', ?int $userId = null, ?int $companyId = null): void
{
    try {
        $db = Database::getConnection();
        $userId = $userId ?? ($_SESSION['user_id'] ?? null);
        $companyId = $companyId ?? ($_SESSION['company_id'] ?? null);
        $stmt = $db->prepare("INSERT INTO activity_logs (user_id, company_id, action, description, ip_address)
                               VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $companyId, $action, $description, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $e) {
        error_log('log_activity failed: ' . $e->getMessage());
    }
}

function log_login_attempt(int $userId, string $status): void
{
    $db = Database::getConnection();
    $stmt = $db->prepare("INSERT INTO login_history (user_id, ip_address, user_agent, status)
                           VALUES (?, ?, ?, ?)");
    $stmt->execute([$userId, $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null, $status]);
}

// ---------------------------------------------------------
// Notifications
// ---------------------------------------------------------
function create_notification(int $companyId, string $type, string $title, string $message,
                              ?int $userId = null, ?int $tenantId = null): void
{
    $db = Database::getConnection();
    $stmt = $db->prepare("INSERT INTO notifications (company_id, recipient_user_id, recipient_tenant_id, type, title, message)
                           VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$companyId, $userId, $tenantId, $type, $title, $message]);
}

function unread_notification_count(int $userId): int
{
    $db = Database::getConnection();
    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

// ---------------------------------------------------------
// Rate Limiting / Brute Force Protection
// ---------------------------------------------------------
function is_account_locked(array $user): bool
{
    return !empty($user['locked_until']) && strtotime($user['locked_until']) > time();
}

function register_failed_login(int $userId): void
{
    $db = Database::getConnection();
    $stmt = $db->prepare("SELECT failed_login_attempts FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $attempts = (int) $stmt->fetchColumn() + 1;

    if ($attempts >= MAX_LOGIN_ATTEMPTS) {
        $lockUntil = date('Y-m-d H:i:s', time() + LOCKOUT_DURATION_SECONDS);
        $stmt = $db->prepare("UPDATE users SET failed_login_attempts = ?, locked_until = ? WHERE id = ?");
        $stmt->execute([$attempts, $lockUntil, $userId]);
    } else {
        $stmt = $db->prepare("UPDATE users SET failed_login_attempts = ? WHERE id = ?");
        $stmt->execute([$attempts, $userId]);
    }
}

function reset_failed_logins(int $userId): void
{
    $db = Database::getConnection();
    $stmt = $db->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?");
    $stmt->execute([$userId]);
}

// ---------------------------------------------------------
// Formatting
// ---------------------------------------------------------
function money(float $amount): string
{
    return number_format($amount, 2);
}

function format_date(?string $date, string $format = 'M j, Y'): string
{
    if (empty($date)) return '—';
    return date($format, strtotime($date));
}

// ---------------------------------------------------------
// Branded Email Template
// Email clients strip <link> stylesheets and most CSS, so
// everything here is inline styles / table-safe HTML.
// ---------------------------------------------------------
function build_email_html(string $heading, string $bodyHtml, ?string $ctaText = null, ?string $ctaUrl = null): string
{
    $cta = '';
    if ($ctaText && $ctaUrl) {
        $cta = '<tr><td style="padding:8px 0 4px;">
            <a href="' . e($ctaUrl) . '" style="display:inline-block;background:linear-gradient(135deg,#2563EB,#4F46E5,#06B6D4);
                color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;padding:13px 28px;border-radius:10px;">
                ' . e($ctaText) . '
            </a>
        </td></tr>';
    }

    return '
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F5F7FB;padding:32px 0;font-family:Arial,Helvetica,sans-serif;">
        <tr><td align="center">
            <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(16,24,40,0.08);">
                <tr>
                    <td style="background:linear-gradient(135deg,#2563EB 0%,#4F46E5 55%,#06B6D4 100%);padding:32px 36px;">
                        <table role="presentation" cellpadding="0" cellspacing="0"><tr>
                            <td style="width:36px;height:36px;background:rgba(255,255,255,0.25);border-radius:9px;text-align:center;vertical-align:middle;color:#fff;font-family:Arial,sans-serif;font-weight:800;font-size:16px;">R</td>
                            <td style="padding-left:10px;color:#ffffff;font-size:19px;font-weight:800;font-family:Arial,sans-serif;">' . e(APP_NAME) . '</td>
                        </tr></table>
                    </td>
                </tr>
                <tr>
                    <td style="padding:36px 36px 8px;">
                        <h2 style="margin:0 0 14px;color:#111827;font-size:21px;font-family:Arial,sans-serif;">' . e($heading) . '</h2>
                        <div style="color:#374151;font-size:14.5px;line-height:1.7;">' . $bodyHtml . '</div>
                    </td>
                </tr>
                <tr><td style="padding:0 36px 8px;">
                    <table role="presentation" cellpadding="0" cellspacing="0">' . $cta . '</table>
                </td></tr>
                <tr>
                    <td style="padding:28px 36px 32px;">
                        <p style="margin:0;color:#9AA4B8;font-size:12px;">If you did not request this, you can safely ignore this email.</p>
                    </td>
                </tr>
                <tr>
                    <td style="background:#F5F7FB;padding:18px 36px;text-align:center;">
                        <p style="margin:0;color:#9AA4B8;font-size:11.5px;">&copy; ' . date('Y') . ' ' . e(APP_NAME) . ' &middot; ' . e(APP_TAGLINE) . '</p>
                    </td>
                </tr>
            </table>
        </td></tr>
    </table>';
}

function otp_code_block(string $code): string
{
    return '<table role="presentation" cellpadding="0" cellspacing="0"><tr>' .
        implode('', array_map(function ($digit) {
            return '<td style="width:40px;height:52px;background:#F5F7FB;border:1.5px solid #E5E9F0;border-radius:8px;
                text-align:center;vertical-align:middle;font-size:22px;font-weight:800;color:#2563EB;font-family:Arial,sans-serif;margin-right:6px;">' . e($digit) . '</td><td style="width:6px;"></td>';
        }, str_split($code))) .
        '</tr></table>';
}


// Requires PHPMailer installed via Composer in /vendor,
// or the 3 PHPMailer source files placed in /vendor/phpmailer/.
// ---------------------------------------------------------
function send_email(string $toEmail, string $toName, string $subject, string $htmlBody): bool
{
    require_once ROOT_PATH . '/vendor/autoload.php'; // composer autoload

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = MAIL_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = MAIL_USERNAME;
        $mail->Password = MAIL_PASSWORD;
        $mail->SMTPSecure = MAIL_ENCRYPTION;
        $mail->Port = MAIL_PORT;

        $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Mail error: ' . ($mail->ErrorInfo ?? $e->getMessage()));
        return false;
    }
}

// ---------------------------------------------------------
// Slugs (used for company tenant sign-up links)
// ---------------------------------------------------------
function slugify(string $text): string
{
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = trim($text, '-');
    $text = strtolower($text);
    $text = preg_replace('~[^-\w]+~', '', $text);
    return $text !== '' ? $text : 'company';
}

function generate_unique_company_slug(PDO $db, string $companyName, int $excludeId = 0): string
{
    $base = slugify($companyName);
    $slug = $base;
    $i = 2;
    while (true) {
        $stmt = $db->prepare("SELECT id FROM companies WHERE slug = ? AND id != ?");
        $stmt->execute([$slug, $excludeId]);
        if (!$stmt->fetch()) return $slug;
        $slug = $base . '-' . $i;
        $i++;
    }
}

// ---------------------------------------------------------
// OTP Generation
// ---------------------------------------------------------
function generate_otp(): string
{
    return (string) random_int(100000, 999999);
}
