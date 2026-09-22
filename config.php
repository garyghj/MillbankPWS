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
