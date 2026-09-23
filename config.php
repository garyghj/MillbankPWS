<?php
/*
 WXSIM Forecast vs Weather Underground Actuals

 Developed by MillbankPWS
 Munlochy, Scotland

 Free for personal, amateur meteorological, educational
 and other non-commercial use.

 Commercial use, resale or use for profit is not permitted
 without prior permission from MillbankPWS.

 See LICENSE.txt for full licence terms.
*/

declare(strict_types=1);

define('BASE_DIR', __DIR__);
define('DATA_DIR', BASE_DIR . '/data');
define('WEEKS_DIR', DATA_DIR . '/weeks');
define('LOG_DIR', BASE_DIR . '/logs');
define('SETTINGS_FILE', DATA_DIR . '/settings.json');

function wxfa_default_settings(): array
{
    return [
        'SITE_NAME' => '',
        'SITE_LOCATION' => '',
        'STATION_TIMEZONE' => '',
        'WXSIM_LATEST_CSV' => '',
        'WXSIM_OUTPUT_UNITS' => '',
        'FORECAST_START_DAY' => '',
        'WU_STATION_ID' => '',
        'WU_API_KEY' => '',
        'DISPLAY_UNITS' => '',
        'ADMIN_PASSWORD_HASH' => '',
    ];
}

function wxfa_load_settings(): array
{
    $defaults = wxfa_default_settings();
    if (!is_file(SETTINGS_FILE)) return $defaults;
    $raw = @file_get_contents(SETTINGS_FILE);
    if ($raw === false || trim($raw) === '') return $defaults;
    $saved = json_decode($raw, true);
    if (!is_array($saved)) return $defaults;
    return array_merge($defaults, array_intersect_key($saved, $defaults));
}

$WXFA_SETTINGS = wxfa_load_settings();
foreach ($WXFA_SETTINGS as $name => $value) {
    if (defined($name)) continue;
    /* Keep the setup form blank on a new installation, but use UTC as a safe
       runtime fallback until the user selects a valid station time zone. */
    if ($name === 'STATION_TIMEZONE' && trim((string)$value) === '') {
        define($name, 'UTC');
    } else {
        define($name, (string)$value);
    }
}

if (!@date_default_timezone_set((string)STATION_TIMEZONE)) {
    date_default_timezone_set('UTC');
}


/* -------------------------------------------------------------------------
   Setup & Tests administrator authentication

   No browser session cookie is required. After the administrator password is
   verified, a short authentication token is carried only in protected POST
   forms. The password itself is never stored in the scripts. CLI/CRON jobs
   bypass browser authentication exactly as before.
   ------------------------------------------------------------------------- */
function wxfa_admin_hash(): string
{
    global $WXFA_SETTINGS;
    return trim((string)($WXFA_SETTINGS['ADMIN_PASSWORD_HASH'] ?? ''));
}

function wxfa_admin_password_configured(): bool
{
    return wxfa_admin_hash() !== '';
}

function wxfa_admin_token(): string
{
    $hash = wxfa_admin_hash();
    if ($hash === '') return '';
    return hash_hmac('sha256', 'wxfa-admin-access-v1', $hash);
}

function wxfa_admin_token_field(): string
{
    $token = wxfa_admin_token();
    return '<input type="hidden" name="WXFA_ADMIN_TOKEN" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

function wxfa_admin_authenticated(): bool
{
    if (PHP_SAPI === 'cli') return true;
    if (!empty($GLOBALS['WXFA_ADMIN_AUTHENTICATED'])) return true;
    $expected = wxfa_admin_token();
    $supplied = (string)($_POST['WXFA_ADMIN_TOKEN'] ?? '');
    return $expected !== '' && $supplied !== '' && hash_equals($expected, $supplied);
}

function wxfa_set_admin_authenticated(bool $authenticated): void
{
    if (PHP_SAPI === 'cli') return;
    $GLOBALS['WXFA_ADMIN_AUTHENTICATED'] = $authenticated;
}

function wxfa_start_admin_session(): void
{
    /* Retained as a compatibility no-op for older index.php versions. */
}

function wxfa_admin_logout(): void
{
    if (PHP_SAPI === 'cli') return;
    $GLOBALS['WXFA_ADMIN_AUTHENTICATED'] = false;
}

function wxfa_require_admin_browser(): void
{
    if (PHP_SAPI === 'cli') return;
    if (!wxfa_admin_password_configured() || !wxfa_admin_authenticated()) {
        http_response_code(403);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>Administrator access required</title><style>body{font-family:Segoe UI,Arial,sans-serif;background:#eef2f6;color:#1d2733;margin:0}.box{max-width:650px;margin:70px auto;background:#fff;border:1px solid #d8e0e8;border-radius:12px;padding:24px}a{color:#1769aa;font-weight:700}</style></head><body><div class="box">';
        echo '<h1>Administrator access required</h1><p>This action is available only from the protected Setup &amp; Tests page.</p>';
        echo '<p><a href="index.php?page=setup">Open Setup &amp; Tests</a></p></div></body></html>';
        exit;
    }
}
