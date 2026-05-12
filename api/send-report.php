<?php
/**
 * Vanlectric send-report endpoint.
 *
 * Receives a JSON POST with the user's email and their calculation result,
 * renders a branded HTML email, and sends it via SMTP using credentials
 * stored OUTSIDE the public web root in vanlectric_secrets.php.
 *
 * Optionally also subscribes the user to MailerLite if they ticked the
 * marketing consent checkbox. MailerLite failures NEVER block the report.
 *
 * Endpoint contract:
 *   POST application/json
 *   {
 *     "email": "user@example.com",
 *     "calculation": { "wizard": {}, "result": {}, "profile": {} },
 *     "reportConsent": true,
 *     "marketingConsent": false
 *   }
 *
 * Responses:
 *   200 { "success": true }
 *   400 { "success": false, "error": "Invalid request" }
 *   502 { "success": false, "error": "Could not send report" }
 */

declare(strict_types=1);

// ---------- CORS ----------------------------------------------------------
$allowedOrigins = [
    'https://vanlectric.com',
    'https://www.vanlectric.com',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    header('Allow: POST, OPTIONS');
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// ---------- Parse + validate body ----------------------------------------
$raw  = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$email = isset($data['email']) && is_string($data['email']) ? trim($data['email']) : '';
$reportConsent    = !empty($data['reportConsent']) && $data['reportConsent'] === true;
$marketingConsent = !empty($data['marketingConsent']) && $data['marketingConsent'] === true;
$calculation      = $data['calculation'] ?? null;

if ($email === '' || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}
if (!$reportConsent) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}
if (!is_array($calculation)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

// ---------- Load secrets --------------------------------------------------
$secretsPath = '/home/sites/27a/5/51ff282fd6/vanlectric_secrets.php';
if (!is_file($secretsPath) || !is_readable($secretsPath)) {
    error_log('[vanlectric send-report] Secrets file missing or unreadable');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server not configured']);
    exit;
}
$config = require $secretsPath;
if (!is_array($config)) {
    error_log('[vanlectric send-report] Secrets file did not return array');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server not configured']);
    exit;
}

$smtpHost     = (string)($config['SMTP_HOST']       ?? '');
$smtpPort     = (int)   ($config['SMTP_PORT']       ?? 0);
$smtpUser     = (string)($config['SMTP_USERNAME']   ?? '');
$smtpPass     = (string)($config['SMTP_PASSWORD']   ?? '');
$fromEmail    = (string)($config['SMTP_FROM_EMAIL'] ?? '');
$fromName     = (string)($config['SMTP_FROM_NAME']  ?? 'Vanlectric');
$mlApiKey     = (string)($config['MAILERLITE_API_KEY'] ?? '');
$mlGroupId    = (string)($config['MAILERLITE_GROUP_ID'] ?? '');

if ($smtpHost === '' || $smtpPort <= 0 || $fromEmail === '') {
    error_log('[vanlectric send-report] SMTP config incomplete');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server not configured']);
    exit;
}

// ---------- Build HTML email ---------------------------------------------
$html    = vl_render_email_html($calculation);
$subject = 'Your Vanlectric electrical system report';

// ---------- Send via SMTP -------------------------------------------------
$sent = vl_smtp_send(
    $smtpHost,
    $smtpPort,
    $smtpUser,
    $smtpPass,
    $fromEmail,
    $fromName,
    $email,
    $subject,
    $html,
    $errMsg
);

if (!$sent) {
    error_log('[vanlectric send-report] SMTP send failed: ' . $errMsg);
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'Could not send report']);
    exit;
}

// ---------- Optional MailerLite opt-in -----------------------------------
// Best-effort: never block the report response on MailerLite issues.
if ($marketingConsent && $mlApiKey !== '' && $mlGroupId !== '') {
    vl_mailerlite_subscribe($mlApiKey, $mlGroupId, $email, 'Email report (marketing opt-in)');
}

http_response_code(200);
echo json_encode(['success' => true]);
exit;


// =========================================================================
// Helpers
// =========================================================================

/**
 * Safely pull a scalar out of a nested array using a dot path.
 */
function vl_get($data, string $path, $default = null) {
    if (!is_array($data)) return $default;
    $cur = $data;
    foreach (explode('.', $path) as $part) {
        if (is_array($cur) && array_key_exists($part, $cur)) {
            $cur = $cur[$part];
        } else {
            return $default;
        }
    }
    return $cur;
}

function vl_h($v): string {
    if ($v === null || $v === '' || (is_array($v) && empty($v))) return '';
    if (is_bool($v)) return $v ? 'Yes' : 'No';
    if (is_array($v)) return htmlspecialchars(implode(', ', array_map('strval', $v)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Render a row in a key/value section table. Returns '' if value is empty.
 */
function vl_row(string $label, $value, string $suffix = ''): string {
    if ($value === null || $value === '' || (is_array($value) && empty($value))) return '';
    $val = vl_h($value);
    if ($suffix !== '') $val .= ' ' . htmlspecialchars($suffix, ENT_QUOTES, 'UTF-8');
    $lbl = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    return "<tr>"
         . "<td style=\"padding:6px 12px 6px 0;color:#5b6b5e;font-size:14px;vertical-align:top;\">{$lbl}</td>"
         . "<td style=\"padding:6px 0;color:#1f2d23;font-size:14px;font-weight:600;\">{$val}</td>"
         . "</tr>";
}

/**
 * Render the full HTML email body. Sections are skipped gracefully when the
 * underlying data is missing.
 */
function vl_render_email_html(array $calc): string {
    $wizard  = is_array($calc['wizard']  ?? null) ? $calc['wizard']  : [];
    $result  = is_array($calc['result']  ?? null) ? $calc['result']  : [];
    $profile = is_array($calc['profile'] ?? null) ? $calc['profile'] : [];

    // --- Brand palette (inline only — no external CSS) ---
    $bgPage   = '#f5efe3'; // warm cream
    $bgCard   = '#ffffff';
    $forest   = '#2f4a35'; // forest green
    $forestLt = '#3e6147';
    $text     = '#1f2d23';
    $muted    = '#5b6b5e';
    $border   = '#e3dccb';
    $accent   = '#c9a96b';

    // --- Section: Profile summary ---
    $profileRows  = '';
    $profileRows .= vl_row('Profile',       vl_get($profile, 'name') ?? vl_get($wizard, 'profile'));
    $profileRows .= vl_row('Vehicle',       vl_get($wizard, 'vehicle.type') ?? vl_get($wizard, 'vehicle'));
    $profileRows .= vl_row('Climate',       vl_get($wizard, 'climate'));
    $profileRows .= vl_row('Season use',    vl_get($wizard, 'season'));
    $profileRows .= vl_row('Travelers',     vl_get($wizard, 'people'));
    $profileRows .= vl_row('Remote work',   vl_get($wizard, 'remoteWork'));
    $profileRows .= vl_row('Insulation',    vl_get($wizard, 'insulation'));
    $profileRows .= vl_row('Driving style', vl_get($wizard, 'driving'));
    $profileRows .= vl_row('Roof access',   vl_get($wizard, 'roof'));
    $profileRows .= vl_row('Budget',        vl_get($wizard, 'budget'));

    // --- Section: Daily consumption ---
    $consRows  = '';
    $consRows .= vl_row('Daily energy use',     vl_get($result, 'dailyWh'),     'Wh/day');
    $consRows .= vl_row('Daily energy use',     vl_get($result, 'dailyKwh'),    'kWh/day');
    $consRows .= vl_row('Peak load',            vl_get($result, 'peakW'),       'W');
    $consRows .= vl_row('Autonomy target',      vl_get($result, 'autonomyDays'), 'days');

    // --- Section: Recommended system ---
    $sysRows  = '';
    $sysRows .= vl_row('System voltage',  vl_get($result, 'system.voltage') ?? vl_get($result, 'voltage'), 'V');
    $sysRows .= vl_row('Battery bank',    vl_get($result, 'system.batteryWh') ?? vl_get($result, 'batteryWh'), 'Wh');
    $sysRows .= vl_row('Battery type',    vl_get($result, 'system.batteryType') ?? vl_get($result, 'batteryType'));
    $sysRows .= vl_row('Solar array',     vl_get($result, 'system.solarW') ?? vl_get($result, 'solarW'), 'W');
    $sysRows .= vl_row('MPPT controller', vl_get($result, 'system.mppt') ?? vl_get($result, 'mppt'));
    $sysRows .= vl_row('Inverter',        vl_get($result, 'system.inverterW') ?? vl_get($result, 'inverterW'), 'W');
    $sysRows .= vl_row('DC-DC charger',   vl_get($result, 'system.dcdcA') ?? vl_get($result, 'dcdcA'), 'A');
    $sysRows .= vl_row('Shore charger',   vl_get($result, 'system.shoreChargerA') ?? vl_get($result, 'shoreChargerA'), 'A');

    // --- Section: Shore-only appliances ---
    $shoreItems = vl_get($result, 'shoreAppliances');
    if (!is_array($shoreItems)) $shoreItems = vl_get($wizard, 'appliances.shore');
    $shoreHtml = '';
    if (is_array($shoreItems) && !empty($shoreItems)) {
        $items = '';
        foreach ($shoreItems as $it) {
            $label = is_array($it) ? (string)($it['name'] ?? $it['label'] ?? '') : (string)$it;
            if ($label === '') continue;
            $items .= '<li style="margin:4px 0;color:' . $text . ';font-size:14px;">' . vl_h($label) . '</li>';
        }
        if ($items !== '') {
            $shoreHtml = '<ul style="margin:8px 0 0;padding-left:20px;">' . $items . '</ul>';
        }
    }

    // --- Section: Warnings ---
    $warnings = vl_get($result, 'warnings');
    $warningsHtml = '';
    if (is_array($warnings) && !empty($warnings)) {
        $items = '';
        foreach ($warnings as $w) {
            $msg = is_array($w) ? (string)($w['message'] ?? $w['text'] ?? '') : (string)$w;
            if ($msg === '') continue;
            $items .= '<li style="margin:6px 0;color:#7a3b13;font-size:14px;line-height:1.5;">' . vl_h($msg) . '</li>';
        }
        if ($items !== '') {
            $warningsHtml = '<ul style="margin:8px 0 0;padding-left:20px;">' . $items . '</ul>';
        }
    }

    // --- Section: Cost summary ---
    $costRows  = '';
    $costRows .= vl_row('Estimated total',    vl_get($result, 'cost.total')    ?? vl_get($result, 'totalCost'),    vl_get($result, 'cost.currency') ?? '');
    $costRows .= vl_row('Battery cost',       vl_get($result, 'cost.battery'));
    $costRows .= vl_row('Solar cost',         vl_get($result, 'cost.solar'));
    $costRows .= vl_row('Inverter cost',      vl_get($result, 'cost.inverter'));
    $costRows .= vl_row('Charger cost',       vl_get($result, 'cost.charger'));
    $costRows .= vl_row('Wiring & install',   vl_get($result, 'cost.install'));

    // --- Build the body sections only if they have content ---
    $section = function (string $title, string $innerHtml, bool $emphasize = false) use ($forest, $border, $bgCard, $accent) {
        if (trim($innerHtml) === '') return '';
        $borderColor = $emphasize ? $accent : $border;
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px;background:' . $bgCard . ';border:1px solid ' . $borderColor . ';border-radius:8px;">'
             . '<tr><td style="padding:18px 20px;">'
             . '<h2 style="margin:0 0 10px;color:' . $forest . ';font-size:16px;font-family:Georgia,\'Times New Roman\',serif;">' . vl_h($title) . '</h2>'
             . $innerHtml
             . '</td></tr></table>';
    };

    $profileSection  = $profileRows  !== '' ? $section('Your profile',         '<table role="presentation" cellpadding="0" cellspacing="0">' . $profileRows . '</table>') : '';
    $consSection     = $consRows     !== '' ? $section('Daily consumption',    '<table role="presentation" cellpadding="0" cellspacing="0">' . $consRows    . '</table>') : '';
    $sysSection      = $sysRows      !== '' ? $section('Recommended system',   '<table role="presentation" cellpadding="0" cellspacing="0">' . $sysRows     . '</table>') : '';
    $shoreSection    = $shoreHtml    !== '' ? $section('Shore-power appliances', $shoreHtml) : '';
    $warnSection     = $warningsHtml !== '' ? $section('Important notes',      $warningsHtml, true) : '';
    $costSection     = $costRows     !== '' ? $section('Cost summary',         '<table role="presentation" cellpadding="0" cellspacing="0">' . $costRows . '</table>') : '';

    $cta = 'https://vanlectric.com/';

    return <<<HTML
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Your Vanlectric electrical system report</title></head>
<body style="margin:0;padding:0;background:{$bgPage};font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;color:{$text};">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{$bgPage};">
  <tr><td align="center" style="padding:24px 12px;">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">
      <tr><td style="padding:8px 4px 20px;">
        <div style="font-family:Georgia,'Times New Roman',serif;font-size:26px;color:{$forest};letter-spacing:0.5px;">Vanlectric</div>
        <div style="height:3px;width:48px;background:{$accent};margin-top:6px;"></div>
      </td></tr>

      <tr><td style="padding:0 4px 16px;">
        <p style="margin:0;color:{$text};font-size:15px;line-height:1.6;">
          Here is your campervan electrical system report based on your Vanlectric calculation.
        </p>
      </td></tr>

      <tr><td style="padding:0 4px;">
        {$profileSection}
        {$consSection}
        {$sysSection}
        {$shoreSection}
        {$warnSection}
        {$costSection}
      </td></tr>

      <tr><td align="center" style="padding:8px 4px 24px;">
        <a href="{$cta}" style="display:inline-block;background:{$forest};color:#ffffff;text-decoration:none;font-weight:600;padding:12px 22px;border-radius:6px;font-size:14px;">
          Continue planning on Vanlectric
        </a>
      </td></tr>

      <tr><td style="padding:16px 4px 0;border-top:1px solid {$border};">
        <p style="margin:0;color:{$muted};font-size:12px;line-height:1.5;">
          This report was generated because you requested it on Vanlectric.
        </p>
      </td></tr>
    </table>
  </td></tr>
</table>
</body></html>
HTML;
}

/**
 * Minimal SMTP client supporting STARTTLS (port 587) and implicit TLS (465).
 * Returns true on success, false on failure (with $errMsg populated).
 *
 * Auth: AUTH LOGIN if username + password provided.
 * Body: HTML email with quoted-printable encoding to keep lines <= 76 chars.
 */
function vl_smtp_send(
    string $host,
    int $port,
    string $user,
    string $pass,
    string $fromEmail,
    string $fromName,
    string $toEmail,
    string $subject,
    string $html,
    ?string &$errMsg = null
): bool {
    $errMsg = null;
    $timeout = 20;
    $useImplicitTls = ($port === 465);
    $remote = ($useImplicitTls ? 'tls://' : '') . $host . ':' . $port;

    $errno = 0; $errstr = '';
    $sock = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
    if (!$sock) {
        $errMsg = "connect failed: $errstr ($errno)";
        return false;
    }
    stream_set_timeout($sock, $timeout);

    $read = function () use ($sock, &$errMsg) {
        $data = '';
        while (!feof($sock)) {
            $line = fgets($sock, 1024);
            if ($line === false) { $errMsg = 'read failed'; return false; }
            $data .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return $data;
    };
    $write = function (string $cmd) use ($sock, &$errMsg): bool {
        if (fwrite($sock, $cmd . "\r\n") === false) { $errMsg = 'write failed'; return false; }
        return true;
    };
    $expect = function (string $code, $resp) use (&$errMsg): bool {
        if ($resp === false || strncmp((string)$resp, $code, strlen($code)) !== 0) {
            $errMsg = 'unexpected SMTP response: ' . trim((string)$resp);
            return false;
        }
        return true;
    };

    // Greeting
    if (!$expect('220', $read())) { fclose($sock); return false; }

    $ehloHost = $_SERVER['SERVER_NAME'] ?? 'vanlectric.com';

    // EHLO
    if (!$write("EHLO {$ehloHost}")) { fclose($sock); return false; }
    if (!$expect('250', $read()))    { fclose($sock); return false; }

    // STARTTLS for port 587-style
    if (!$useImplicitTls) {
        if (!$write('STARTTLS')) { fclose($sock); return false; }
        if (!$expect('220', $read())) { fclose($sock); return false; }
        if (!@stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            $errMsg = 'STARTTLS negotiation failed';
            fclose($sock); return false;
        }
        if (!$write("EHLO {$ehloHost}")) { fclose($sock); return false; }
        if (!$expect('250', $read()))    { fclose($sock); return false; }
    }

    // AUTH LOGIN
    if ($user !== '' && $pass !== '') {
        if (!$write('AUTH LOGIN')) { fclose($sock); return false; }
        if (!$expect('334', $read())) { fclose($sock); return false; }
        if (!$write(base64_encode($user))) { fclose($sock); return false; }
        if (!$expect('334', $read())) { fclose($sock); return false; }
        if (!$write(base64_encode($pass))) { fclose($sock); return false; }
        if (!$expect('235', $read())) { fclose($sock); return false; }
    }

    // Envelope
    if (!$write('MAIL FROM:<' . $fromEmail . '>')) { fclose($sock); return false; }
    if (!$expect('250', $read())) { fclose($sock); return false; }
    if (!$write('RCPT TO:<' . $toEmail . '>')) { fclose($sock); return false; }
    $rcpt = $read();
    if (!($rcpt !== false && (strncmp($rcpt, '250', 3) === 0 || strncmp($rcpt, '251', 3) === 0))) {
        $errMsg = 'RCPT rejected: ' . trim((string)$rcpt);
        fclose($sock); return false;
    }
    if (!$write('DATA')) { fclose($sock); return false; }
    if (!$expect('354', $read())) { fclose($sock); return false; }

    // Headers
    $boundary = 'vl_' . bin2hex(random_bytes(8));
    $fromHeader = vl_format_address($fromEmail, $fromName);
    $headers = [];
    $headers[] = 'Date: ' . date('r');
    $headers[] = 'From: ' . $fromHeader;
    $headers[] = 'To: <' . $toEmail . '>';
    $headers[] = 'Subject: ' . vl_encode_header($subject);
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
    $headers[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@vanlectric.com>';
    $headers[] = 'X-Mailer: Vanlectric/1.0';

    // Plain-text fallback
    $plain = "Your Vanlectric electrical system report\r\n\r\n"
           . "Here is your campervan electrical system report based on your Vanlectric calculation.\r\n\r\n"
           . "Continue planning: https://vanlectric.com/\r\n\r\n"
           . "This report was generated because you requested it on Vanlectric.\r\n";

    $body  = '';
    $body .= '--' . $boundary . "\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= quoted_printable_encode($plain) . "\r\n";
    $body .= '--' . $boundary . "\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= quoted_printable_encode($html) . "\r\n";
    $body .= '--' . $boundary . "--\r\n";

    $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;

    // Dot-stuff per RFC 5321 §4.5.2
    $message = preg_replace('/^\./m', '..', $message);

    if (fwrite($sock, $message . "\r\n.\r\n") === false) {
        $errMsg = 'DATA write failed';
        fclose($sock); return false;
    }
    if (!$expect('250', $read())) { fclose($sock); return false; }

    @$write('QUIT');
    fclose($sock);
    return true;
}

function vl_format_address(string $email, string $name): string {
    $name = trim($name);
    if ($name === '') return '<' . $email . '>';
    return vl_encode_header($name) . ' <' . $email . '>';
}

function vl_encode_header(string $value): string {
    if (preg_match('/[^\x20-\x7e]/', $value)) {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
    return $value;
}

/**
 * Best-effort MailerLite opt-in. Errors are logged server-side and never
 * surfaced to the client.
 */
function vl_mailerlite_subscribe(string $apiKey, string $groupId, string $email, string $source): void {
    $payload = [
        'email'  => $email,
        'groups' => [$groupId],
        'fields' => ['source' => $source],
    ];
    $ch = curl_init('https://connect.mailerlite.com/api/subscribers');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $resp   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $status < 200 || $status >= 300) {
        error_log(sprintf(
            '[vanlectric send-report] MailerLite opt-in failed status=%d err=%s body=%s',
            $status, $err, is_string($resp) ? substr($resp, 0, 300) : ''
        ));
    }
}
