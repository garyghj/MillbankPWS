<?php
/*
 WXSIM Forecast vs Weather Underground Actuals

 Combined browser interface
 Developed by MillbankPWS
 Munlochy, Scotland

 Free for personal, amateur meteorological, educational
 and other non-commercial use.

 Commercial use, resale or use for profit is not permitted
 without prior permission from MillbankPWS.

 See LICENSE.txt for full licence terms.
*/

declare(strict_types=1);

$page = isset($_GET['page']) ? strtolower(trim((string)$_GET['page'])) : 'stage1';
$aliases = [
    '7day' => 'stage1',
    'chart' => 'stage1',
    'detail' => 'detailed',
    'accuracy' => 'stage2',
    'cloudcover' => 'cloud',
    'tests' => 'setup',
];
if (isset($aliases[$page])) $page = $aliases[$page];
$validPages = ['stage1','detailed','stage2','cloud','setup'];
if (!in_array($page, $validPages, true)) $page = 'stage1';

if ($page === 'stage1') {

/*
 * WXSIM CSV Forecast vs Weather Underground Actuals
 * Stage 1 - 7-Day Comparison Chart
 *
 * Reads frozen forecast archives from:
 *     data/weeks/YYYY-MM-DD.json
 *
 * Forecast data is never modified by this page.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/config.php';
/* Shared browser helpers are embedded in this combined index.php. */





function ensure_directories()
{
    $dirs = array(
        DATA_DIR,
        WEEKS_DIR,
        LOG_DIR
    );

    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }
}


function write_log($filename, $message)
{
    ensure_directories();

    $line =
        '[' .
        gmdate('Y-m-d H:i:s') .
        ' UTC] ' .
        $message .
        PHP_EOL;

    @file_put_contents(
        LOG_DIR . '/' . $filename,
        $line,
        FILE_APPEND
    );
}


function load_json_file($filename, $default = array())
{
    if (!is_file($filename)) {
        return $default;
    }

    $text = @file_get_contents($filename);

    if ($text === false || trim($text) === '') {
        return $default;
    }

    $data = json_decode($text, true);

    if (!is_array($data)) {
        return $default;
    }

    return $data;
}


function save_json_file($filename, $data)
{
    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        return false;
    }

    $temp = $filename . '.tmp';

    if (@file_put_contents($temp, $json) === false) {
        return false;
    }

    if (!@rename($temp, $filename)) {

        @unlink($filename);

        if (!@rename($temp, $filename)) {
            @unlink($temp);
            return false;
        }
    }

    return true;
}


function is_http_url($value)
{
    return (
        stripos($value, 'http://') === 0 ||
        stripos($value, 'https://') === 0
    );
}


function get_wxsim_plaintext()
{
    $source = trim(WXSIM_PLAINTEXT);

    if ($source === '') {
        return array(
            'success' => false,
            'error'   => 'WXSIM plaintext.txt location has not been configured.'
        );
    }


    // ------------------------------------------------------------
    // REMOTE HTTP/HTTPS FILE
    // ------------------------------------------------------------

    if (is_http_url($source)) {

        $context = stream_context_create(
            array(
                'http' => array(
                    'timeout'    => 15,
                    'user_agent' => 'WXSIM-Forecast-Comparison/1.0'
                ),
                'https' => array(
                    'timeout' => 15
                )
            )
        );

        $text = @file_get_contents(
            $source,
            false,
            $context
        );

        if ($text === false) {
            return array(
                'success' => false,
                'error'   => 'Unable to download WXSIM plaintext.txt from the configured URL.'
            );
        }

        return array(
            'success' => true,
            'type'    => 'Remote HTTP/HTTPS',
            'source'  => $source,
            'text'    => $text,
            'bytes'   => strlen($text)
        );
    }


    // ------------------------------------------------------------
    // LOCAL SERVER FILE
    // ------------------------------------------------------------

    if (!is_file($source)) {
        return array(
            'success' => false,
            'error'   => 'The configured WXSIM plaintext.txt file does not exist.'
        );
    }

    if (!is_readable($source)) {
        return array(
            'success' => false,
            'error'   => 'The configured WXSIM plaintext.txt file is not readable.'
        );
    }

    $text = @file_get_contents($source);

    if ($text === false) {
        return array(
            'success' => false,
            'error'   => 'Unable to read WXSIM plaintext.txt.'
        );
    }

    return array(
        'success' => true,
        'type'    => 'Local server file',
        'source'  => $source,
        'text'    => $text,
        'bytes'   => strlen($text)
    );
}


function sunday_for_date($date = 'now')
{
    $tz = new DateTimeZone(STATION_TIMEZONE);

    $dt = new DateTime($date, $tz);

    if ((int)$dt->format('w') !== 0) {
        $dt->modify('last sunday');
    }

    return $dt->format('Y-m-d');
}



ensure_directories();

/* -------------------------------------------------------------
   Helpers
------------------------------------------------------------- */

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function valid_archive_name($name)
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $name) === 1;
}

function get_archive_files()
{
    $files = glob(WEEKS_DIR . '/*.json');

    if ($files === false) {
        return array();
    }

    $archives = array();

    foreach ($files as $file) {
        $base = basename($file, '.json');

        if (valid_archive_name($base)) {
            $archives[$base] = $file;
        }
    }

    krsort($archives);

    return $archives;
}

function temperature_display($c)
{
    if ($c === null || $c === '') {
        return null;
    }

    $c = (float)$c;

    if (DISPLAY_UNITS === 'imperial') {
        return ($c * 9 / 5) + 32;
    }

    return $c;
}

function rainfall_display($mm)
{
    if ($mm === null || $mm === '') {
        return null;
    }

    $mm = (float)$mm;

    if (DISPLAY_UNITS === 'imperial') {
        return $mm / 25.4;
    }

    return $mm;
}

function wind_display($kmh)
{
    if ($kmh === null || $kmh === '') {
        return null;
    }

    $kmh = (float)$kmh;

    if (DISPLAY_UNITS === 'uk' || DISPLAY_UNITS === 'imperial') {
        return $kmh * 0.621371192;
    }

    return $kmh;
}

function temperature_unit()
{
    return DISPLAY_UNITS === 'imperial' ? '°F' : '°C';
}

function rainfall_unit()
{
    return DISPLAY_UNITS === 'imperial' ? 'in' : 'mm';
}

function wind_unit()
{
    return (DISPLAY_UNITS === 'uk' || DISPLAY_UNITS === 'imperial')
        ? 'mph'
        : 'km/h';
}

function format_period_date($date)
{
    $timestamp = strtotime($date);

    if ($timestamp === false) {
        return $date;
    }

    return date('j M Y', $timestamp);
}

function display_number($value, $decimals = 1)
{
    if ($value === null) {
        return '—';
    }

    return number_format((float)$value, $decimals);
}


/*
 * Symmetric percentage error (sMAPE-style).
 *
 * This is used as a comparative forecast-error indicator because it
 * remains finite when one value is zero. A perfect match is 0%;
 * the theoretical maximum is 200%.
 *
 * Temperature percentages near 0 °C can still appear large, so the
 * percentage should be interpreted as a comparative error indicator,
 * not as a thermodynamic percentage.
 */
function forecast_error_percent($forecast, $actual)
{
    if ($forecast === null || $actual === null) {
        return null;
    }

    $forecast = (float)$forecast;
    $actual   = (float)$actual;

    $denominator = abs($forecast) + abs($actual);

    if ($denominator == 0.0) {
        return 0.0;
    }

    return (200.0 * abs($forecast - $actual)) / $denominator;
}

function average_available_errors($values)
{
    $valid = array();

    foreach ($values as $value) {
        if ($value !== null && is_numeric($value)) {
            $valid[] = (float)$value;
        }
    }

    if (count($valid) === 0) {
        return null;
    }

    return array_sum($valid) / count($valid);
}

/* -------------------------------------------------------------
   Find available archives
------------------------------------------------------------- */

$archives = get_archive_files();

$error = '';
$selected = '';
$archive = array();

if (count($archives) === 0) {
    $error = 'No frozen forecast archives have been found.';
} else {

    if (
        isset($_GET['period']) &&
        valid_archive_name($_GET['period']) &&
        isset($archives[$_GET['period']])
    ) {
        $selected = $_GET['period'];
    } else {
        reset($archives);
        $selected = key($archives);
    }

    $archive = load_json_file($archives[$selected], array());

    if (
        !is_array($archive) ||
        !isset($archive['days']) ||
        !is_array($archive['days'])
    ) {
        $error = 'The selected archive could not be read or is not valid.';
    }
}

/* -------------------------------------------------------------
   Prepare chart data
------------------------------------------------------------- */

$labels = array();

$forecastHigh = array();
$actualHigh = array();

$forecastLow = array();
$actualLow = array();

$forecastRain = array();
$actualRain = array();

$forecastWind = array();
$actualWind = array();

$forecastGust = array();
$actualGust = array();

$forecastSolar = array();
$actualSolar = array();

$tableRows = array();

$actualDays = 0;
$forecastDaysCount = 0;
$allDailyErrors = array();

if ($error === '') {

    foreach ($archive['days'] as $day) {

        $label = isset($day['label']) ? $day['label'] : '';
        $date  = isset($day['date']) ? $day['date'] : '';

        $forecast = isset($day['forecast']) && is_array($day['forecast'])
            ? $day['forecast']
            : array();

        $actual = isset($day['actual']) && is_array($day['actual'])
            ? $day['actual']
            : array();

        $labels[] = $label;

        $fh = temperature_display(
            array_key_exists('hiC', $forecast) ? $forecast['hiC'] : null
        );

        $ah = temperature_display(
            array_key_exists('hiC', $actual) ? $actual['hiC'] : null
        );

        $fl = temperature_display(
            array_key_exists('loC', $forecast) ? $forecast['loC'] : null
        );

        $al = temperature_display(
            array_key_exists('loC', $actual) ? $actual['loC'] : null
        );

        $fr = rainfall_display(
            array_key_exists('rainMm', $forecast) ? $forecast['rainMm'] : null
        );

        $ar = rainfall_display(
            array_key_exists('rainMm', $actual) ? $actual['rainMm'] : null
        );

        $fw = wind_display(
            array_key_exists('windKmh', $forecast) ? $forecast['windKmh'] : null
        );

        $aw = wind_display(
            array_key_exists('windKmh', $actual) ? $actual['windKmh'] : null
        );

        $fg = wind_display(
            array_key_exists('gustKmh', $forecast) ? $forecast['gustKmh'] : null
        );

        $ag = wind_display(
            array_key_exists('gustKmh', $actual) ? $actual['gustKmh'] : null
        );

        $fs = array_key_exists('solarWm2', $forecast) && $forecast['solarWm2'] !== null
            ? (float)$forecast['solarWm2']
            : null;

        $as = array_key_exists('solarWm2', $actual) && $actual['solarWm2'] !== null
            ? (float)$actual['solarWm2']
            : null;

        $forecastHigh[] = $fh;
        $actualHigh[]   = $ah;

        $forecastLow[] = $fl;
        $actualLow[]   = $al;

        $forecastRain[] = $fr;
        $actualRain[]   = $ar;

        $forecastWind[] = $fw;
        $actualWind[]   = $aw;

        $forecastGust[] = $fg;
        $actualGust[]   = $ag;

        $forecastSolar[] = $fs;
        $actualSolar[]   = $as;

        /*
         * Error percentages are calculated from the archive's internal
         * normalized values (°C, mm, km/h), not the visitor display units.
         */
        $errHigh = forecast_error_percent(
            array_key_exists('hiC', $forecast) ? $forecast['hiC'] : null,
            array_key_exists('hiC', $actual) ? $actual['hiC'] : null
        );

        $errLow = forecast_error_percent(
            array_key_exists('loC', $forecast) ? $forecast['loC'] : null,
            array_key_exists('loC', $actual) ? $actual['loC'] : null
        );

        $errRain = forecast_error_percent(
            array_key_exists('rainMm', $forecast) ? $forecast['rainMm'] : null,
            array_key_exists('rainMm', $actual) ? $actual['rainMm'] : null
        );

        $errWind = forecast_error_percent(
            array_key_exists('windKmh', $forecast) ? $forecast['windKmh'] : null,
            array_key_exists('windKmh', $actual) ? $actual['windKmh'] : null
        );

        $errGust = forecast_error_percent(
            array_key_exists('gustKmh', $forecast) ? $forecast['gustKmh'] : null,
            array_key_exists('gustKmh', $actual) ? $actual['gustKmh'] : null
        );

        $errSolar = forecast_error_percent(
            array_key_exists('solarWm2', $forecast) ? $forecast['solarWm2'] : null,
            array_key_exists('solarWm2', $actual) ? $actual['solarWm2'] : null
        );

        $dailyError = average_available_errors(array(
            $errHigh,
            $errLow,
            $errRain,
            $errWind,
            $errGust,
            $errSolar
        ));

        if ($dailyError !== null) {
            $allDailyErrors[] = $dailyError;
        }

        /*
         * Count a day as having actual observations if at least
         * one principal actual measurement exists.
         */
        if (
            $ah !== null ||
            $al !== null ||
            $ar !== null ||
            $aw !== null ||
            $ag !== null ||
            $as !== null
        ) {
            $actualDays++;
        }

        $tableRows[] = array(
            'label' => $label,
            'date'  => $date,
            'fh'    => $fh,
            'ah'    => $ah,
            'fl'    => $fl,
            'al'    => $al,
            'fr'    => $fr,
            'ar'    => $ar,
            'fw'       => $fw,
            'aw'       => $aw,
            'fg'       => $fg,
            'ag'       => $ag,
            'fs'       => $fs,
            'as'       => $as,
            'errHigh'  => $errHigh,
            'errLow'   => $errLow,
            'errRain'  => $errRain,
            'errWind'  => $errWind,
            'errGust'  => $errGust,
            'errSolar' => $errSolar,
            'dailyErr' => $dailyError
        );
    }

    $forecastDaysCount = count($archive['days']);
}

$weeklyAverageError = average_available_errors($allDailyErrors);

/* -------------------------------------------------------------
   Metadata
------------------------------------------------------------- */

$periodStart = isset($archive['periodStart'])
    ? $archive['periodStart']
    : $selected;

$periodEnd = isset($archive['periodEnd'])
    ? $archive['periodEnd']
    : '';

$startDay = isset($archive['startDay'])
    ? $archive['startDay']
    : FORECAST_START_DAY;

$forecastInit = isset($archive['forecastInit'])
    ? $archive['forecastInit']
    : 'Not recorded';

$forecastCaptured = isset($archive['forecastCapturedLocal'])
    ? $archive['forecastCapturedLocal']
    : 'Not recorded';

$actualLastUpdate =
    isset($archive['actualLastUpdate']) &&
    $archive['actualLastUpdate'] !== null &&
    $archive['actualLastUpdate'] !== ''
        ? $archive['actualLastUpdate']
        : 'No actual observations added yet';

$tempUnit = temperature_unit();
$rainUnit = rainfall_unit();
$windUnit = wind_unit();

/* -------------------------------------------------------------
   JSON for JavaScript
------------------------------------------------------------- */

$jsLabels = json_encode($labels);

$jsForecastHigh = json_encode($forecastHigh);
$jsActualHigh   = json_encode($actualHigh);

$jsForecastLow = json_encode($forecastLow);
$jsActualLow   = json_encode($actualLow);

$jsForecastRain = json_encode($forecastRain);
$jsActualRain   = json_encode($actualRain);

$jsForecastWind = json_encode($forecastWind);
$jsActualWind   = json_encode($actualWind);

$jsForecastGust = json_encode($forecastGust);
$jsActualGust   = json_encode($actualGust);

$jsForecastSolar = json_encode($forecastSolar);
$jsActualSolar   = json_encode($actualSolar);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>
<?php echo h(SITE_NAME); ?> — WXSIM Forecast vs Actual
</title>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

<script>
(function() {
    try {
        const saved = localStorage.getItem('wxsimChartTheme');
        const theme = saved === 'dark' || saved === 'light'
            ? saved
            : (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
                ? 'dark'
                : 'light');

        document.documentElement.setAttribute('data-theme', theme);
    } catch (e) {
        document.documentElement.setAttribute('data-theme', 'light');
    }
})();
</script>

<style>

:root {
    --page-bg: #eef2f6;
    --panel-bg: #ffffff;
    --text: #1d2733;
    --muted: #667482;
    --border: #d8e0e8;
    --heading: #163b5c;
    --accent: #1769aa;
    --actual: #e67e22;
    --forecast: #1769aa;
    --header-start: #163b5c;
    --header-end: #245f8d;
    --control-bg: #ffffff;
    --control-text: #1d2733;
    --control-border: #bdc8d2;
    --control-hover: #f0f5f9;
    --table-head-bg: #f4f7fa;
    --table-head-text: #34495e;
    --row-border: #e5eaf0;
    --forecast-cell: #135d96;
    --actual-cell: #a94f08;
    --notice-bg: #fff8e6;
    --notice-border: #ead39a;
    --notice-text: #68521d;
    --error-bg: #fff0f0;
    --error-border: #e4aaaa;
    --error-text: #8b2020;
    --chart-text: #43515f;
    --chart-axis: #53616e;
    --chart-grid: rgba(100,115,130,.16);
}

html[data-theme="dark"] {
    --page-bg: #10161d;
    --panel-bg: #18212b;
    --text: #e7edf3;
    --muted: #a7b3bf;
    --border: #344251;
    --heading: #9fc9eb;
    --accent: #5fa8e0;
    --header-start: #102b42;
    --header-end: #1d4c70;
    --control-bg: #202c38;
    --control-text: #e7edf3;
    --control-border: #4a5b6c;
    --control-hover: #293746;
    --table-head-bg: #202c38;
    --table-head-text: #d8e4ef;
    --row-border: #2e3b48;
    --forecast-cell: #7ab8e8;
    --actual-cell: #ffb068;
    --notice-bg: #302916;
    --notice-border: #65552b;
    --notice-text: #f0d990;
    --error-bg: #371d20;
    --error-border: #734149;
    --error-text: #f2a6af;
    --chart-text: #c4cfda;
    --chart-axis: #b5c0cb;
    --chart-grid: rgba(190,205,220,.14);
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 0;
    background: var(--page-bg);
    color: var(--text);
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        Roboto,
        Arial,
        sans-serif;
}

.page {
    width: min(1180px, calc(100% - 28px));
    margin: 22px auto 40px auto;
}

.header {
    background: linear-gradient(135deg, var(--header-start), var(--header-end));
    color: white;
    border-radius: 12px;
    padding: 18px 22px;
    box-shadow: 0 3px 12px rgba(0,0,0,.12);
}

.header h1 {
    margin: 0;
    font-size: 24px;
    font-weight: 700;
}

.header .subtitle {
    margin-top: 5px;
    font-size: 16px;
    opacity: .92;
}

.header .period {
    margin-top: 14px;
    font-size: 19px;
    font-weight: 600;
}

.toolbar {
    margin-top: 14px;
    background: var(--panel-bg);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 12px 14px;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.toolbar label {
    font-weight: 600;
    color: var(--table-head-text);
}

.toolbar select,
.toolbar button {
    height: 38px;
    border-radius: 6px;
    border: 1px solid var(--control-border);
    background: var(--control-bg);
    color: var(--control-text);
    padding: 0 12px;
    font-size: 14px;
}

.toolbar button {
    cursor: pointer;
    font-weight: 600;
}

.toolbar button:hover {
    background: var(--control-hover);
}

.toolbar .spacer {
    flex: 1;
}

.status-grid {
    margin-top: 14px;
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
}

.status-card {
    background: var(--panel-bg);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 14px 16px;
}

.status-label {
    color: var(--muted);
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
}

.status-value {
    margin-top: 5px;
    font-size: 15px;
    font-weight: 600;
}

.legend-box {
    margin-top: 14px;
    background: var(--panel-bg);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 13px 16px;
    display: flex;
    align-items: center;
    gap: 26px;
    flex-wrap: wrap;
}

.legend-title {
    font-weight: 700;
    color: var(--table-head-text);
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 700;
}

.legend-swatch {
    width: 34px;
    height: 16px;
    border-radius: 3px;
}

.legend-forecast {
    background: #1769aa;
    border: 2px solid #1769aa;
}

.legend-actual {
    background:
        repeating-linear-gradient(
            135deg,
            #f6d2ae 0,
            #f6d2ae 5px,
            #e67e22 5px,
            #e67e22 9px
        );
    border: 2px solid #a94f08;
}

.legend-note {
    color: var(--muted);
    font-size: 13px;
}

.notice {
    margin-top: 14px;
    background: var(--notice-bg);
    border: 1px solid var(--notice-border);
    border-radius: 10px;
    padding: 12px 15px;
    color: var(--notice-text);
}

.error {
    margin-top: 15px;
    background: var(--error-bg);
    border: 1px solid var(--error-border);
    color: var(--error-text);
    border-radius: 10px;
    padding: 16px;
    font-weight: 600;
}

.charts {
    margin-top: 14px;
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}

.chart-panel {
    background: var(--panel-bg);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 13px;
    min-width: 0;
}

.chart-panel.full {
    grid-column: 1 / -1;
}

.chart-title {
    font-size: 18px;
    font-weight: 700;
    color: var(--heading);
    margin-bottom: 2px;
}

.chart-subtitle {
    color: var(--muted);
    font-size: 13px;
    margin-bottom: 10px;
}

.chart-wrap {
    position: relative;
    height: 250px;
}

.chart-wrap.large {
    height: 280px;
}

.table-panel {
    margin-top: 14px;
    background: var(--panel-bg);
    border: 1px solid var(--border);
    border-radius: 10px;
    overflow: hidden;
}

.table-heading {
    padding: 15px 17px;
    border-bottom: 1px solid var(--border);
}

.table-heading h2 {
    margin: 0;
    color: var(--heading);
    font-size: 18px;
}

.table-scroll {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

th,
td {
    padding: 9px 8px;
    border-bottom: 1px solid var(--row-border);
    text-align: center;
    white-space: nowrap;
}

th {
    background: var(--table-head-bg);
    color: var(--table-head-text);
    font-weight: 700;
}

td:first-child,
th:first-child {
    text-align: left;
    padding-left: 16px;
}

.forecast-cell {
    color: var(--forecast-cell);
    font-weight: 600;
}

.actual-cell {
    color: var(--actual-cell);
    font-weight: 700;
}

.no-value {
    color: #9aa5af;
}

.error-cell {
    font-weight: 700;
    color: var(--text);
}

.daily-error-cell {
    font-weight: 800;
    background: rgba(23, 105, 170, .07);
}

.weekly-average-row td {
    border-top: 2px solid var(--border);
    background: var(--table-head-bg);
    font-weight: 700;
}

.error-note {
    padding: 10px 17px 14px 17px;
    color: var(--muted);
    font-size: 12px;
    line-height: 1.5;
}

.footer {
    margin-top: 14px;
    text-align: center;
    color: var(--muted);
    font-size: 12px;
    line-height: 1.6;
}

.suite-nav {
    margin-top: 10px;
    display: flex;
    gap: 7px;
    flex-wrap: wrap;
}
.suite-nav a {
    text-decoration: none;
    color: #174a70;
    background: #edf7fc;
    border: 1px solid #b8d8e9;
    border-radius: 7px;
    padding: 7px 11px;
    font-size: 12px;
    font-weight: 700;
}
.suite-nav a:hover { background: #dff1fa; }
.suite-nav a.current { background: #1769aa; color: #fff; border-color: #1769aa; }

@media (max-width: 1000px) {

    .status-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .charts {
        grid-template-columns: 1fr;
    }

    .chart-panel.full {
        grid-column: auto;
    }
}

@media (max-width: 600px) {

    .page {
        width: calc(100% - 16px);
        margin-top: 8px;
    }

    .header {
        padding: 18px;
        border-radius: 8px;
    }

    .header h1 {
        font-size: 22px;
    }

    .status-grid {
        grid-template-columns: 1fr;
    }

    .toolbar .spacer {
        display: none;
    }
}

@media print {

    :root,
    html[data-theme="dark"] {
        --page-bg: #ffffff;
        --panel-bg: #ffffff;
        --text: #1d2733;
        --muted: #667482;
        --border: #d8e0e8;
        --heading: #163b5c;
        --control-bg: #ffffff;
        --control-text: #1d2733;
        --table-head-bg: #f4f7fa;
        --table-head-text: #34495e;
        --row-border: #e5eaf0;
        --forecast-cell: #135d96;
        --actual-cell: #a94f08;
    }

    body {
        background: #ffffff;
    }

    .page {
        width: 100%;
        margin: 0;
    }

    .toolbar {
        display: none;
    }

    .header,
    .status-card,
    .legend-box,
    .chart-panel,
    .table-panel {
        box-shadow: none;
    }

    .chart-panel {
        break-inside: avoid;
    }

    .table-panel {
        break-inside: avoid;
    }
}

</style>
</head>

<body>

<div class="page">

    <div class="header">

        <h1>
            <?php echo h(SITE_NAME); ?>
            — WXSIM Forecast vs Actual Weather
        </h1>

        <div class="subtitle">
            <?php echo h(SITE_LOCATION); ?>
        </div>

        <?php if ($error === ''): ?>

            <div class="period">
                <?php echo h($startDay); ?>
                <?php echo h($forecastDaysCount); ?>-Day Forecast:
                <?php echo h(format_period_date($periodStart)); ?>
                –
                <?php echo h(format_period_date($periodEnd)); ?>
            </div>

        <?php endif; ?>

    </div>

    <nav class="suite-nav" aria-label="WXSIM forecast pages">
        <a class="current" href="index.php?page=stage1">7-Day Comparison</a>
        <a href="index.php?page=detailed">Detailed Comparison</a>
        <a href="index.php?page=stage2">Accuracy Dashboard</a>
        <a href="index.php?page=cloud">Cloud Cover</a>
    
        <a href="index.php?page=setup">Setup &amp; Tests</a></nav>

    <?php if ($error !== ''): ?>

        <div class="error">
            <?php echo h($error); ?>
        </div>

    <?php else: ?>

        <div class="toolbar">

            <label for="periodSelect">Archive:</label>

            <select id="periodSelect">
                <?php foreach ($archives as $archiveDate => $archiveFile): ?>

                    <option
                        value="<?php echo h($archiveDate); ?>"
                        <?php echo $archiveDate === $selected ? 'selected' : ''; ?>
                    >
                        <?php echo h(format_period_date($archiveDate)); ?>
                    </option>

                <?php endforeach; ?>
            </select>

            <button type="button" onclick="loadArchive()">
                View
            </button>

            <button type="button" onclick="location.reload()">
                Reload
            </button>

            <div class="spacer"></div>

            <button type="button" id="themeToggle" onclick="toggleTheme()">
                Dark mode
            </button>

            <button type="button" onclick="window.print()">
                Print
            </button>

            <button type="button" onclick="exportPDF()">
                Export PDF
            </button>

        </div>

        <div class="status-grid">

            <div class="status-card">
                <div class="status-label">
                    Forecast initialized
                </div>
                <div class="status-value">
                    <?php echo h($forecastInit); ?>
                </div>
            </div>

            <div class="status-card">
                <div class="status-label">
                    Forecast frozen
                </div>
                <div class="status-value">
                    <?php echo h($forecastCaptured); ?>
                </div>
            </div>

            <div class="status-card">
                <div class="status-label">
                    Actual observations
                </div>
                <div class="status-value">
                    <?php echo h($actualDays); ?> of
                    <?php echo h(count($archive['days'])); ?> days
                </div>
            </div>

            <div class="status-card">
                <div class="status-label">
                    Actuals last updated
                </div>
                <div class="status-value">
                    <?php echo h($actualLastUpdate); ?>
                </div>
            </div>

        </div>

        <div class="legend-box">

            <div class="legend-title">
                Comparison:
            </div>

            <div class="legend-item">
                <span class="legend-swatch legend-forecast"></span>
                FORECAST
            </div>

            <div class="legend-item">
                <span class="legend-swatch legend-actual"></span>
                ACTUAL
            </div>

            <div class="legend-note">
                Forecast values are derived from WXSIM latest.csv and frozen at capture.
                Actual observations are supplied by Weather Underground.
            </div>

        </div>

        <?php if ($actualDays === 0): ?>

            <div class="notice">
                <strong>Forecast only at present.</strong>
                No completed Weather Underground daily observations have
                yet been added to this <?php echo h($forecastDaysCount); ?>-day period. Actual bars will appear
                automatically as the archive is updated.
            </div>

        <?php endif; ?>

        <div class="charts">

            <div class="chart-panel full">

                <div class="chart-title">
                    Maximum &amp; Minimum Temperature
                </div>

                <div class="chart-subtitle">
                    Forecast versus actual daily temperature
                    (<?php echo h($tempUnit); ?>)
                </div>

                <div class="chart-wrap large">
                    <canvas id="temperatureChart"></canvas>
                </div>

            </div>

            <div class="chart-panel">

                <div class="chart-title">
                    Rainfall
                </div>

                <div class="chart-subtitle">
                    Forecast versus actual daily total
                    (<?php echo h($rainUnit); ?>)
                </div>

                <div class="chart-wrap">
                    <canvas id="rainChart"></canvas>
                </div>

            </div>

            <div class="chart-panel">

                <div class="chart-title">
                    Maximum Sustained Wind
                </div>

                <div class="chart-subtitle">
                    Forecast versus actual
                    (<?php echo h($windUnit); ?>)
                </div>

                <div class="chart-wrap">
                    <canvas id="windChart"></canvas>
                </div>

            </div>

            <div class="chart-panel full">

                <div class="chart-title">
                    Maximum Wind Gust
                </div>

                <div class="chart-subtitle">
                    Forecast versus actual
                    (<?php echo h($windUnit); ?>)
                </div>

                <div class="chart-wrap">
                    <canvas id="gustChart"></canvas>
                </div>

            </div>

            <div class="chart-panel full">

                <div class="chart-title">
                    Maximum Solar Radiation
                </div>

                <div class="chart-subtitle">
                    Forecast versus actual daily maximum
                    (W/m²)
                </div>

                <div class="chart-wrap">
                    <canvas id="solarChart"></canvas>
                </div>

            </div>

        </div>

        <div class="table-panel">

            <div class="table-heading">
                <h2><?php echo h($forecastDaysCount); ?>-Day Values</h2>
            </div>

            <div class="table-scroll">

                <table>

                    <thead>
                        <tr>
                            <th rowspan="2">Date</th>

                            <th colspan="3">
                                High <?php echo h($tempUnit); ?>
                            </th>

                            <th colspan="3">
                                Low <?php echo h($tempUnit); ?>
                            </th>

                            <th colspan="3">
                                Rain <?php echo h($rainUnit); ?>
                            </th>

                            <th colspan="3">
                                Wind <?php echo h($windUnit); ?>
                            </th>

                            <th colspan="3">
                                Gust <?php echo h($windUnit); ?>
                            </th>

                            <th colspan="3">
                                Solar W/m²
                            </th>

                            <th rowspan="2">Daily<br>Error %</th>
                        </tr>

                        <tr>
                            <th>Fcst</th>
                            <th>Actual</th>
                            <th>Error %</th>

                            <th>Fcst</th>
                            <th>Actual</th>
                            <th>Error %</th>

                            <th>Fcst</th>
                            <th>Actual</th>
                            <th>Error %</th>

                            <th>Fcst</th>
                            <th>Actual</th>
                            <th>Error %</th>

                            <th>Fcst</th>
                            <th>Actual</th>
                            <th>Error %</th>

                            <th>Fcst</th>
                            <th>Actual</th>
                            <th>Error %</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($tableRows as $row): ?>

                        <tr>

                            <td>
                                <strong><?php echo h($row['label']); ?></strong>
                            </td>

                            <td class="forecast-cell">
                                <?php echo h(display_number($row['fh'], 1)); ?>
                            </td>

                            <td class="actual-cell">
                                <?php
                                echo $row['ah'] === null
                                    ? '<span class="no-value">—</span>'
                                    : h(display_number($row['ah'], 1));
                                ?>
                            </td>

                            <td class="error-cell">
                                <?php
                                echo $row['errHigh'] === null
                                    ? '<span class="no-value">—</span>'
                                    : h(display_number($row['errHigh'], 1)) . '%';
                                ?>
                            </td>

                            <td class="forecast-cell">
                                <?php echo h(display_number($row['fl'], 1)); ?>
                            </td>

                            <td class="actual-cell">
                                <?php
                                echo $row['al'] === null
                                    ? '<span class="no-value">—</span>'
                                    : h(display_number($row['al'], 1));
                                ?>
                            </td>

                            <td class="error-cell">
                                <?php
                                echo $row['errLow'] === null
                                    ? '<span class="no-value">—</span>'
                                    : h(display_number($row['errLow'], 1)) . '%';
                                ?>
                            </td>

                            <td class="forecast-cell">
                                <?php
                                echo $row['fr'] === null
                                    ? '<span class="no-value">—</span>'
                                    : h(display_number($row['fr'], DISPLAY_UNITS === 'imperial' ? 2 : 1));
                                ?>
                            </td>

                            <td class="actual-cell">
                                <?php
                                echo $row['ar'] === null
                                    ? '<span class="no-value">—</span>'
                                    : h(display_number($row['ar'], DISPLAY_UNITS === 'imperial' ? 2 : 1));
                                ?>
                            </td>

                            <td class="error-cell">
                                <?php
                                echo $row['errRain'] === null
                                    ? '<span class="no-value">—</span>'
                                    : h(display_number($row['errRain'], 1)) . '%';
                                ?>
                            </td>

                            <td class="forecast-cell">
                                <?php
                                echo $row['fw'] === null
                                    ? '<span class="no-value">—</span>'
                                    : h(display_number($row['fw'], 1));
                                ?>
                            </td>

                            <td class="actual-cell">
                                <?php
                                echo $row['aw'] === null
                                    ? '<span class="no-value">—</span>'
                                    : h(display_number($row['aw'], 1));
                                ?>
                            </td>

                            <td class="error-cell">
                                <?php
                                echo $row['errWind'] === null
                                    ? '<span class="no-value">—</span>'
                                    : h(display_number($row['errWind'], 1)) . '%';
                                ?>
                            </td>

                            <td class="forecast-cell">
                                <?php
                                echo $row['fg'] === null
                                    ? '<span class="no-value">—</span>'
                                    : h(display_number($row['fg'], 1));
                                ?>
                            </td>

                            <td class="actual-cell">
                                <?php
                                echo $row['ag'] === null
                                    ? '<span class="no-value">—</span>'
                                    : h(display_number($row['ag'], 1));
                                ?>
                            </td>

                            <td class="error-cell">
                                <?php
                                echo $row['errGust'] === null
                                    ? '<span class="no-value">—</span>'
                                    : h(display_number($row['errGust'], 1)) . '%';
                                ?>
                            </td>

                            <td class="forecast-cell">
                                <?php echo $row['fs'] === null ? '<span class="no-value">—</span>' : h(display_number($row['fs'], 1)); ?>
                            </td>

                            <td class="actual-cell">
                                <?php echo $row['as'] === null ? '<span class="no-value">—</span>' : h(display_number($row['as'], 1)); ?>
                            </td>

                            <td class="error-cell">
                                <?php echo $row['errSolar'] === null ? '<span class="no-value">—</span>' : h(display_number($row['errSolar'], 1)) . '%'; ?>
                            </td>

                            <td class="daily-error-cell">
                                <?php
                                echo $row['dailyErr'] === null
                                    ? '<span class="no-value">—</span>'
                                    : h(display_number($row['dailyErr'], 1)) . '%';
                                ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                        <tr class="weekly-average-row">
                            <td colspan="19">Average error for completed days in this forecast period</td>
                            <td>
                                <?php
                                echo $weeklyAverageError === null
                                    ? '<span class="no-value">—</span>'
                                    : h(display_number($weeklyAverageError, 1)) . '%';
                                ?>
                            </td>
                        </tr>

                    </tbody>

                </table>

            </div>

            <div class="error-note">
                Error % uses a symmetric forecast-versus-actual percentage difference
                (0% = exact match; maximum 200%). Daily Error % is the mean of the
                available High, Low, Rain, Wind, Gust and Solar errors for that day.
                Temperature percentages near 0 °C can appear large and should be
                interpreted as a comparative forecast-error indicator.
            </div>

        </div>

        <div class="footer">

            Frozen WXSIM latest.csv forecast compared with Weather Underground
            PWS observations.

            <br>

            Archive:
            <?php echo h(basename($archives[$selected])); ?>
            &nbsp;|&nbsp;
            Display units:
            <?php echo h(DISPLAY_UNITS); ?>
            &nbsp;|&nbsp;
            Station timezone:
            <?php echo h(STATION_TIMEZONE); ?>

            <br>
            WXSIM CSV Forecast vs Weather Underground Actuals — developed by
            MillbankPWS, Munlochy, Scotland

        </div>

    <?php endif; ?>

</div>

<?php if ($error === ''): ?>

<script>

Chart.register(ChartDataLabels);

const chartInstances = [];

function cssVar(name)
{
    return getComputedStyle(document.documentElement)
        .getPropertyValue(name)
        .trim();
}

function currentTheme()
{
    return document.documentElement.getAttribute('data-theme') === 'dark'
        ? 'dark'
        : 'light';
}

function updateThemeButton()
{
    const button = document.getElementById('themeToggle');

    if (!button) {
        return;
    }

    button.textContent =
        currentTheme() === 'dark'
            ? 'Light mode'
            : 'Dark mode';
}

function refreshChartTheme()
{
    const chartText = cssVar('--chart-text');
    const chartAxis = cssVar('--chart-axis');
    const chartGrid = cssVar('--chart-grid');

    chartInstances.forEach(function(chart) {
        if (!chart || !chart.options) {
            return;
        }

        if (chart.options.plugins && chart.options.plugins.legend) {
            chart.options.plugins.legend.labels.color = chartText;
        }

        if (chart.options.plugins && chart.options.plugins.datalabels) {
            chart.options.plugins.datalabels.color = chartText;
        }

        if (chart.options.scales && chart.options.scales.x) {
            chart.options.scales.x.ticks.color = chartText;
        }

        if (chart.options.scales && chart.options.scales.y) {
            chart.options.scales.y.ticks.color = chartText;
            chart.options.scales.y.title.color = chartAxis;
            chart.options.scales.y.grid.color = chartGrid;
        }

        chart.update();
    });
}

function setTheme(theme)
{
    const safeTheme = theme === 'dark' ? 'dark' : 'light';

    document.documentElement.setAttribute('data-theme', safeTheme);

    try {
        localStorage.setItem('wxsimChartTheme', safeTheme);
    } catch (e) {
        // Theme still works for this page view if storage is unavailable.
    }

    updateThemeButton();
    refreshChartTheme();
}

function toggleTheme()
{
    setTheme(currentTheme() === 'dark' ? 'light' : 'dark');
}

updateThemeButton();

const labels = <?php echo $jsLabels; ?>;

const forecastHigh = <?php echo $jsForecastHigh; ?>;
const actualHigh   = <?php echo $jsActualHigh; ?>;

const forecastLow = <?php echo $jsForecastLow; ?>;
const actualLow   = <?php echo $jsActualLow; ?>;

const forecastRain = <?php echo $jsForecastRain; ?>;
const actualRain   = <?php echo $jsActualRain; ?>;

const forecastWind = <?php echo $jsForecastWind; ?>;
const actualWind   = <?php echo $jsActualWind; ?>;

const forecastGust = <?php echo $jsForecastGust; ?>;
const actualGust   = <?php echo $jsActualGust; ?>;

const forecastSolar = <?php echo $jsForecastSolar; ?>;
const actualSolar   = <?php echo $jsActualSolar; ?>;

const tempUnit = <?php echo json_encode($tempUnit); ?>;
const rainUnit = <?php echo json_encode($rainUnit); ?>;
const windUnit = <?php echo json_encode($windUnit); ?>;


/*
 * Strong solid blue = FORECAST
 *
 * Orange with dark border = ACTUAL
 *
 * The actual series deliberately has a substantially different
 * appearance so the two remain distinguishable in print and for
 * users who have difficulty distinguishing similar hues.
 */

const forecastBackground = 'rgba(23, 105, 170, 0.82)';
const forecastBorder     = 'rgb(18, 82, 133)';

const actualBackground   = 'rgba(230, 126, 34, 0.48)';
const actualBorder       = 'rgb(145, 69, 7)';


function valueLabel(unit, decimals = 1)
{
    return function(value) {

        if (value === null || typeof value === 'undefined') {
            return '';
        }

        return Number(value).toFixed(decimals) + ' ' + unit;
    };
}


function standardOptions(unit, beginAtZero)
{
    return {
        responsive: true,
        maintainAspectRatio: false,

        interaction: {
            mode: 'index',
            intersect: false
        },

        plugins: {

            legend: {
                display: true,
                position: 'top',
                labels: {
                    usePointStyle: false,
                    boxWidth: 22,
                    boxHeight: 12,
                    color: cssVar('--chart-text'),
                    font: {
                        weight: '600'
                    }
                }
            },

            tooltip: {
                callbacks: {
                    label: function(context) {

                        if (context.raw === null) {
                            return context.dataset.label + ': —';
                        }

                        return context.dataset.label +
                            ': ' +
                            Number(context.raw).toFixed(1) +
                            ' ' +
                            unit;
                    }
                }
            },

            datalabels: {
                anchor: 'end',
                align: 'end',
                offset: 1,
                clamp: true,
                color: cssVar('--chart-text'),
                font: {
                    size: 10,
                    weight: '600'
                },
                formatter: function(value) {

                    if (value === null) {
                        return '';
                    }

                    return Number(value).toFixed(1);
                }
            }
        },

        scales: {

            x: {
                grid: {
                    display: false
                },
                ticks: {
                    color: cssVar('--chart-text'),
                    font: {
                        weight: '600'
                    }
                }
            },

            y: {
                beginAtZero: beginAtZero,
                title: {
                    display: true,
                    text: unit,
                    color: cssVar('--chart-axis'),
                    font: {
                        weight: '600'
                    }
                },
                grid: {
                    color: cssVar('--chart-grid')
                }
            }
        }
    };
}


function forecastDataset(label, data)
{
    return {
        label: 'FORECAST — ' + label,
        data: data,
        backgroundColor: forecastBackground,
        borderColor: forecastBorder,
        borderWidth: 1.5,
        borderRadius: 3,
        maxBarThickness: 32
    };
}


function actualDataset(label, data)
{
    return {
        label: 'ACTUAL — ' + label,
        data: data,
        backgroundColor: actualBackground,
        borderColor: actualBorder,
        borderWidth: 3,
        borderRadius: 3,
        maxBarThickness: 32
    };
}


/* -------------------------------------------------------------
   Temperature chart
------------------------------------------------------------- */

const temperatureChart = new Chart(
    document.getElementById('temperatureChart'),
    {
        type: 'bar',

        data: {
            labels: labels,

            datasets: [
                forecastDataset('HIGH', forecastHigh),
                actualDataset('HIGH', actualHigh),

                forecastDataset('LOW', forecastLow),
                actualDataset('LOW', actualLow)
            ]
        },

        options: standardOptions(tempUnit, false)
    }
);


/* -------------------------------------------------------------
   Rainfall chart
------------------------------------------------------------- */

const rainChart = new Chart(
    document.getElementById('rainChart'),
    {
        type: 'bar',

        data: {
            labels: labels,

            datasets: [
                forecastDataset('RAINFALL', forecastRain),
                actualDataset('RAINFALL', actualRain)
            ]
        },

        options: standardOptions(rainUnit, true)
    }
);


/* -------------------------------------------------------------
   Sustained wind chart
------------------------------------------------------------- */

const windChart = new Chart(
    document.getElementById('windChart'),
    {
        type: 'bar',

        data: {
            labels: labels,

            datasets: [
                forecastDataset('WIND', forecastWind),
                actualDataset('WIND', actualWind)
            ]
        },

        options: standardOptions(windUnit, true)
    }
);


/* -------------------------------------------------------------
   Gust chart
------------------------------------------------------------- */

const gustChart = new Chart(
    document.getElementById('gustChart'),
    {
        type: 'bar',

        data: {
            labels: labels,

            datasets: [
                forecastDataset('GUST', forecastGust),
                actualDataset('GUST', actualGust)
            ]
        },

        options: standardOptions(windUnit, true)
    }
);


/* -------------------------------------------------------------
   Solar radiation chart
------------------------------------------------------------- */

const solarChart = new Chart(
    document.getElementById('solarChart'),
    {
        type: 'bar',

        data: {
            labels: labels,

            datasets: [
                forecastDataset('SOLAR', forecastSolar),
                actualDataset('SOLAR', actualSolar)
            ]
        },

        options: standardOptions('W/m²', true)
    }
);

chartInstances.push(
    temperatureChart,
    rainChart,
    windChart,
    gustChart,
    solarChart
);

refreshChartTheme();
updateThemeButton();


/* -------------------------------------------------------------
   Archive selection
------------------------------------------------------------- */

function loadArchive()
{
    const select = document.getElementById('periodSelect');

    const url =
        new URL(window.location.href);

    url.searchParams.set(
        'period',
        select.value
    );

    window.location.href = url.toString();
}


/* -------------------------------------------------------------
   PDF export
------------------------------------------------------------- */

async function exportPDF()
{
    if (
        typeof window.jspdf === 'undefined' ||
        typeof window.jspdf.jsPDF === 'undefined'
    ) {
        alert('The PDF library has not loaded.');
        return;
    }

    const { jsPDF } = window.jspdf;

    const pdf = new jsPDF({
        orientation: 'landscape',
        unit: 'mm',
        format: 'a4'
    });

    const pageWidth = pdf.internal.pageSize.getWidth();
    const margin = 10;

    pdf.setFontSize(17);

    pdf.text(
        <?php echo json_encode(SITE_NAME . ' — WXSIM Forecast vs Actual Weather'); ?>,
        margin,
        12
    );

    pdf.setFontSize(10);

    pdf.text(
        <?php
        echo json_encode(
            format_period_date($periodStart) .
            ' - ' .
            format_period_date($periodEnd)
        );
        ?>,
        margin,
        18
    );

    const chartIds = [
        'temperatureChart',
        'rainChart',
        'windChart',
        'gustChart',
        'solarChart'
    ];

    const chartTitles = [
        'Maximum & Minimum Temperature',
        'Rainfall',
        'Maximum Sustained Wind',
        'Maximum Wind Gust',
        'Maximum Solar Radiation'
    ];

    let y = 24;

    for (let i = 0; i < chartIds.length; i++) {

        const canvas =
            document.getElementById(chartIds[i]);

        if (!canvas) {
            continue;
        }

        /*
         * Start a new PDF page where necessary.
         */
        if (i > 0) {
            pdf.addPage();
            y = 14;
        }

        pdf.setFontSize(13);
        pdf.text(chartTitles[i], margin, y);

        const image =
            canvas.toDataURL('image/png', 1.0);

        const usableWidth =
            pageWidth - (margin * 2);

        const imageHeight = 125;

        pdf.addImage(
            image,
            'PNG',
            margin,
            y + 5,
            usableWidth,
            imageHeight
        );
    }

    pdf.save(
        <?php
        echo json_encode(
            'wxsim_forecast_vs_actual_' .
            $periodStart .
            '.pdf'
        );
        ?>
    );
}

</script>

<?php endif; ?>

</body>
</html>
<?php
exit;
}

if ($page === 'detailed') {

/*
 WXSIM Forecast vs Weather Underground Actuals
 Detailed Stage 1 Trial Chart
 Developed by MillbankPWS, Munlochy, Scotland

 Detailed Stage 1 weekly chart.
 Uses the configured forecast start day and creates a separate immutable
 detailed forecast cache for each seven-day week. It does NOT alter data/weeks
 or the normal Stage 1 chart.
*/
require_once __DIR__.'/config.php';

/*
 Use the period of the newest frozen Stage 1 forecast archive when one exists.
 This is essential after a manual first-install initialisation on a day other
 than FORECAST_START_DAY: Detailed Comparison must describe the forecast that
 was actually frozen, not an earlier calculated calendar week.

 If no Stage 1 archive exists yet, fall back to the configured recurring
 forecast weekday.
*/
$weekDayName = defined('FORECAST_START_DAY') ? trim((string)FORECAST_START_DAY) : 'Sunday';
$validDays = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
if (!in_array($weekDayName, $validDays, true)) $weekDayName = 'Sunday';

$detailStart = '';
$detailEnd = '';
$stage1Files = glob(WEEKS_DIR . '/*.json');
if (is_array($stage1Files) && count($stage1Files) > 0) {
    $stage1Archives = [];
    foreach ($stage1Files as $stage1File) {
        $base = basename($stage1File, '.json');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $base) === 1) {
            $stage1Archives[$base] = $stage1File;
        }
    }
    if (count($stage1Archives) > 0) {
        krsort($stage1Archives);
        $latestStage1File = reset($stage1Archives);
        $latestStage1 = json_decode((string)@file_get_contents($latestStage1File), true);
        if (is_array($latestStage1)) {
            $candidateStart = trim((string)($latestStage1['periodStart'] ?? ''));
            $candidateEnd = trim((string)($latestStage1['periodEnd'] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidateStart) === 1) {
                $detailStart = $candidateStart;
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidateEnd) === 1) {
                    $detailEnd = $candidateEnd;
                } else {
                    $tmpEnd = new DateTime($detailStart, new DateTimeZone(STATION_TIMEZONE));
                    $tmpEnd->modify('+6 days');
                    $detailEnd = $tmpEnd->format('Y-m-d');
                }
            }
        }
    }
}

if ($detailStart === '') {
    $tz = new DateTimeZone(STATION_TIMEZONE);
    $nowLocal = new DateTime('now', $tz);
    $weekStart = clone $nowLocal;
    if ($nowLocal->format('l') !== $weekDayName) $weekStart->modify('last '.$weekDayName);
    $weekStart->setTime(0,0,0);
    $weekEnd = clone $weekStart;
    $weekEnd->modify('+6 days');
    $detailStart = $weekStart->format('Y-m-d');
    $detailEnd = $weekEnd->format('Y-m-d');
}

define('DT_START', $detailStart);
define('DT_END', $detailEnd);
define('DT_FILE', 'detailed_'.DT_START.'.json');

function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function nv($v){return ($v!==null&&$v!==''&&is_numeric($v))?(float)$v:null;}
function vals($a){return array_values(array_filter($a,fn($v)=>$v!==null&&is_numeric($v)));}
function av($a){$a=vals($a);return $a?array_sum($a)/count($a):null;}
function mx($a){$a=vals($a);return $a?max($a):null;}
function mn($a){$a=vals($a);return $a?min($a):null;}
function circ($a){$a=vals($a);if(!$a)return null;$s=$c=0.0;foreach($a as $v){$r=deg2rad($v);$s+=sin($r);$c+=cos($r);}if(abs($s)<1e-12&&abs($c)<1e-12)return null;$d=rad2deg(atan2($s,$c));return $d<0?$d+360:$d;}
function angerr($a,$b){if($a===null||$b===null)return null;$d=abs($a-$b);return min($d,360-$d);}
function wb($t,$rh){if($t===null||$rh===null)return null;$rh=max(0,min(100,$rh));return $t*atan(.151977*sqrt($rh+8.313659))+atan($t+$rh)-atan($rh-1.676331)+.00391838*pow($rh,1.5)*atan(.023101*$rh)-4.686035;}
function txt($src){
 if(preg_match('~^https?://~i',$src)){
  $ctx=stream_context_create(['http'=>['timeout'=>20,'user_agent'=>'MillbankPWS-Detailed-Trial/1.0']]);
  $x=@file_get_contents($src,false,$ctx);
 }else{$x=@file_get_contents($src);}
 return $x===false?null:$x;
}
function windkmh($v,$u){if($v===null)return null;$u=strtolower(trim($u));if(in_array($u,['knots','knot','kt','kts'],true))return $v*1.852;if(in_array($u,['mi/hr','mph','miles/hr'],true))return $v*1.609344;if(in_array($u,['km/hr','km/h','kph'],true))return $v;return null;}
function tc($v){if($v===null)return null;return defined('WXSIM_OUTPUT_UNITS')&&strtolower(WXSIM_OUTPUT_UNITS)==='imperial'?($v-32)*5/9:$v;}
function mm($v){if($v===null)return null;return defined('WXSIM_OUTPUT_UNITS')&&strtolower(WXSIM_OUTPUT_UNITS)==='imperial'?$v*25.4:$v;}
function atomic($path,$data){$dir=dirname($path);if(!is_dir($dir)&&!@mkdir($dir,0755,true)&&!is_dir($dir))return false;$tmp=$path.'.tmp.'.getmypid();$j=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);if($j===false||@file_put_contents($tmp,$j,LOCK_EX)===false)return false;if(!@rename($tmp,$path)){@unlink($tmp);return false;}return true;}

function forecast_snapshot(){
 $raw=txt(WXSIM_LATEST_CSV);if($raw===null||trim($raw)==='')throw new Exception('Could not read WXSIM latest.csv.');
 $h=fopen('php://temp','r+');fwrite($h,$raw);rewind($h);
 $head=fgetcsv($h, 0, ',', '"', '\\');$units=fgetcsv($h, 0, ',', '"', '\\');if(!$head||!$units)throw new Exception('latest.csv header/units rows not recognised.');
 $head=array_map('trim',$head);$units=array_map('trim',$units);$ix=[];foreach($head as $i=>$v)$ix[$v]=$i;
 $need=['Year','Month','Day','Hi Temp','Low Temp','Rel.Hum.','Dew Pt.','Wet Bulb','Wind Spd.','Wind Dir.','Tot.Prcp','S.L.P.','Wind Chl','Heat Ind','Solar Rad','UV Index','10 min Gust'];
 foreach($need as $k)if(!array_key_exists($k,$ix))throw new Exception('Missing WXSIM CSV field: '.$k);
 $wu=$units[$ix['Wind Spd.']]??'';$gu=$units[$ix['10 min Gust']]??'';$g=[];
 while(($r=fgetcsv($h, 0, ',', '"', '\\'))!==false){
  $r=array_map(fn($x)=>trim((string)$x),$r);$y=$r[$ix['Year']]??'';$m=$r[$ix['Month']]??'';$d=$r[$ix['Day']]??'';
  if(!ctype_digit($y)||!ctype_digit($m)||!ctype_digit($d))continue;$date=sprintf('%04d-%02d-%02d',(int)$y,(int)$m,(int)$d);
  if($date<DT_START||$date>DT_END)continue;$q=fn($k)=>nv($r[$ix[$k]]??null);
  $g[$date][]= ['hi'=>tc($q('Hi Temp')),'lo'=>tc($q('Low Temp')),'rh'=>$q('Rel.Hum.'),'dew'=>tc($q('Dew Pt.')),'wet'=>tc($q('Wet Bulb')),
   'wind'=>windkmh($q('Wind Spd.'),$wu),'dir'=>$q('Wind Dir.'),'rain'=>mm($q('Tot.Prcp')),'p'=>$q('S.L.P.'),
   'chill'=>tc($q('Wind Chl')),'heat'=>tc($q('Heat Ind')),'solar'=>$q('Solar Rad'),'uv'=>$q('UV Index'),'gust'=>windkmh($q('10 min Gust'),$gu)];
 }
 fclose($h);if(!isset($g[DT_START]))throw new Exception('latest.csv does not contain trial start date '.DT_START.'.');
 $o=[];foreach($g as $date=>$rows){$c=fn($k)=>array_map(fn($r)=>$r[$k],$rows);$rv=$c('rain');$rmax=mx($rv);$rmin=mn($rv);
  $o[$date]=['max_temp_c'=>mx($c('hi')),'min_temp_c'=>mn($c('lo')),'dewpoint_c'=>av($c('dew')),'humidity_pct'=>av($c('rh')),
   'wetbulb_c'=>av($c('wet')),'pressure_hpa'=>av($c('p')),'rain_mm'=>($rmax!==null&&$rmin!==null)?max(0,$rmax-$rmin):null,
   'wind_kmh'=>mx($c('wind')),'gust_kmh'=>mx($c('gust')),'wind_dir_deg'=>circ($c('dir')),'solar_wm2'=>mx($c('solar')),
   'uv_index'=>mx($c('uv')),'wind_chill_c'=>mn($c('chill')),'heat_index_c'=>mx($c('heat'))];
 }return $o;
}
function wu_day($date){
 $url='https://api.weather.com/v2/pws/history/all?stationId='.rawurlencode(WU_STATION_ID).'&format=json&units=m&date='.str_replace('-','',$date).'&numericPrecision=decimal&apiKey='.rawurlencode(WU_API_KEY);
 $raw=txt($url);if($raw===null)return [null,'WU request failed'];$j=json_decode($raw,true);if(!isset($j['observations'])||!is_array($j['observations']))return [null,'WU response not recognised'];
 $obs=array_values(array_filter($j['observations'],fn($o)=>isset($o['obsTimeLocal'])&&substr($o['obsTimeLocal'],0,10)===$date));if(!$obs)return [null,'No station-local observations'];
 $v=['t'=>[],'rh'=>[],'dew'=>[],'wet'=>[],'p'=>[],'rain'=>[],'wind'=>[],'gust'=>[],'dir'=>[],'solar'=>[],'uv'=>[],'chill'=>[],'heat'=>[]];
 foreach($obs as $o){$m=$o['metric']??[];$t=nv($m['tempAvg']??null);$rh=nv($o['humidityAvg']??null);$v['t'][]=$t;$v['rh'][]=$rh;$v['dew'][]=nv($m['dewptAvg']??null);$v['wet'][]=wb($t,$rh);
  $p1=nv($m['pressureMax']??null);$p2=nv($m['pressureMin']??null);$v['p'][]=($p1!==null&&$p2!==null)?($p1+$p2)/2:($p1??$p2);
  $v['rain'][]=nv($m['precipTotal']??null);$v['wind'][]=nv($m['windspeedHigh']??null);$v['gust'][]=nv($m['windgustHigh']??null);$v['dir'][]=nv($o['winddirAvg']??null);
  $v['solar'][]=nv($o['solarRadiationHigh']??null);$v['uv'][]=nv($o['uvHigh']??null);$v['chill'][]=nv($m['windchillLow']??null);$v['heat'][]=nv($m['heatindexHigh']??null);}
 return [['max_temp_c'=>mx($v['t']),'min_temp_c'=>mn($v['t']),'dewpoint_c'=>av($v['dew']),'humidity_pct'=>av($v['rh']),'wetbulb_c'=>av($v['wet']),
  'pressure_hpa'=>av($v['p']),'rain_mm'=>mx($v['rain']),'wind_kmh'=>mx($v['wind']),'gust_kmh'=>mx($v['gust']),'wind_dir_deg'=>circ($v['dir']),
  'solar_wm2'=>mx($v['solar']),'uv_index'=>mx($v['uv']),'wind_chill_c'=>mn($v['chill']),'heat_index_c'=>mx($v['heat']),'observations'=>count($obs)],null];
}

$path=(defined('DATA_DIR')?DATA_DIR:__DIR__.'/data').'/'.DT_FILE;$msg='';$error='';
if(is_file($path)){$trial=json_decode((string)@file_get_contents($path),true);if(!is_array($trial)||!isset($trial['forecast']))$error='Detailed trial cache is invalid.';}
else{try{$trial=['created_local'=>date('Y-m-d H:i:s'),'timezone'=>STATION_TIMEZONE,'start'=>DT_START,'end'=>DT_END,'forecast'=>forecast_snapshot()];
 if(!atomic($path,$trial))$error='Could not create separate trial cache: '.$path;else$msg='Trial forecast frozen from the current latest.csv. The normal weekly archive was not changed.';
}catch(Throwable $x){$error=$x->getMessage();}}
$today=date('Y-m-d');$actual=[];$notes=[];
if(!$error){foreach($trial['forecast'] as $date=>$f){if($date>=$today)continue;[$a,$n]=wu_day($date);if($a!==null)$actual[$date]=$a;if($n)$notes[$date]=$n;}}
$metrics=[
 'max_temp_c'=>['Max temperature','°C','temp'],'min_temp_c'=>['Min temperature','°C','temp'],'dewpoint_c'=>['Mean dew point','°C','moist'],
 'humidity_pct'=>['Mean humidity','%','moist'],'wetbulb_c'=>['Mean wet bulb','°C','moist'],'pressure_hpa'=>['Mean sea-level pressure','hPa','pressure'],
 'rain_mm'=>['Rainfall','mm','rain'],'wind_kmh'=>['Max wind speed','km/h','wind'],'gust_kmh'=>['Max 10-min gust / WU gust','km/h','wind'],
 'wind_dir_deg'=>['Mean wind direction','°','wind'],'solar_wm2'=>['Max solar radiation','W/m²','rad'],'uv_index'=>['Max UV index','','rad'],
 'wind_chill_c'=>['Min wind chill','°C','temp'],'heat_index_c'=>['Max heat index','°C','temp']];
$dates=[];$d=new DateTime(DT_START,new DateTimeZone(STATION_TIMEZONE));$z=new DateTime(DT_END,new DateTimeZone(STATION_TIMEZONE));while($d<=$z){$dates[]=$d->format('Y-m-d');$d->modify('+1 day');}
function fv($v,$u){if($v===null)return '—';$dp=in_array($u,['%','°'],true)?0:1;return number_format($v,$dp).($u?' '.$u:'');}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="60">
<title>WXSIM Detailed Comparison Dashboard</title>
<style>
:root{
    --bg:#f3f5f7;
    --panel:#fff;
    --ink:#1f2933;
    --muted:#65727e;
    --line:#d8dee4;
    --forecast:#1565c0;
    --actual:#2e7d32;
    --error:#9c2f2f;
    --future:#f6f7f8;
}
*{box-sizing:border-box}
body{
    margin:0;
    background:var(--bg);
    color:var(--ink);
    font-family:Arial,Helvetica,sans-serif;
}
.wrap{max-width:1180px;margin:0 auto;padding:14px}
header{
    background:linear-gradient(135deg,#eaf4fb,#f7fbfd);
    border:1px solid #b9d5e8;
    border-radius:10px;
    padding:14px 18px;
    margin-bottom:10px;
}
h1{margin:0 0 5px;font-size:24px;color:#173f5f}
.sub{color:#526673;font-size:13px}
.suite-nav{
    display:flex;
    gap:7px;
    flex-wrap:wrap;
    margin:0 0 10px
}
.suite-nav a{
    text-decoration:none;
    color:#174a70;
    background:#edf7fc;
    border:1px solid #b8d8e9;
    border-radius:7px;
    padding:7px 11px;
    font-size:12px;
    font-weight:700
}
.suite-nav a:hover{background:#dff1fa}
.suite-nav a.current{
    background:#1769aa;
    color:#fff;
    border-color:#1769aa
}
.grid{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:9px;
    margin-bottom:10px
}
.card,.panel{
    background:var(--panel);
    border:1px solid var(--line);
    border-radius:12px
}
.card{
    padding:11px 13px;
    border-top:4px solid #4a90b8
}
.card .k{
    color:#5c7180;
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.04em;
    margin-bottom:5px
}
.card .v{
    font-size:18px;
    font-weight:700;
    color:#173f5f
}
.card .s{
    color:var(--muted);
    font-size:11px;
    margin-top:3px
}
.panel{
    padding:13px 15px;
    margin-bottom:10px
}
.panel h2{
    font-size:17px;
    margin:0 0 9px;
    color:#173f5f;
    border-bottom:2px solid #d7e9f4;
    padding-bottom:6px
}
.notice{
    padding:12px 14px;
    border-radius:8px;
    background:#eef8ef;
    border:1px solid #b8d9bd;
    margin-bottom:10px
}
.notice.bad{
    background:#fff0f0;
    border-color:#e0b3b3;
    color:#8a1f1f
}
.toolbar{
    display:flex;
    gap:7px;
    align-items:center;
    flex-wrap:wrap
}
.toolbar strong{margin-right:3px}
.btn{
    border:1px solid #b8d8e9;
    background:#edf7fc;
    color:#174a70;
    padding:7px 11px;
    border-radius:7px;
    cursor:pointer;
    font-weight:700;
    font-size:12px
}
.btn:hover{background:#dff1fa}
.btn.active{
    background:#1769aa;
    color:#fff;
    border-color:#1769aa
}
.tablewrap{overflow:auto}
.matrix{
    border-collapse:collapse;
    width:100%;
    min-width:1080px
}
.matrix th,.matrix td{
    border-bottom:1px solid var(--line);
    border-right:1px solid #edf0f2;
    padding:8px 7px;
    text-align:center;
    white-space:nowrap;
    font-size:12px
}
.matrix th:last-child,.matrix td:last-child{border-right:0}
.matrix th{
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.03em;
    color:var(--muted);
    background:#fafbfc;
    position:sticky;
    top:0;
    z-index:1
}
.metric{
    text-align:left!important;
    font-weight:700;
    color:#173f5f;
    background:#fbfcfd
}
.f{
    color:var(--forecast);
    font-weight:700;
    margin-bottom:2px
}
.a{
    color:var(--actual);
    font-weight:700;
    margin-bottom:2px
}
.er{
    color:var(--error);
    font-size:11px
}
.future{
    background:var(--future)
}
.legend{
    color:var(--muted);
    font-size:12px;
    line-height:1.5;
    margin-top:10px
}
.info-note{
    margin-top:10px;
    padding:9px 11px;
    border-left:4px solid #4a90b8;
    background:#f3f8fc;
    color:#526673;
    font-size:12px;
    line-height:1.45;
    border-radius:5px
}
.small{color:var(--muted);font-size:12px;line-height:1.45}
footer{
    color:var(--muted);
    font-size:12px;
    text-align:center;
    padding:8px 0 18px
}
[hidden]{display:none!important}
@media(max-width:900px){
    .grid{grid-template-columns:repeat(2,1fr)}
    .wrap{padding:12px}
}
@media(max-width:520px){
    .grid{grid-template-columns:1fr}
    h1{font-size:23px}
}

/* Shared display/print controls added for public release */
.page-actions{display:flex;gap:7px;justify-content:flex-end;flex-wrap:wrap;margin:0 0 10px}
.page-actions button{border:1px solid #b8c7d3;border-radius:7px;background:#fff;color:#203040;padding:7px 11px;font-size:12px;font-weight:700;cursor:pointer}
.page-actions button:hover{background:#eef5f9}
@media print{
    .page-actions,.suite-nav{display:none!important}
    body{background:#fff!important}
}

html[data-theme="dark"]{
    --bg:#10161d;--panel:#18212b;--ink:#e7edf3;--muted:#a7b3bf;--line:#344251;
    --forecast:#7ab8e8;--actual:#78c98a;--error:#f0a0a0;--future:#202a34;--accent:#8fc4ea;
}
html[data-theme="dark"] body{background:var(--bg);color:var(--ink)}
html[data-theme="dark"] header{background:linear-gradient(135deg,#102b42,#1d4c70);border-color:#365b75}
html[data-theme="dark"] h1,html[data-theme="dark"] h2{color:#b9dafa}
html[data-theme="dark"] .sub,html[data-theme="dark"] .small,html[data-theme="dark"] footer{color:var(--muted)}
html[data-theme="dark"] .suite-nav a{color:#cfe7f8;background:#202c38;border-color:#4a6275}
html[data-theme="dark"] .suite-nav a:hover{background:#293746}
html[data-theme="dark"] .suite-nav a.current{background:#2879b5;color:#fff;border-color:#2879b5}
html[data-theme="dark"] .card,html[data-theme="dark"] .panel{background:var(--panel);border-color:var(--line)}
html[data-theme="dark"] table,html[data-theme="dark"] th,html[data-theme="dark"] td{border-color:var(--line)}
html[data-theme="dark"] th{background:#202c38;color:#d8e4ef}
html[data-theme="dark"] .notice,html[data-theme="dark"] .rain-note{background:#302916;color:#f0d990;border-color:#65552b}
html[data-theme="dark"] select,html[data-theme="dark"] button,html[data-theme="dark"] .btn{background:#202c38;color:#e7edf3;border-color:#4a5b6c}
html[data-theme="dark"] .page-actions button:hover,html[data-theme="dark"] .btn:hover{background:#293746}
html[data-theme="dark"] td.metric,
html[data-theme="dark"] td.metric-name,
html[data-theme="dark"] th.metric,
html[data-theme="dark"] .metric-label,
html[data-theme="dark"] .row-label,
html[data-theme="dark"] tbody th,
html[data-theme="dark"] td[style*="background:#fff"],
html[data-theme="dark"] td[style*="background: #fff"],
html[data-theme="dark"] td[style*="background:white"],
html[data-theme="dark"] td[style*="background: white"]{background:#202c38!important;color:#e7edf3!important}
html[data-theme="dark"] td.forecast{background:#182b3a!important;color:#73bfff!important}
html[data-theme="dark"] td.actual{background:#1b3027!important;color:#82d49a!important}
html[data-theme="dark"] td.error{background:#352326!important;color:#ff9b9b!important}
html[data-theme="dark"] .forecast-cell,
html[data-theme="dark"] .actual-cell,
html[data-theme="dark"] .error-cell{color:#e7edf3}
</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

<script>
(function(){
    try{
        const saved=localStorage.getItem('wxsimChartTheme');
        const theme=(saved==='dark'||saved==='light')?saved:'light';
        document.documentElement.setAttribute('data-theme',theme);
    }catch(e){document.documentElement.setAttribute('data-theme','light');}
})();
</script>
</head>
<body>
<div class="wrap">

<header>
    <h1>WXSIM Detailed Comparison Dashboard</h1>
    <div class="sub">
        Daily WXSIM forecast values compared with completed-day Weather Underground observations ·
        week commencing <?=e(date('j F Y',strtotime(DT_START)))?> ·
        <?=e(SITE_NAME)?> · <?=e(STATION_TIMEZONE)?>
    </div>
</header>

<nav class="suite-nav" aria-label="WXSIM forecast pages">
    <a href="index.php?page=stage1">7-Day Comparison</a>
    <a class="current" href="index.php?page=detailed">Detailed Comparison</a>
    <a href="index.php?page=stage2">Accuracy Dashboard</a>
    <a href="index.php?page=cloud">Cloud Cover</a>

        <a href="index.php?page=setup">Setup &amp; Tests</a></nav>

<div class="page-actions">
    <button type="button" id="themeTogglePage" onclick="wxfaToggleTheme()">Dark mode</button>
    <button type="button" onclick="window.print()">Print</button>
    <button type="button" onclick="wxfaExportPagePDF()">Export PDF</button>
</div>


<?php if($error):?>
<div class="notice bad"><b>ERROR:</b> <?=e($error)?></div>
<?php else:?>

<?php if($msg):?>
<div class="notice"><?=e($msg)?></div>
<?php endif;?>

<div class="grid">
    <div class="card">
        <div class="k">Week commencing</div>
        <div class="v"><?=e(date('D j M Y',strtotime(DT_START)))?></div>
        <div class="s">Configured start day: <?=e(FORECAST_START_DAY)?></div>
    </div>
    <div class="card">
        <div class="k">Week ending</div>
        <div class="v"><?=e(date('D j M Y',strtotime(DT_END)))?></div>
        <div class="s">Seven-day comparison period</div>
    </div>
    <div class="card">
        <div class="k">Forecast</div>
        <div class="v">Frozen</div>
        <div class="s">Separate immutable weekly detailed cache</div>
    </div>
    <div class="card">
        <div class="k">Actuals</div>
        <div class="v">Completed days</div>
        <div class="s">Weather Underground history/all</div>
    </div>
</div>

<div class="panel">
    <h2>Metrics</h2>
    <div class="toolbar">
        <strong>Show:</strong>
        <button class="btn active" data-g="all">All</button>
        <button class="btn" data-g="temp">Temperature</button>
        <button class="btn" data-g="moist">Moisture</button>
        <button class="btn" data-g="pressure">Pressure</button>
        <button class="btn" data-g="rain">Rain</button>
        <button class="btn" data-g="wind">Wind</button>
        <button class="btn" data-g="rad">Solar / UV</button>
    </div>
</div>

<div class="panel">
    <h2>Forecast vs actual by day</h2>
    <div class="tablewrap">
        <table class="matrix">
            <thead>
                <tr>
                    <th>Metric</th>
                    <?php foreach($dates as $date):?>
                    <th><?=e(date('D j',strtotime($date)))?></th>
                    <?php endforeach;?>
                    <th>Mean absolute error</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($metrics as $key=>$m):
                [$label,$unit,$group]=$m;
                $ae=[];
            ?>
                <tr class="mr" data-g="<?=e($group)?>">
                    <td class="metric"><?=e($label)?></td>
                    <?php foreach($dates as $date):
                        $f=$trial['forecast'][$date][$key]??null;
                        $a=$actual[$date][$key]??null;
                        $er=($f===null||$a===null)?null:($key==='wind_dir_deg'?angerr($f,$a):$f-$a);
                        if($er!==null)$ae[]=abs($er);
                    ?>
                    <td class="<?=$date>=$today?'future':''?>">
                        <div class="f">F: <?=e(fv($f,$unit))?></div>
                        <div class="a">A: <?=e(fv($a,$unit))?></div>
                        <div class="er">
                            Error:
                            <?=$er===null
                                ? '—'
                                : e(($key==='wind_dir_deg'?'':($er>0?'+':''))
                                    .number_format($er,in_array($unit,['%','°'],true)?0:1)
                                    .($unit?' '.$unit:''))
                            ?>
                        </div>
                    </td>
                    <?php endforeach;?>
                    <td><?=$ae?e(number_format(array_sum($ae)/count($ae),1).' '.$unit):'—'?></td>
                </tr>
            <?php endforeach;?>
            </tbody>
        </table>
    </div>

    <div class="legend">
        <b>F</b> = frozen WXSIM forecast.
        <b>A</b> = Weather Underground actual.
        Error = Forecast − Actual.
        Wind direction uses the shortest angular error.
        Mean absolute error uses completed days only.
    </div>

    <div class="info-note">
        <strong>Automatic update:</strong> this page refreshes itself every 60 seconds.
        Weather Underground actuals are shown only for completed station-local days, so the first actual values for a day appear after that day has finished.
    </div>
</div>

<?php if($notes):?>
<div class="panel">
    <h2>Weather Underground notes</h2>
    <div class="small">
    <?php foreach($notes as $d=>$n):?>
        <?=e($d.': '.$n)?><br>
    <?php endforeach;?>
    </div>
</div>
<?php endif;?>

<div class="panel small">
    This dashboard uses the separate weekly file <code><?=e(DT_FILE)?></code> in <code>data</code>.
    A new detailed file is selected automatically when the configured week starts, preserving the previous week's detailed cache.
    It does not alter the normal Stage 1 weekly archive.
</div>

<?php endif;?>

<footer>WXSIM Forecast vs Weather Underground Actuals — developed by MillbankPWS, Munlochy, Scotland</footer>
</div>

<script>
document.querySelectorAll('.btn').forEach(button => {
    button.addEventListener('click', () => {
        document.querySelectorAll('.btn').forEach(x => x.classList.remove('active'));
        button.classList.add('active');
        const group = button.dataset.g;
        document.querySelectorAll('.mr').forEach(row => {
            row.hidden = group !== 'all' && row.dataset.g !== group;
        });
    });
});
</script>

<script>
function wxfaCurrentTheme(){
    return document.documentElement.getAttribute('data-theme')==='dark'?'dark':'light';
}
function wxfaUpdateThemeButton(){
    const b=document.getElementById('themeTogglePage');
    if(b) b.textContent=wxfaCurrentTheme()==='dark'?'Light mode':'Dark mode';
}
function wxfaToggleTheme(){
    const next=wxfaCurrentTheme()==='dark'?'light':'dark';
    document.documentElement.setAttribute('data-theme',next);
    try{localStorage.setItem('wxsimChartTheme',next);}catch(e){}
    wxfaUpdateThemeButton();
    window.dispatchEvent(new Event('resize'));
}
async function wxfaExportPagePDF(){
    if(typeof html2canvas==='undefined'||!window.jspdf||!window.jspdf.jsPDF){
        alert('The PDF library has not loaded.'); return;
    }
    const actions=document.querySelector('.page-actions');
    if(actions) actions.style.display='none';
    try{
        const canvas=await html2canvas(document.body,{scale:1.5,useCORS:true,backgroundColor:'#ffffff'});
        const {jsPDF}=window.jspdf;
        const pdf=new jsPDF({orientation:'portrait',unit:'mm',format:'a4'});
        const pageW=pdf.internal.pageSize.getWidth(), pageH=pdf.internal.pageSize.getHeight();
        const margin=8, usableW=pageW-margin*2, usableH=pageH-margin*2;
        const imgW=usableW, imgH=canvas.height*imgW/canvas.width;
        const img=canvas.toDataURL('image/jpeg',0.92);
        let y=margin, remaining=imgH;
        pdf.addImage(img,'JPEG',margin,y,imgW,imgH);
        remaining-=usableH;
        while(remaining>0){
            pdf.addPage();
            y=margin-(imgH-remaining);
            pdf.addImage(img,'JPEG',margin,y,imgW,imgH);
            remaining-=usableH;
        }
        pdf.save('WXSIM-comparison.pdf');
    } finally {
        if(actions) actions.style.display='';
    }
}
wxfaUpdateThemeButton();
</script>

</body>
</html>

<?php
exit;
}

if ($page === 'stage2') {

/*
 WXSIM Forecast vs Weather Underground Actuals

 Stage 2 Dashboard
 Developed by MillbankPWS
 Munlochy, Scotland

 Read-only dashboard for immutable Stage 2 comparison records.
*/



require_once __DIR__ . '/config.php';

$stage2Dir = __DIR__ . '/data/stage2';
$displayMode = defined('DISPLAY_UNITS') ? strtolower(trim((string)DISPLAY_UNITS)) : 'metric';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function num($value): ?float
{
    return is_numeric($value) ? (float)$value : null;
}

function utc_text(?string $iso): string
{
    if (!$iso) return '—';
    try {
        $dt = new DateTimeImmutable($iso);
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') . ' UTC';
    } catch (Throwable $e) {
        return '—';
    }
}

function short_utc(?string $iso): string
{
    if (!$iso) return '—';
    try {
        $dt = new DateTimeImmutable($iso);
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('d M H:i');
    } catch (Throwable $e) {
        return '—';
    }
}

function convert_display(string $key, ?float $value, string $unit, string $mode, bool $isError = false): array
{
    if ($value === null) return [null, $unit];

    if (in_array($key, ['wind', 'gust'], true) && $unit === 'km/h' && ($mode === 'uk' || $mode === 'imperial')) {
        return [$value / 1.609344, 'mph'];
    }

    if (in_array($key, ['temperature', 'dewpoint', 'wetbulb'], true)
        && $unit === '°C' && $mode === 'imperial') {
        return [$isError ? ($value * 9 / 5) : ($value * 9 / 5 + 32), '°F'];
    }

    if ($key === 'rain' && $unit === 'mm' && $mode === 'imperial') {
        return [$value / 25.4, 'in'];
    }

    return [$value, $unit];
}

function fmt(?float $value, string $unit, bool $signed = false): string
{
    if ($value === null) return '—';

    $decimals = match ($unit) {
        'hPa', 'W/m²' => 1,
        '°', '%', '°C', '°F', 'mph', 'km/h', 'mm' => 1,
        'in' => 3,
        default => 1
    };

    $prefix = ($signed && $value > 0) ? '+' : '';
    return $prefix . number_format($value, $decimals) . ($unit !== '' ? ' ' . $unit : '');
}

function angular_difference(?float $forecast, ?float $actual): ?float
{
    if ($forecast === null || $actual === null) return null;
    $d = abs($forecast - $actual);
    $d = fmod($d, 360.0);
    return min($d, 360.0 - $d);
}

function metric_labels(): array
{
    return [
        'temperature' => 'Temperature',
        'humidity'    => 'Relative humidity',
        'dewpoint'    => 'Dew point',
        'wetbulb'     => 'Wet bulb',
        'pressure'    => 'Sea-level pressure',
        'rain'        => 'Rainfall (30 min interval)',
        'wind'        => 'Wind speed',
        'gust'        => 'Wind gust',
        'direction'   => 'Wind direction',
        'solar'       => 'Solar radiation',
        'uv'          => 'UV index',
    ];
}

$records = [];
$loadWarnings = [];

if (is_dir($stage2Dir)) {
    $files = glob($stage2Dir . '/*.json') ?: [];
    sort($files, SORT_STRING);

    foreach ($files as $file) {
        $raw = @file_get_contents($file);
        if ($raw === false || trim($raw) === '') {
            $loadWarnings[] = basename($file) . ' could not be read.';
            continue;
        }

        $json = json_decode($raw, true);
        if (!is_array($json) || ($json['record_type'] ?? '') !== 'wxsim_wu_stage2_comparison') {
            $loadWarnings[] = basename($file) . ' is not a recognised Stage 2 comparison record.';
            continue;
        }

        $json['_file'] = basename($file);
        $records[] = $json;
    }
}

/* Ensure the dashboard always uses the chronologically newest saved comparison. */
usort($records, static function (array $a, array $b): int {
    $ta = $a['forecast']['valid_time_utc'] ?? '';
    $tb = $b['forecast']['valid_time_utc'] ?? '';
    return strcmp((string)$ta, (string)$tb);
});

$labels = metric_labels();

/*
 * Rainfall compatibility:
 * Older Stage 2 records may contain cumulative WU precip_total_mm but no
 * populated metrics.rain.actual value. Reconstruct the 30-minute WU interval
 * only when two consecutive saved forecast-valid times are exactly 30 minutes
 * apart, on the same station-local date, and the cumulative total has not reset.
 */
for ($i = 1, $count = count($records); $i < $count; $i++) {
    $cur =& $records[$i];
    $prev = $records[$i - 1];

    $curRain = $cur['metrics']['rain']['actual'] ?? null;
    if ($curRain !== null && is_numeric($curRain)) {
        unset($cur);
        continue;
    }

    $curTotal  = $cur['actual']['precip_total_mm'] ?? null;
    $prevTotal = $prev['actual']['precip_total_mm'] ?? null;
    $curValid  = $cur['forecast']['valid_time_utc'] ?? null;
    $prevValid = $prev['forecast']['valid_time_utc'] ?? null;
    $curObsLocal  = $cur['actual']['observation_time_local'] ?? null;
    $prevObsLocal = $prev['actual']['observation_time_local'] ?? null;

    if (!is_numeric($curTotal) || !is_numeric($prevTotal) || !$curValid || !$prevValid) {
        unset($cur);
        continue;
    }

    try {
        $cv = new DateTimeImmutable((string)$curValid);
        $pv = new DateTimeImmutable((string)$prevValid);
        if (($cv->getTimestamp() - $pv->getTimestamp()) !== 1800) {
            unset($cur);
            continue;
        }

        if ($curObsLocal && $prevObsLocal) {
            $co = new DateTimeImmutable((string)$curObsLocal);
            $po = new DateTimeImmutable((string)$prevObsLocal);
            if ($co->format('Y-m-d') !== $po->format('Y-m-d')) {
                unset($cur);
                continue;
            }
        }

        $delta = (float)$curTotal - (float)$prevTotal;
        if ($delta < -0.001) {
            unset($cur);
            continue;
        }

        if (!isset($cur['metrics']['rain']) || !is_array($cur['metrics']['rain'])) {
            $cur['metrics']['rain'] = [
                'unit' => 'mm',
                'forecast' => null,
                'actual' => null,
                'error_forecast_minus_actual' => null
            ];
        }

        $cur['metrics']['rain']['unit'] = 'mm';
        $cur['metrics']['rain']['actual'] = max(0.0, $delta);

        $rf = $cur['metrics']['rain']['forecast'] ?? null;
        if (is_numeric($rf)) {
            $cur['metrics']['rain']['error_forecast_minus_actual']
                = (float)$rf - max(0.0, $delta);
        }
    } catch (Throwable $e) {
        /* Leave rainfall blank if timestamps cannot be interpreted safely. */
    }

    unset($cur);
}

$latest = $records ? $records[count($records) - 1] : null;

/* Build browser-safe chart data in display units. */
$chartRows = [];
foreach ($records as $rec) {
    $row = [
        'time' => $rec['forecast']['valid_time_utc'] ?? null,
        'label' => short_utc($rec['forecast']['valid_time_utc'] ?? null),
        'metrics' => []
    ];

    foreach ($labels as $key => $label) {
        $m = $rec['metrics'][$key] ?? [];
        $unit = (string)($m['unit'] ?? '');
        [$fv, $du] = convert_display($key, num($m['forecast'] ?? null), $unit, $displayMode, false);
        [$av, ]    = convert_display($key, num($m['actual'] ?? null), $unit, $displayMode, false);
        $storedError = num($m['error_forecast_minus_actual'] ?? null);
        $infoOnly = false;

        if ($key === 'direction' && $storedError === null) {
            $storedError = angular_difference(
                num($m['forecast'] ?? null),
                num($m['actual'] ?? null)
            );
            $infoOnly = ($storedError !== null);
        }

        [$ev, ] = convert_display($key, $storedError, $unit, $displayMode, true);

        $row['metrics'][$key] = [
            'forecast' => $fv,
            'actual' => $av,
            'error' => $ev,
            'error_info_only' => $infoOnly,
            'unit' => $du
        ];
    }
    $chartRows[] = $row;
}

/* Mean absolute error for each metric over available scored records. */
$mae = [];
foreach ($labels as $key => $label) {
    $sum = 0.0;
    $n = 0;
    $unit = '';
    foreach ($records as $rec) {
        $m = $rec['metrics'][$key] ?? [];
        $rawUnit = (string)($m['unit'] ?? '');
        [$err, $du] = convert_display($key, num($m['error_forecast_minus_actual'] ?? null), $rawUnit, $displayMode, true);
        if ($err !== null) {
            $sum += abs($err);
            $n++;
            $unit = $du;
        }
    }
    $mae[$key] = ['value' => $n ? $sum / $n : null, 'n' => $n, 'unit' => $unit];
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="60">
<title>WXSIM Forecast Accuracy Dashboard</title>
<style>
:root{
    --bg:#f3f5f7; --panel:#fff; --ink:#1f2933; --muted:#65727e; --line:#d8dee4;
    --accent:#204d74; --forecast:#1565c0; --actual:#2e7d32; --error:#9c2f2f;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font-family:Arial,Helvetica,sans-serif}
.wrap{max-width:1180px;margin:0 auto;padding:14px}
header{background:linear-gradient(135deg,#eaf4fb,#f7fbfd);border:1px solid #b9d5e8;border-radius:10px;padding:14px 18px;margin-bottom:10px}
h1{margin:0 0 5px;font-size:24px;color:#173f5f}
.sub{color:#526673;font-size:13px}
.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px;margin-bottom:10px}
.card,.panel{background:var(--panel);border:1px solid var(--line);border-radius:12px}
.card{padding:11px 13px;border-top:4px solid #4a90b8}
.card .k{color:#5c7180;font-size:11px;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px}
.card .v{font-size:19px;font-weight:700;color:#173f5f}
.card .s{color:var(--muted);font-size:11px;margin-top:3px}
.panel{padding:13px 15px;margin-bottom:10px}
.panel h2{font-size:17px;margin:0 0 9px;color:#173f5f;border-bottom:2px solid #d7e9f4;padding-bottom:6px}
.meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:5px 14px;font-size:13px}
.meta div{padding:5px 0;border-bottom:1px solid #edf0f2}
.meta strong{display:block;font-size:12px;color:var(--muted);margin-bottom:3px}
.tablewrap{overflow:auto}
table{width:100%;border-collapse:collapse;min-width:760px}
th,td{padding:7px 8px;border-bottom:1px solid var(--line);text-align:right;white-space:nowrap;font-size:13px}
th:first-child,td:first-child{text-align:left}
th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);background:#fafbfc}
.forecast{font-weight:700;color:#155fa0;background:#f2f8fc}
.actual{font-weight:700;color:#28733c;background:#f3faf5}
.error{font-weight:700;color:#8c3434;background:#fff7f7}
.toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px}
select{padding:8px 10px;border:1px solid var(--line);border-radius:7px;background:#fff}
.legend{margin-left:auto;font-size:13px;color:var(--muted)}
.dot{display:inline-block;width:11px;height:3px;vertical-align:middle;margin:0 5px 2px 12px}
.dot:first-child{margin-left:0}
.dot.f{background:var(--forecast)} .dot.a{background:var(--actual)}
canvas{width:100%;height:240px;border:1px solid #d9e6ee;border-radius:8px;background:#fbfdff}
.chart-holder{position:relative}
.chart-tooltip{position:absolute;display:none;pointer-events:none;z-index:10;min-width:190px;padding:8px 10px;border:1px solid #b9c5ce;border-radius:7px;background:rgba(255,255,255,.96);box-shadow:0 2px 8px rgba(0,0,0,.14);font-size:12px;line-height:1.45;color:#1f2933}
.chart-tooltip strong{display:block;margin-bottom:3px;color:#173f5f}
.chart-tooltip .tf{color:var(--forecast);font-weight:700}
.chart-tooltip .ta{color:var(--actual);font-weight:700}
.chart-tooltip .te{color:var(--error);font-weight:700}
.notice{padding:12px 14px;border-radius:8px;background:#fff8df;border:1px solid #ead28a;margin-bottom:16px}
.rain-note{margin-top:10px;padding:9px 11px;border-left:4px solid #4a90b8;background:#f3f8fc;color:#526673;font-size:12px;line-height:1.45;border-radius:5px}
.rain-note code{font-size:11px}
.empty{padding:28px;text-align:center;color:var(--muted)}
footer{color:var(--muted);font-size:12px;text-align:center;padding:8px 0 18px}
@media(max-width:900px){.grid{grid-template-columns:repeat(2,1fr)}.meta{grid-template-columns:1fr}.wrap{padding:12px}}
@media(max-width:520px){.grid{grid-template-columns:1fr}h1{font-size:23px}}
.suite-nav{display:flex;gap:7px;flex-wrap:wrap;margin:0 0 10px}.suite-nav a{text-decoration:none;color:#174a70;background:#edf7fc;border:1px solid #b8d8e9;border-radius:7px;padding:7px 11px;font-size:12px;font-weight:700}.suite-nav a:hover{background:#dff1fa}.suite-nav a.current{background:#1769aa;color:#fff;border-color:#1769aa}

/* Shared display/print controls added for public release */
.page-actions{display:flex;gap:7px;justify-content:flex-end;flex-wrap:wrap;margin:0 0 10px}
.page-actions button{border:1px solid #b8c7d3;border-radius:7px;background:#fff;color:#203040;padding:7px 11px;font-size:12px;font-weight:700;cursor:pointer}
.page-actions button:hover{background:#eef5f9}
@media print{
    .page-actions,.suite-nav{display:none!important}
    body{background:#fff!important}
}

html[data-theme="dark"]{
    --bg:#10161d;--panel:#18212b;--ink:#e7edf3;--muted:#a7b3bf;--line:#344251;
    --forecast:#7ab8e8;--actual:#78c98a;--error:#f0a0a0;--future:#202a34;--accent:#8fc4ea;
}
html[data-theme="dark"] body{background:var(--bg);color:var(--ink)}
html[data-theme="dark"] header{background:linear-gradient(135deg,#102b42,#1d4c70);border-color:#365b75}
html[data-theme="dark"] h1,html[data-theme="dark"] h2{color:#b9dafa}
html[data-theme="dark"] .sub,html[data-theme="dark"] .small,html[data-theme="dark"] footer{color:var(--muted)}
html[data-theme="dark"] .suite-nav a{color:#cfe7f8;background:#202c38;border-color:#4a6275}
html[data-theme="dark"] .suite-nav a:hover{background:#293746}
html[data-theme="dark"] .suite-nav a.current{background:#2879b5;color:#fff;border-color:#2879b5}
html[data-theme="dark"] .card,html[data-theme="dark"] .panel{background:var(--panel);border-color:var(--line)}
html[data-theme="dark"] table,html[data-theme="dark"] th,html[data-theme="dark"] td{border-color:var(--line)}
html[data-theme="dark"] th{background:#202c38;color:#d8e4ef}
html[data-theme="dark"] .notice,html[data-theme="dark"] .rain-note{background:#302916;color:#f0d990;border-color:#65552b}
html[data-theme="dark"] select,html[data-theme="dark"] button,html[data-theme="dark"] .btn{background:#202c38;color:#e7edf3;border-color:#4a5b6c}
html[data-theme="dark"] .page-actions button:hover,html[data-theme="dark"] .btn:hover{background:#293746}
html[data-theme="dark"] td.metric,
html[data-theme="dark"] td.metric-name,
html[data-theme="dark"] th.metric,
html[data-theme="dark"] .metric-label,
html[data-theme="dark"] .row-label,
html[data-theme="dark"] tbody th,
html[data-theme="dark"] td[style*="background:#fff"],
html[data-theme="dark"] td[style*="background: #fff"],
html[data-theme="dark"] td[style*="background:white"],
html[data-theme="dark"] td[style*="background: white"]{background:#202c38!important;color:#e7edf3!important}
html[data-theme="dark"] td.forecast{background:#182b3a!important;color:#73bfff!important}
html[data-theme="dark"] td.actual{background:#1b3027!important;color:#82d49a!important}
html[data-theme="dark"] td.error{background:#352326!important;color:#ff9b9b!important}
html[data-theme="dark"] .forecast-cell,
html[data-theme="dark"] .actual-cell,
html[data-theme="dark"] .error-cell{color:#e7edf3}
</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

<script>
(function(){
    try{
        const saved=localStorage.getItem('wxsimChartTheme');
        const theme=(saved==='dark'||saved==='light')?saved:'light';
        document.documentElement.setAttribute('data-theme',theme);
    }catch(e){document.documentElement.setAttribute('data-theme','light');}
})();
</script>
</head>
<body>
<div class="wrap">
<header>
    <h1>WXSIM Forecast Accuracy Dashboard</h1>
    <div class="sub">WXSIM forecast conditions compared with observations from the Weather Underground station. Times are shown in UTC.</div>
</header>
<nav class="suite-nav" aria-label="WXSIM forecast pages"><a href="index.php?page=stage1">7-Day Comparison</a><a href="index.php?page=detailed">Detailed Comparison</a><a class="current" href="index.php?page=stage2">Accuracy Dashboard</a><a href="index.php?page=cloud">Cloud Cover</a>
        <a href="index.php?page=setup">Setup &amp; Tests</a></nav>

<div class="page-actions">
    <button type="button" id="themeTogglePage" onclick="wxfaToggleTheme()">Dark mode</button>
    <button type="button" onclick="window.print()">Print</button>
    <button type="button" onclick="wxfaExportPagePDF()">Export PDF</button>
</div>


<?php if ($loadWarnings): ?>
<div class="notice"><?= h(implode(' ', $loadWarnings)) ?></div>
<?php endif; ?>

<?php if (!$latest): ?>
<div class="panel empty">
    No saved comparison records were found yet.
</div>
<?php else: ?>

<div class="grid">
    <div class="card">
        <div class="k">Records loaded</div>
        <div class="v"><?= count($records) ?></div>
        <div class="s">Saved comparison records</div>
    </div>
    <div class="card">
        <div class="k">Latest forecast-valid time</div>
        <div class="v" style="font-size:18px"><?= h(short_utc($latest['forecast']['valid_time_utc'] ?? null)) ?></div>
        <div class="s">UTC</div>
    </div>
    <div class="card">
        <div class="k">WU match difference</div>
        <div class="v"><?= isset($latest['match']['difference_minutes']) ? number_format((float)$latest['match']['difference_minutes'], 1) . ' min' : '—' ?></div>
        <div class="s">Actual observation after forecast-valid time</div>
    </div>
    <div class="card">
        <div class="k">Display units</div>
        <div class="v"><?= h(strtoupper($displayMode)) ?></div>
        <div class="s">Internal archive values remain metric</div>
    </div>
</div>

<div class="panel">
    <h2>Current comparison</h2>
    <div class="meta">
        <div><strong>Forecast-valid time</strong><?= h(utc_text($latest['forecast']['valid_time_utc'] ?? null)) ?></div>
        <div><strong>WU observation time</strong><?= h(utc_text($latest['actual']['observation_time_utc'] ?? null)) ?></div>
        <div><strong>Record file</strong><?= h($latest['_file'] ?? '—') ?></div>
    </div>
</div>

<div class="panel">
    <h2>Forecast vs observed conditions</h2>
    <div class="tablewrap">
    <table>
        <thead>
        <tr><th>Metric</th><th>WXSIM forecast</th><th>WU actual</th><th>Error F − A</th><th>Mean absolute error</th></tr>
        </thead>
        <tbody>
        <?php foreach ($labels as $key => $label):
            $m = $latest['metrics'][$key] ?? [];
            $unit = (string)($m['unit'] ?? '');
            [$fv,$du] = convert_display($key, num($m['forecast'] ?? null), $unit, $displayMode, false);
            [$av,] = convert_display($key, num($m['actual'] ?? null), $unit, $displayMode, false);

            $storedError = num($m['error_forecast_minus_actual'] ?? null);
            $directionInfoOnly = false;
            if ($key === 'direction' && $storedError === null) {
                $storedError = angular_difference(
                    num($m['forecast'] ?? null),
                    num($m['actual'] ?? null)
                );
                $directionInfoOnly = ($storedError !== null);
            }

            [$ev,] = convert_display($key, $storedError, $unit, $displayMode, true);
            $mv = $mae[$key]['value'];
            $mu = $mae[$key]['unit'] ?: $du;
        ?>
        <tr>
            <td><?= h($label) ?></td>
            <td class="forecast"><?= h(fmt($fv,$du)) ?></td>
            <td class="actual"><?= h(fmt($av,$du)) ?></td>
            <td class="error"><?= h(fmt($ev,$du,true)) ?><?= $directionInfoOnly ? ' (calm)' : '' ?></td>
            <td><?= h(fmt($mv,$mu)) ?><?= $mae[$key]['n'] ? ' <span style="color:#777;font-size:11px">(n=' . (int)$mae[$key]['n'] . ')</span>' : '' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div class="rain-note"><strong>Rainfall:</strong> WXSIM and Weather Underground values are compared as 30-minute intervals. New records use the collector's independent rainfall baseline. For compatibility with earlier Stage 2 records, the dashboard can also reconstruct a missing WU 30-minute rainfall value from consecutive saved cumulative <code>precipTotal</code> readings when the forecast-valid times are exactly 30 minutes apart.</div>
    <div class="rain-note"><strong>Wind direction:</strong> when the collector excludes a direction record from scoring because either wind speed is below the calm-wind threshold, the dashboard still shows the shortest angular difference for information, marked <strong>(calm)</strong>. These calm-wind values are not added to the mean absolute error.</div>
</div>

<div class="panel">
    <h2>Forecast accuracy through time</h2>
    <div class="toolbar">
        <label for="metricSelect"><strong>Metric:</strong></label>
        <select id="metricSelect">
            <?php foreach ($labels as $key => $label): ?>
            <option value="<?= h($key) ?>"<?= $key === 'temperature' ? ' selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="legend"><span class="dot f"></span>WXSIM forecast <span class="dot a"></span>WU actual</div>
    </div>
    <div class="chart-holder">
        <canvas id="comparisonChart" width="1200" height="330"></canvas>
        <div id="chartTooltip" class="chart-tooltip"></div>
    </div>
</div>


<script>
const rows = <?= json_encode($chartRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const metricLabels = <?= json_encode($labels, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const canvas = document.getElementById('comparisonChart');
const select = document.getElementById('metricSelect');
const ctx = canvas.getContext('2d');
const tooltip = document.getElementById('chartTooltip');

function cssVar(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}

function drawChart(metric) {
    const dpr = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    const w = Math.max(600, Math.floor(rect.width));
    const h = 240;
    canvas.width = Math.floor(w * dpr);
    canvas.height = Math.floor(h * dpr);
    ctx.setTransform(dpr,0,0,dpr,0,0);
    ctx.clearRect(0,0,w,h);

    const pad = {l:62,r:22,t:24,b:55};
    const pw = w-pad.l-pad.r, ph = h-pad.t-pad.b;
    const vals = [];
    rows.forEach(r => {
        const m = r.metrics[metric];
        if (!m) return;
        if (m.forecast !== null) vals.push(m.forecast);
        if (m.actual !== null) vals.push(m.actual);
    });

    ctx.font = '12px Arial';
    ctx.fillStyle = '#65727e';

    if (!vals.length) {
        ctx.textAlign='center';
        ctx.fillText('No saved values are available for this metric yet.', w/2, h/2);
        return;
    }

    let min = Math.min(...vals), max = Math.max(...vals);
    if (min === max) { min -= 1; max += 1; }
    let margin = (max-min)*0.10;
    min -= margin; max += margin;

    // Grid and Y labels
    ctx.strokeStyle = '#e3e8ec';
    ctx.lineWidth = 1;
    ctx.textAlign = 'right';
    ctx.textBaseline = 'middle';
    for (let i=0;i<=5;i++) {
        const y = pad.t + ph*(i/5);
        const v = max - (max-min)*(i/5);
        ctx.beginPath(); ctx.moveTo(pad.l,y); ctx.lineTo(w-pad.r,y); ctx.stroke();
        ctx.fillText(v.toFixed(metric==='rain' ? 2 : 1), pad.l-8,y);
    }

    const n = rows.length;
    const xFor = i => n <= 1 ? pad.l + pw/2 : pad.l + pw*(i/(n-1));
    const yFor = v => pad.t + ph*((max-v)/(max-min));

    // X labels: max 8
    ctx.textAlign='center'; ctx.textBaseline='top';
    const step = Math.max(1, Math.ceil(n/8));
    rows.forEach((r,i) => {
        if (i % step === 0 || i === n-1) {
            const x=xFor(i);
            ctx.save();
            ctx.translate(x,h-pad.b+8);
            ctx.rotate(-0.38);
            ctx.fillText(r.label,0,0);
            ctx.restore();
        }
    });

    function series(field, color) {
        ctx.strokeStyle=color; ctx.lineWidth=2.5;
        ctx.beginPath();
        let started=false;
        rows.forEach((r,i)=>{
            const m=r.metrics[metric];
            const v=m ? m[field] : null;
            if (v===null || Number.isNaN(v)) { started=false; return; }
            const x=xFor(i), y=yFor(v);
            if (!started) { ctx.moveTo(x,y); started=true; } else ctx.lineTo(x,y);
        });
        ctx.stroke();

        ctx.fillStyle=color;
        rows.forEach((r,i)=>{
            const m=r.metrics[metric], v=m ? m[field] : null;
            if (v===null || Number.isNaN(v)) return;
            ctx.beginPath(); ctx.arc(xFor(i),yFor(v),3,0,Math.PI*2); ctx.fill();
        });
    }

    series('forecast', cssVar('--forecast'));
    series('actual', cssVar('--actual'));

    const unit = rows.map(r=>r.metrics[metric]?.unit).find(Boolean) || '';
    ctx.fillStyle='#1f2933'; ctx.font='bold 13px Arial'; ctx.textAlign='left'; ctx.textBaseline='top';
    ctx.fillText(metricLabels[metric] + (unit ? ' ('+unit+')' : ''), pad.l, 4);
}


function tooltipValue(value, unit, signed=false) {
    if (value === null || Number.isNaN(value)) return '—';
    const decimals = unit === 'in' ? 3 : 1;
    const prefix = signed && value > 0 ? '+' : '';
    return prefix + Number(value).toFixed(decimals) + (unit ? ' ' + unit : '');
}

canvas.addEventListener('mousemove', (event) => {
    if (!rows.length) { tooltip.style.display = 'none'; return; }

    const rect = canvas.getBoundingClientRect();
    const mouseX = event.clientX - rect.left;
    const w = Math.max(600, Math.floor(rect.width));
    const pad = {l:62,r:22,t:24,b:55};
    const pw = w - pad.l - pad.r;
    const n = rows.length;

    if (mouseX < pad.l - 12 || mouseX > w - pad.r + 12) {
        tooltip.style.display = 'none'; return;
    }

    let index = n <= 1 ? 0 : Math.round(((mouseX - pad.l) / pw) * (n - 1));
    index = Math.max(0, Math.min(n - 1, index));

    const row = rows[index];
    const m = row.metrics[select.value];
    if (!m || (m.forecast === null && m.actual === null)) {
        tooltip.style.display = 'none'; return;
    }

    const unit = m.unit || '';
    tooltip.innerHTML =
        '<strong>' + row.label + ' UTC</strong>' +
        '<div class="tf">WXSIM forecast: ' + tooltipValue(m.forecast, unit) + '</div>' +
        '<div class="ta">WU actual: ' + tooltipValue(m.actual, unit) + '</div>' +
        '<div class="te">Error F − A: ' + tooltipValue(m.error, unit, true) + (m.error_info_only ? ' (calm)' : '') + '</div>';

    tooltip.style.display = 'block';

    const holder = canvas.parentElement;
    const tw = tooltip.offsetWidth, th = tooltip.offsetHeight;
    let left = event.clientX - rect.left + 14;
    let top = event.clientY - rect.top + 14;
    if (left + tw > holder.clientWidth - 4) left = event.clientX - rect.left - tw - 14;
    if (top + th > holder.clientHeight - 4) top = event.clientY - rect.top - th - 14;
    tooltip.style.left = Math.max(4, left) + 'px';
    tooltip.style.top = Math.max(4, top) + 'px';
});

canvas.addEventListener('mouseleave', () => {
    tooltip.style.display = 'none';
});

select.addEventListener('change', ()=>drawChart(select.value));
window.addEventListener('resize', ()=>drawChart(select.value));
drawChart(select.value);
</script>

<?php endif; ?>

<footer>WXSIM Forecast vs Weather Underground Actuals — developed by MillbankPWS, Munlochy, Scotland</footer>
</div>

<script>
function wxfaCurrentTheme(){
    return document.documentElement.getAttribute('data-theme')==='dark'?'dark':'light';
}
function wxfaUpdateThemeButton(){
    const b=document.getElementById('themeTogglePage');
    if(b) b.textContent=wxfaCurrentTheme()==='dark'?'Light mode':'Dark mode';
}
function wxfaToggleTheme(){
    const next=wxfaCurrentTheme()==='dark'?'light':'dark';
    document.documentElement.setAttribute('data-theme',next);
    try{localStorage.setItem('wxsimChartTheme',next);}catch(e){}
    wxfaUpdateThemeButton();
    window.dispatchEvent(new Event('resize'));
}
async function wxfaExportPagePDF(){
    if(typeof html2canvas==='undefined'||!window.jspdf||!window.jspdf.jsPDF){
        alert('The PDF library has not loaded.'); return;
    }
    const actions=document.querySelector('.page-actions');
    if(actions) actions.style.display='none';
    try{
        const canvas=await html2canvas(document.body,{scale:1.5,useCORS:true,backgroundColor:'#ffffff'});
        const {jsPDF}=window.jspdf;
        const pdf=new jsPDF({orientation:'portrait',unit:'mm',format:'a4'});
        const pageW=pdf.internal.pageSize.getWidth(), pageH=pdf.internal.pageSize.getHeight();
        const margin=8, usableW=pageW-margin*2, usableH=pageH-margin*2;
        const imgW=usableW, imgH=canvas.height*imgW/canvas.width;
        const img=canvas.toDataURL('image/jpeg',0.92);
        let y=margin, remaining=imgH;
        pdf.addImage(img,'JPEG',margin,y,imgW,imgH);
        remaining-=usableH;
        while(remaining>0){
            pdf.addPage();
            y=margin-(imgH-remaining);
            pdf.addImage(img,'JPEG',margin,y,imgW,imgH);
            remaining-=usableH;
        }
        pdf.save('WXSIM-comparison.pdf');
    } finally {
        if(actions) actions.style.display='';
    }
}
wxfaUpdateThemeButton();
</script>

</body>
</html>

<?php
exit;
}

if ($page === 'cloud') {


require_once __DIR__ . '/config.php';
/**
 * WXSIM Cloud Cover Forecast - Okta Display
 * Reads WXSIM CSV output and converts Sky Cov (%) to 0-8 oktas.
 * Displays standard synoptic-style cloud amount symbols at configurable intervals.
 * No external libraries required.
 */

// -------- CONFIGURATION --------
$csvFile = WXSIM_LATEST_CSV;
$panelTitle = 'Forecast Cloud Cover';
$maxHours = 168;                  // 7 days. 0 = complete CSV.
$displayIntervalHours = 3;        // Show one symbol every 3 hours.
$showSourcePercentOnHover = true; // Also retain exact WXSIM % in tooltip.
// -------------------------------

// WXSIM forecast schedule is local station time (Europe/London).
date_default_timezone_set(defined('STATION_TIMEZONE') ? STATION_TIMEZONE : 'Europe/London');

// Prevent a browser/proxy from serving an old forecast page.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Retrieve the live WXSIM CSV over HTTPS.
function fetch_wxsim_csv($url) {
    $sep = (strpos($url, '?') === false) ? '?' : '&';
    $ctx = stream_context_create(['http' => [
        'method' => 'GET', 'timeout' => 15, 'ignore_errors' => true,
        'header' => "Cache-Control: no-cache\r\nPragma: no-cache\r\n"
    ]]);
    $data = @file_get_contents($url . $sep . '_=' . time(), false, $ctx);
    return ($data === false || trim($data) === '') ? false : $data;
}

// Status endpoint: compare a content hash because filemtime() is not valid for a remote URL.
if (isset($_GET['wxsim_status'])) {
    header('Content-Type: application/json; charset=utf-8');
    $statusCsv = fetch_wxsim_csv($csvFile);
    echo json_encode([
        'exists' => ($statusCsv !== false),
        'hash' => ($statusCsv !== false) ? sha1($statusCsv) : '',
        'size' => ($statusCsv !== false) ? strlen($statusCsv) : 0
    ]);
    exit;
}

function clean_header($value) {
    return trim((string)$value);
}

function wxsim_local_datetime($year, $month, $day, $decimalHour) {
    $hour = (int)floor((float)$decimalHour);
    $minute = (int)round((((float)$decimalHour) - $hour) * 60);
    if ($minute >= 60) { $hour++; $minute -= 60; }
    return sprintf('%04d-%02d-%02d %02d:%02d:00', $year, $month, $day, $hour, $minute);
}

/**
 * Convert WXSIM total sky-cover percentage to the nearest eighth.
 * 0% = 0 oktas, 100% = 8 oktas; intermediate values are rounded
 * to the nearest eighth for a forecast representation.
 */
function percent_to_oktas($percent) {
    $percent = max(0, min(100, (float)$percent));
    return max(0, min(8, (int)round($percent * 8 / 100)));
}

/** Standard synoptic-style total cloud amount symbol, 0-8 oktas. */
function okta_svg($okta, $size = 34) {
    $o = max(0, min(8, (int)$okta));
    $common = 'stroke="#17212b" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';
    $svg = '<svg class="okta-symbol" width="'.$size.'" height="'.$size.'" viewBox="0 0 32 32" role="img" aria-label="'.$o.' oktas">';

    switch ($o) {
        case 0:
            $svg .= '<circle cx="16" cy="16" r="12" fill="white" '.$common.'/>';
            break;
        case 1:
            $svg .= '<circle cx="16" cy="16" r="12" fill="white" '.$common.'/>';
            $svg .= '<line x1="16" y1="4" x2="16" y2="28" '.$common.'/>';
            break;
        case 2:
            $svg .= '<circle cx="16" cy="16" r="12" fill="white" '.$common.'/>';
            $svg .= '<path d="M16 16 L16 4 A12 12 0 0 1 28 16 Z" fill="#17212b" stroke="none"/>';
            $svg .= '<circle cx="16" cy="16" r="12" fill="none" '.$common.'/>';
            break;
        case 3:
            $svg .= '<circle cx="16" cy="16" r="12" fill="white" '.$common.'/>';
            $svg .= '<path d="M16 16 L16 4 A12 12 0 0 1 28 16 Z" fill="#17212b" stroke="none"/>';
            $svg .= '<line x1="16" y1="4" x2="16" y2="28" '.$common.'/>';
            $svg .= '<circle cx="16" cy="16" r="12" fill="none" '.$common.'/>';
            break;
        case 4:
            $svg .= '<circle cx="16" cy="16" r="12" fill="white" '.$common.'/>';
            $svg .= '<path d="M16 4 A12 12 0 0 1 16 28 Z" fill="#17212b" stroke="none"/>';
            $svg .= '<line x1="16" y1="4" x2="16" y2="28" '.$common.'/>';
            $svg .= '<circle cx="16" cy="16" r="12" fill="none" '.$common.'/>';
            break;
        case 5:
            $svg .= '<circle cx="16" cy="16" r="12" fill="white" '.$common.'/>';
            $svg .= '<path d="M16 4 A12 12 0 0 1 16 28 Z" fill="#17212b" stroke="none"/>';
            $svg .= '<line x1="4" y1="16" x2="16" y2="16" '.$common.'/>';
            $svg .= '<line x1="16" y1="4" x2="16" y2="28" '.$common.'/>';
            $svg .= '<circle cx="16" cy="16" r="12" fill="none" '.$common.'/>';
            break;
        case 6:
            $svg .= '<circle cx="16" cy="16" r="12" fill="#17212b" '.$common.'/>';
            $svg .= '<path d="M16 16 L16 4 A12 12 0 0 0 4 16 Z" fill="white" stroke="none"/>';
            $svg .= '<path d="M16 4 L16 16 L4 16" fill="none" '.$common.'/>';
            $svg .= '<circle cx="16" cy="16" r="12" fill="none" '.$common.'/>';
            break;
        case 7:
            $svg .= '<circle cx="16" cy="16" r="12" fill="#17212b" '.$common.'/>';
            $svg .= '<line x1="16" y1="5" x2="16" y2="27" stroke="white" stroke-width="2.4" stroke-linecap="round"/>';
            $svg .= '<circle cx="16" cy="16" r="12" fill="none" '.$common.'/>';
            break;
        case 8:
            $svg .= '<circle cx="16" cy="16" r="12" fill="#17212b" '.$common.'/>';
            break;
    }
    return $svg . '</svg>';
}

$allPoints = [];
$error = null;

$csvContent = fetch_wxsim_csv($csvFile);
if ($csvContent === false) {
    $error = 'Unable to retrieve the live WXSIM CSV file.';
} else {
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $csvContent);
    rewind($fh);
    $rawHeader = fgetcsv($fh, 0, ',', '"', '\\');
    if (!$rawHeader) {
        $error = 'CSV header row could not be read.';
    } else {
        $header = array_map('clean_header', $rawHeader);
        $index = array_flip($header);
        $required = ['Year', 'Month', 'Day', 'Time', 'Sky Cov'];
        foreach ($required as $name) {
            if (!array_key_exists($name, $index)) {
                $error = "Required WXSIM column '{$name}' was not found.";
                break;
            }
        }

        if (!$error) {
            while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
                if (!isset($row[$index['Year']]) || !is_numeric(trim($row[$index['Year']]))) {
                    continue; // skip units row
                }

                $year  = (int)trim($row[$index['Year']]);
                $month = (int)trim($row[$index['Month']]);
                $day   = (int)trim($row[$index['Day']]);
                $time  = (float)trim($row[$index['Time']]);
                $sky   = max(0, min(100, (float)trim($row[$index['Sky Cov']])));

                $localString = wxsim_local_datetime($year, $month, $day, $time);
                $dt = DateTime::createFromFormat('Y-m-d H:i:s', $localString);
                if (!$dt) continue;

                $allPoints[] = [
                    'ts'      => $dt->getTimestamp(),
                    'date'    => $dt->format('Y-m-d'),
                    'dayLong' => $dt->format('l j M'),
                    'time'    => $dt->format('H:i'),
                    'hour'    => (int)$dt->format('G'),
                    'minute'  => (int)$dt->format('i'),
                    'sky'     => round($sky, 1),
                    'okta'    => percent_to_oktas($sky),
                    'label'   => $dt->format('D j M H:i'),
                ];
            }
        }
    }
    fclose($fh);
}

if (!$error && $allPoints && $maxHours > 0) {
    $startTs = $allPoints[0]['ts'];
    $endTs = $startTs + ($maxHours * 3600);
    $allPoints = array_values(array_filter($allPoints, fn($p) => $p['ts'] <= $endTs));
}

// Select the WXSIM row nearest each standard 3-hour chart slot.
// WXSIM forecast rows are normally 30 minutes apart, but their minute
// offset depends on when the WXSIM run actually completed.  Therefore
// the rows may be at :00/:30, :20/:50, etc.  Requiring minute == 00
// would sometimes reject every row in a newly generated forecast.
$displayPoints = [];

if (!$error) {
    $pointsByDate = [];
    foreach ($allPoints as $p) {
        $pointsByDate[$p['date']][] = $p;
    }

    foreach ($pointsByDate as $date => $datePoints) {
        for ($slotHour = 0; $slotHour < 24; $slotHour += $displayIntervalHours) {
            $targetMinutes = $slotHour * 60;
            $best = null;
            $bestDiff = PHP_INT_MAX;

            foreach ($datePoints as $p) {
                $pointMinutes = ($p['hour'] * 60) + $p['minute'];
                $diff = abs($pointMinutes - $targetMinutes);

                if ($diff < $bestDiff) {
                    $best = $p;
                    $bestDiff = $diff;
                }
            }

            // A 30-minute WXSIM output should always have a row within
            // 30 minutes of the requested chart slot.  Allow 45 minutes
            // as a small safety margin.
            if ($best !== null && $bestDiff <= 45) {
                $best['slotHour'] = $slotHour;
                $displayPoints[] = $best;
            }
        }
    }
}

$days = [];
foreach ($displayPoints as $p) {
    if (!isset($days[$p['date']])) {
        $days[$p['date']] = ['label' => $p['dayLong'], 'points' => []];
    }
    $days[$p['date']]['points'][] = $p;
}

$rangeStart = $allPoints ? $allPoints[0]['label'] : '';
$rangeEnd   = $allPoints ? $allPoints[count($allPoints)-1]['label'] : '';
$loadedHash = ($csvContent !== false) ? sha1($csvContent) : '';
$loadedFileTime = date('D j M Y H:i:s');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($panelTitle) ?></title>
<style>
:root{--border:#d8e1e8;--text:#203040;--muted:#70808e;--bg:#fff;--soft:#f4f7f9;--line:#e5ebef}
*{box-sizing:border-box}
body{margin:0;background:#f3f6f8;font-family:Arial,Helvetica,sans-serif;color:var(--text)}
.cloud-panel{max-width:1180px;margin:14px auto;padding:14px;background:var(--bg);border:1px solid var(--border);border-radius:12px;box-shadow:0 2px 10px rgba(30,50,70,.08)}
.cloud-head{background:linear-gradient(135deg,#eaf4fb,#f7fbfd);border:1px solid #b9d5e8;border-radius:10px;padding:14px 18px;margin-bottom:10px}.cloud-title{font-size:24px;font-weight:700;margin:0;color:#173f5f}
.cloud-sub{font-size:13px;color:var(--muted);margin:5px 0 0}
.day-row{display:grid;grid-template-columns:145px 1fr;border-top:1px solid var(--line);min-height:84px}
.day-row:last-of-type{border-bottom:1px solid var(--line)}
.day-label{display:flex;align-items:center;font-weight:700;font-size:14px;padding:10px 12px;background:var(--soft);border-right:1px solid var(--line)}
.slots{display:grid;grid-template-columns:repeat(8,minmax(58px,1fr));align-items:stretch}
.slot{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;min-height:84px;border-right:1px solid #edf1f4;cursor:default}
.slot:last-child{border-right:0}
.slot-time{font-size:11px;color:var(--muted);font-variant-numeric:tabular-nums}
.okta-number{font-size:11px;font-weight:700;color:#3a4a57}
.sky-percent{font-size:11px;color:#526575;font-variant-numeric:tabular-nums}
.okta-symbol{display:block}
.tip{visibility:hidden;opacity:0;position:absolute;z-index:20;bottom:66px;left:50%;transform:translateX(-50%);min-width:130px;padding:7px 9px;border-radius:6px;background:rgba(24,34,44,.94);color:#fff;font-size:12px;line-height:1.4;text-align:center;white-space:nowrap;transition:opacity .12s}
.slot:hover .tip{visibility:visible;opacity:1}
.legend{margin-top:18px;display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap}
.legend-title{font-size:12px;font-weight:700;color:#51616e;padding-top:11px;margin-right:4px}
.legend-item{display:flex;flex-direction:column;align-items:center;min-width:49px;font-size:11px;color:var(--muted)}
.legend-item .okta-symbol{margin-bottom:2px}
.note{margin-top:11px;font-size:11px;color:var(--muted)}
.err{padding:18px;border:1px solid #d99;background:#fff4f4;color:#822;border-radius:8px}
.suite-nav{display:flex;gap:7px;flex-wrap:wrap;margin:0 0 10px}.suite-nav a{text-decoration:none;color:#174a70;background:#edf7fc;border:1px solid #b8d8e9;border-radius:7px;padding:7px 11px;font-size:12px;font-weight:700}.suite-nav a:hover{background:#dff1fa}.suite-nav a.current{background:#1769aa;color:#fff;border-color:#1769aa}
@media(max-width:800px){.cloud-panel{margin:0;border-radius:0;padding:10px}.cloud-title{font-size:20px}.day-row{grid-template-columns:105px minmax(620px,1fr)}.day-label{font-size:12px}.forecast-scroll{overflow-x:auto}.slots{grid-template-columns:repeat(8,78px)}}

/* Shared display/print controls added for public release */
.page-actions{display:flex;gap:7px;justify-content:flex-end;flex-wrap:wrap;margin:0 0 10px}
.page-actions button{border:1px solid #b8c7d3;border-radius:7px;background:#fff;color:#203040;padding:7px 11px;font-size:12px;font-weight:700;cursor:pointer}
.page-actions button:hover{background:#eef5f9}
@media print{
    .page-actions,.suite-nav{display:none!important}
    body{background:#fff!important}
}
</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
</head>
<body>
<div class="cloud-panel" id="wxsim-cloud-cover">
<?php if ($error): ?>
    <div class="err"><strong>Cloud cover:</strong> <?= htmlspecialchars($error) ?></div>
<?php elseif (!$displayPoints): ?>
    <div class="err"><strong>Cloud cover:</strong> no suitable WXSIM forecast rows were found for the selected interval.</div>
<?php else: ?>
    <div class="cloud-head"><h2 class="cloud-title"><?= htmlspecialchars($panelTitle) ?></h2>
    <div class="cloud-sub">WXSIM total sky cover &bull; <?= (int)$displayIntervalHours ?>-hourly okta forecast &bull; local forecast time &bull; <?= htmlspecialchars($rangeStart) ?> to <?= htmlspecialchars($rangeEnd) ?><br><span id="wxsim-update-status">Live WXSIM CSV retrieved: <?= htmlspecialchars($loadedFileTime) ?> local</span></div></div>
    <nav class="suite-nav" aria-label="WXSIM forecast pages"><a href="index.php?page=stage1">7-Day Comparison</a><a href="index.php?page=detailed">Detailed Comparison</a><a href="index.php?page=stage2">Accuracy Dashboard</a><a class="current" href="index.php?page=cloud">Cloud Cover</a>
        <a href="index.php?page=setup">Setup &amp; Tests</a></nav>

<div class="page-actions">
    <button type="button" onclick="window.print()">Print</button>
    <button type="button" onclick="wxfaExportPagePDF()">Export PDF</button>
</div>


    <div class="forecast-scroll">
    <?php foreach ($days as $day): ?>
        <div class="day-row">
            <div class="day-label"><?= htmlspecialchars($day['label']) ?></div>
            <div class="slots">
                <?php
                // Create eight fixed 3-hour positions so every day lines up vertically.
                $byHour = [];
                foreach ($day['points'] as $p) $byHour[$p['slotHour']] = $p;
                for ($hour = 0; $hour < 24; $hour += $displayIntervalHours):
                    $p = $byHour[$hour] ?? null;
                ?>
                    <div class="slot">
                        <?php if ($p): ?>
                            <div class="slot-time"><?= sprintf('%02d:00', $hour) ?></div>
                            <?= okta_svg($p['okta']) ?>
                            <div class="okta-number"><?= $p['okta'] ?>/8</div>
                            <div class="sky-percent">Sky <?= number_format($p['sky'],0) ?>%</div>
                            <div class="tip">
                                <strong><?= htmlspecialchars($p['label']) ?></strong><br>
                                <?= $p['okta'] ?> oktas<?php if ($showSourcePercentOnHover): ?><br>WXSIM: <?= number_format($p['sky'],1) ?>%<?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="slot-time"><?= sprintf('%02d:00', $hour) ?></div>
                            <div style="height:34px;display:flex;align-items:center;color:#c3cbd1">&ndash;</div>
                            <div class="okta-number" style="color:#b7c0c7">&nbsp;</div>
                            <div class="sky-percent">&nbsp;</div>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>
        </div>
    <?php endforeach; ?>
    </div>

    <div class="legend">
        <div class="legend-title">Cloud amount:</div>
        <?php for ($o=0; $o<=8; $o++): ?>
            <div class="legend-item"><?= okta_svg($o,30) ?><strong><?= $o ?>/8</strong></div>
        <?php endfor; ?>
    </div>
    <div class="note">0 oktas = clear sky; 8 oktas = complete cloud cover. WXSIM percentage values are converted to the nearest eighth. The 9-okta “sky obscured” code is not generated from WXSIM percentage cloud cover.</div>
<?php endif; ?>
</div>
<script>
(function () {
    // WXSIM run start times, in Europe/London station time.
    const runMinutes = [
        2 * 60 + 8,
        6 * 60 + 8,
        11 * 60 + 8,
        15 * 60 + 8,
        21 * 60 + 8
    ];
    const watchWindowMinutes = 35; // Covers normal <20-minute processing with margin.
    const checkEveryMs = 60 * 1000;
    const loadedHash = <?= json_encode($loadedHash) ?>;
    let lastKnownHash = loadedHash;

    // Return station-local hour/minute even if the visitor is in another timezone.
    function londonClockParts() {
        const parts = new Intl.DateTimeFormat('en-GB', {
            timeZone: 'Europe/London',
            hour: '2-digit', minute: '2-digit', second: '2-digit',
            hourCycle: 'h23'
        }).formatToParts(new Date());
        const get = type => Number(parts.find(p => p.type === type).value);
        return { hour: get('hour'), minute: get('minute'), second: get('second') };
    }

    function currentMinuteOfDay() {
        const t = londonClockParts();
        return t.hour * 60 + t.minute + t.second / 60;
    }

    function inForecastWatchWindow() {
        const now = currentMinuteOfDay();
        return runMinutes.some(start => now >= start && now <= start + watchWindowMinutes);
    }

    function minutesUntilNextWindow() {
        const now = currentMinuteOfDay();
        for (const start of runMinutes) {
            if (start > now) return start - now;
        }
        return (24 * 60 - now) + runMinutes[0];
    }

    async function checkCsv() {
        try {
            const response = await fetch(location.pathname + '?wxsim_status=1&_=' + Date.now(), {
                cache: 'no-store', credentials: 'same-origin'
            });
            if (!response.ok) throw new Error('HTTP ' + response.status);
            const status = await response.json();

            if (status.exists && status.hash && lastKnownHash && status.hash !== lastKnownHash) {
                // The live latest.csv has changed: reload the whole chart.
                location.replace(location.pathname + '?updated=' + Date.now());
                return;
            }
            if (status.hash) lastKnownHash = status.hash;
        } catch (e) {
            // A temporary failed check is harmless; the next scheduled check will retry.
        }
        scheduleNextCheck();
    }

    function scheduleNextCheck() {
        if (inForecastWatchWindow()) {
            setTimeout(checkCsv, checkEveryMs);
        } else {
            // Wake at the next WXSIM start time. Cap the delay so clock/DST changes are re-evaluated.
            const delay = Math.max(30000, Math.min(minutesUntilNextWindow() * 60000, 60 * 60000));
            setTimeout(function () {
                if (inForecastWatchWindow()) checkCsv();
                else scheduleNextCheck();
            }, delay);
        }
    }

    // Check immediately if the page is opened while a WXSIM forecast is running.
    if (inForecastWatchWindow()) checkCsv();
    else scheduleNextCheck();
})();
</script>

<script>
async function wxfaExportPagePDF(){
    if(typeof html2canvas==='undefined'||!window.jspdf||!window.jspdf.jsPDF){
        alert('The PDF library has not loaded.'); return;
    }
    const actions=document.querySelector('.page-actions');
    if(actions) actions.style.display='none';
    try{
        const canvas=await html2canvas(document.body,{scale:1.5,useCORS:true,backgroundColor:'#ffffff'});
        const {jsPDF}=window.jspdf;
        const pdf=new jsPDF({orientation:'landscape',unit:'mm',format:'a4'});
        const pageW=pdf.internal.pageSize.getWidth(), pageH=pdf.internal.pageSize.getHeight();
        const margin=8, usableW=pageW-margin*2, usableH=pageH-margin*2;
        const imgW=usableW, imgH=canvas.height*imgW/canvas.width;
        const img=canvas.toDataURL('image/jpeg',0.92);
        let y=margin, remaining=imgH;
        pdf.addImage(img,'JPEG',margin,y,imgW,imgH);
        remaining-=usableH;
        while(remaining>0){
            pdf.addPage();
            y=margin-(imgH-remaining);
            pdf.addImage(img,'JPEG',margin,y,imgW,imgH);
            remaining-=usableH;
        }
        pdf.save('WXSIM-cloud-cover.pdf');
    } finally {
        if(actions) actions.style.display='';
    }
}
</script>

</body>
</html>

<?php
exit;
}

if ($page === 'setup') {

/* WXSIM Forecast vs Weather Underground Actuals - Setup & Tests */

error_reporting(E_ALL); ini_set('display_errors','1');
require_once __DIR__.'/config.php';
header('Content-Type: text/html; charset=UTF-8');

/* Protect Setup & Tests without affecting the four public dashboards. */
wxfa_start_admin_session();
$authError = '';

if (isset($_GET['logout'])) {
    wxfa_admin_logout();
    header('Location: index.php?page=setup');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['auth_action'] ?? '') === 'create_admin_password') {
    $password = (string)($_POST['admin_password'] ?? '');
    $confirm  = (string)($_POST['admin_password_confirm'] ?? '');
    if (wxfa_admin_password_configured()) {
        $authError = 'An administrator password is already configured.';
    } elseif (strlen($password) < 10) {
        $authError = 'Choose an administrator password containing at least 10 characters.';
    } elseif ($password !== $confirm) {
        $authError = 'The two passwords do not match.';
    } else {
        $newSettings = wxfa_load_settings();
        $newSettings['ADMIN_PASSWORD_HASH'] = password_hash($password, PASSWORD_DEFAULT);
        if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0775, true);
        $json = json_encode($newSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tmp = SETTINGS_FILE . '.auth.tmp';
        if ($json !== false && @file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) !== false && @rename($tmp, SETTINGS_FILE)) {
            @chmod(SETTINGS_FILE, 0640);
            $WXFA_SETTINGS = $newSettings;
            wxfa_set_admin_authenticated(true);
        }
        @unlink($tmp);
        $authError = 'The administrator password could not be saved. Check that the data directory is writable.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['auth_action'] ?? '') === 'admin_login') {
    $password = (string)($_POST['admin_password'] ?? '');
    $hash = wxfa_admin_hash();
    if ($hash !== '' && password_verify($password, $hash)) {
        wxfa_set_admin_authenticated(true);
    }
    $authError = 'Incorrect administrator password.';
}

if (!wxfa_admin_authenticated()) {
    $creating = !wxfa_admin_password_configured();
    $title = $creating ? 'Create Setup administrator password' : 'Setup & Tests — Administrator Login';
    ?><!doctype html>
    <html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?=htmlspecialchars($title,ENT_QUOTES,'UTF-8')?></title>
    <style>
    body{font-family:Segoe UI,Arial,sans-serif;background:#eef2f6;color:#1d2733;margin:0}.box{max-width:650px;margin:60px auto;background:#fff;border:1px solid #d8e0e8;border-radius:12px;padding:24px;box-shadow:0 3px 12px rgba(0,0,0,.08)}
    h1{color:#163b5c;margin-top:0}.note{color:#667482;line-height:1.55}.error{background:#fff0f0;border:1px solid #e4aaaa;color:#8b2020;padding:10px 12px;border-radius:7px;margin:14px 0}label{display:block;font-weight:700;margin:14px 0 6px}input{width:100%;box-sizing:border-box;padding:10px;border:1px solid #bdc8d2;border-radius:6px;font-size:16px}button{margin-top:18px;background:#1769aa;color:#fff;border:0;border-radius:6px;padding:10px 16px;font-weight:700;cursor:pointer}a{color:#1769aa;font-weight:700}
    </style></head><body><div class="box">
    <h1><?=htmlspecialchars($title,ENT_QUOTES,'UTF-8')?></h1>
    <?php if($authError!==''):?><div class="error"><?=htmlspecialchars($authError,ENT_QUOTES,'UTF-8')?></div><?php endif;?>
    <?php if($creating):?>
        <p class="note">This is the first protected access to Setup &amp; Tests. Create an administrator password. The password is not stored in the scripts.</p>
        <form method="post" action="index.php?page=setup">
        <input type="hidden" name="auth_action" value="create_admin_password">
        <label for="admin_password">Administrator password</label><input id="admin_password" type="password" name="admin_password" minlength="10" autocomplete="new-password" required>
        <label for="admin_password_confirm">Confirm password</label><input id="admin_password_confirm" type="password" name="admin_password_confirm" minlength="10" autocomplete="new-password" required>
        <button type="submit">Create password &amp; open Setup</button></form>
    <?php else:?>
        <p class="note">A password is required to protect your configuration details when this installation is published on the internet. The password is not stored in the scripts.</p>
        <form method="post" action="index.php?page=setup">
        <input type="hidden" name="auth_action" value="admin_login">
        <label for="admin_password">Administrator password</label><input id="admin_password" type="password" name="admin_password" autocomplete="current-password" required autofocus>
        <button type="submit">Sign in</button></form>
    <?php endif;?>
    <p class="note" style="margin-top:22px"><a href="index.php?page=stage1">Return to 7-Day Comparison</a></p>
    </div></body></html><?php
    exit;
}

/* Password manager: available only after administrator login. */
$passwordMessage = '';
$passwordError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_admin_password') {
    $currentPassword = (string)($_POST['current_admin_password'] ?? '');
    $newPassword = (string)($_POST['new_admin_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_admin_password'] ?? '');
    $currentHash = wxfa_admin_hash();

    if ($currentHash === '' || !password_verify($currentPassword, $currentHash)) {
        $passwordError = 'The current administrator password is incorrect.';
    } elseif (strlen($newPassword) < 10) {
        $passwordError = 'The new administrator password must contain at least 10 characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $passwordError = 'The two new passwords do not match.';
    } else {
        $changedSettings = wxfa_load_settings();
        $changedSettings['ADMIN_PASSWORD_HASH'] = password_hash($newPassword, PASSWORD_DEFAULT);
        $json = json_encode($changedSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tmp = SETTINGS_FILE . '.password.tmp';
        if ($json !== false && @file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) !== false && @rename($tmp, SETTINGS_FILE)) {
            @chmod(SETTINGS_FILE, 0640);
            $WXFA_SETTINGS = $changedSettings;
            $passwordMessage = 'Administrator password changed successfully.';
        } else {
            @unlink($tmp);
            $passwordError = 'The new administrator password could not be saved.';
        }
    }
}

function h($v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
function ensure_dir(string $p): bool { return is_dir($p) || @mkdir($p,0775,true); }
function valid_tz(string $tz): bool { try { new DateTimeZone($tz); return $tz!==''; } catch(Throwable $e){ return false; } }
function timezone_groups(): array {
    $groups=[];
    foreach(DateTimeZone::listIdentifiers(DateTimeZone::ALL) as $tz){
        if($tz==='UTC'){ $groups['UTC'][]='UTC'; continue; }
        $p=strpos($tz,'/');
        $region=$p===false?'Other':substr($tz,0,$p);
        $groups[$region][]=$tz;
    }
    if(!isset($groups['UTC'])) $groups=['UTC'=>['UTC']]+$groups;
    return $groups;
}
function http_url(string $s): bool { return (bool)preg_match('#^https?://#i',$s); }
function http_status(array $headers): int { foreach($headers as $x) if(preg_match('#^HTTP/\S+\s+(\d{3})#',$x,$m)) return (int)$m[1]; return 0; }
function atomic_json(string $path,array $data): bool {
    if(!ensure_dir(dirname($path))) return false;
    $json=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($json===false) return false;
    $tmp=$path.'.tmp'; if(@file_put_contents($tmp,$json.PHP_EOL,LOCK_EX)===false) return false;
    if(!@rename($tmp,$path)){ @unlink($tmp); return false; } @chmod($path,0640); return true;
}
function badge(bool $ok,string $yes='PASS',string $no='FAIL'): string { return '<span class="badge '.($ok?'pass':'fail').'">'.h($ok?$yes:$no).'</span>'; }
function row(string $name,bool $ok,string $detail): void { echo '<tr><th>'.h($name).'</th><td>'.badge($ok).'</td><td>'.$detail.'</td></tr>'; }

function fetch_source(string $source): array {
    $r=['ok'=>false,'type'=>http_url($source)?'Remote HTTP/HTTPS URL':'Local server file','method'=>'','status'=>null,'ctype'=>'','bytes'=>0,'text'=>'','error'=>''];
    if($source===''){ $r['error']='No WXSIM latest.csv location configured.'; return $r; }
    if(!http_url($source)){
        $r['method']='Local file read'; if(!is_file($source)){ $r['error']='Local file does not exist.'; return $r; }
        if(!is_readable($source)){ $r['error']='Local file is not readable by PHP.'; return $r; }
        $t=@file_get_contents($source); if($t===false){ $r['error']='PHP could not read the local file.'; return $r; }
        $r['ok']=true; $r['text']=$t; $r['bytes']=strlen($t); return $r;
    }
    if(function_exists('curl_init')){
        $r['method']='cURL'; $ch=curl_init($source);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>20,CURLOPT_USERAGENT=>'WXSIM-Forecast-Comparison/1.0']);
        $t=curl_exec($ch); $err=curl_error($ch); $st=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $ct=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE); curl_close($ch);
        $r['status']=$st?:null; $r['ctype']=$ct;
        if($t===false){ $r['error']=$err?:'cURL request failed.'; return $r; }
        $r['text']=(string)$t; $r['bytes']=strlen((string)$t); $r['ok']=$st>=200&&$st<300; if(!$r['ok']) $r['error']='HTTP status '.$st.' returned.'; return $r;
    }
    $r['method']='PHP file_get_contents'; $ctx=stream_context_create(['http'=>['timeout'=>20,'ignore_errors'=>true,'header'=>"User-Agent: WXSIM-Forecast-Comparison/1.0\r\nAccept: text/csv,text/plain,*/*\r\n"]]);
    $http_response_header=[]; $t=@file_get_contents($source,false,$ctx); $hdr=is_array($http_response_header)?$http_response_header:[]; $st=http_status($hdr); $r['status']=$st?:null;
    foreach($hdr as $x) if(stripos($x,'Content-Type:')===0){ $r['ctype']=trim(substr($x,13)); break; }
    if($t===false){ $r['error']='No response could be read from the remote source.'; return $r; }
    $r['text']=(string)$t; $r['bytes']=strlen((string)$t); $r['ok']=$st===0||($st>=200&&$st<300); if(!$r['ok']) $r['error']='HTTP status '.$st.' returned.'; return $r;
}

function csv_diag(string $text): array {
    $o=['ok'=>false,'rows'=>0,'cols'=>0,'headers'=>[],'first'=>[],'date'=>'','time'=>'','times'=>[],'error'=>''];
    $text=preg_replace('/^\xEF\xBB\xBF/','',$text); $lines=preg_split('/\r\n|\n|\r/',trim($text));
    if(!$lines||count($lines)<2){ $o['error']='No CSV header plus data rows found.'; return $o; }
    $hdr=array_map('trim',str_getcsv((string)$lines[0], ',', '"', '\\')); $o['headers']=$hdr; $o['cols']=count($hdr); $di=$ti=null; $yi=$mi=$dai=null;
    foreach($hdr as $i=>$name){
        $n=strtolower(preg_replace('/[^a-z0-9]+/i','',$name));
        if($di===null&&in_array($n,['date','forecastdate'],true)){ $di=$i;$o['date']=$name; }
        if($yi===null&&$n==='year') $yi=$i;
        if($mi===null&&$n==='month') $mi=$i;
        if($dai===null&&$n==='day') $dai=$i;
        if($ti===null&&in_array($n,['time','forecasttime','hour'],true)){ $ti=$i;$o['time']=$name; }
    }
    if($di===null && $yi!==null && $mi!==null && $dai!==null) $o['date']='Year + Month + Day';
    $rows=[]; for($i=1;$i<count($lines);$i++){ if(trim((string)$lines[$i])==='') continue; $x=str_getcsv((string)$lines[$i], ',', '"', '\\'); if(count($x)>1) $rows[]=array_map('trim',$x); }
    $o['rows']=count($rows); $o['first']=$rows[0]??[];
    if($ti!==null){ $times=[]; foreach($rows as $x){ if(!isset($x[$ti])) continue; $v=trim((string)$x[$ti]); if($v==='') continue; if(is_numeric($v)){ $f=(float)$v;$hh=(int)floor($f);$mm=(int)round(($f-$hh)*60);if($mm===60){$hh++;$mm=0;}$v=sprintf('%02d:%02d',$hh%24,$mm);} $times[]=$v; if(count($times)>=12) break; } $o['times']=array_values(array_unique($times)); }
    $o['ok']=$o['rows']>0&&$o['cols']>1; if(!$o['ok']) $o['error']='No usable CSV data rows found.'; return $o;
}

function wu_diag(string $station,string $key): array {
    $r=['ok'=>false,'method'=>'','status'=>0,'received'=>false,'json'=>false,'error'=>'','data'=>null,'excerpt'=>''];
    if($station===''){ $r['error']='Station ID is blank.'; return $r; } if($key===''){ $r['error']='API key is blank.'; return $r; }
    $url='https://api.weather.com/v2/pws/observations/current?stationId='.rawurlencode($station).'&format=json&units=m&numericPrecision=decimal&apiKey='.rawurlencode($key);
    $resp=false;$st=0;
    if(function_exists('curl_init')){ $r['method']='cURL';$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>20,CURLOPT_USERAGENT=>'WXSIM-Forecast-Comparison/1.0',CURLOPT_HTTPHEADER=>['Accept: application/json']]);$resp=curl_exec($ch);$st=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);if($resp===false&&$err!=='')$r['error']=$err; }
    else { $r['method']='PHP file_get_contents';$ctx=stream_context_create(['http'=>['timeout'=>20,'ignore_errors'=>true,'header'=>"User-Agent: WXSIM-Forecast-Comparison/1.0\r\nAccept: application/json\r\n"]]);$http_response_header=[];$resp=@file_get_contents($url,false,$ctx);$st=http_status(is_array($http_response_header)?$http_response_header:[]); }
    $r['status']=$st;$r['received']=$resp!==false; if($resp===false){ if($r['error']==='')$r['error']='No response received.'; return $r; }
    $raw=(string)$resp;$data=json_decode($raw,true);$r['json']=is_array($data);$r['data']=is_array($data)?$data:null;if(strlen($raw)<=800&&$st!==200)$r['excerpt']=str_replace($key,'[API KEY HIDDEN]',$raw);
    if(!is_array($data)){ $r['error']='Response was not valid JSON.'; return $r; }
    if($st!==200){ $r['error']=$st===401?'HTTP 401: API key not accepted.':($st===403?'HTTP 403: access denied.':($st===404?'HTTP 404: station ID may not exist.':'HTTP status '.$st.' returned.')); return $r; }
    if(empty($data['observations'][0])){ $r['error']='HTTP 200 returned but no observation was present.'; return $r; }
    $r['ok']=true; return $r;
}

$settings=wxfa_load_settings(); $messages=[];$errors=[];$savedNow=false;
$days=['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];$wuUnits=['metric','imperial'];$disp=['uk','metric','imperial'];
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='save_settings'){
    $c=['SITE_NAME'=>trim((string)($_POST['SITE_NAME']??'')),'SITE_LOCATION'=>trim((string)($_POST['SITE_LOCATION']??'')),'STATION_TIMEZONE'=>trim((string)($_POST['STATION_TIMEZONE']??'')),'WXSIM_LATEST_CSV'=>trim((string)($_POST['WXSIM_LATEST_CSV']??'')),'WXSIM_OUTPUT_UNITS'=>strtolower(trim((string)($_POST['WXSIM_OUTPUT_UNITS']??''))),'FORECAST_START_DAY'=>trim((string)($_POST['FORECAST_START_DAY']??'')),'WU_STATION_ID'=>strtoupper(trim((string)($_POST['WU_STATION_ID']??''))),'WU_API_KEY'=>trim((string)($_POST['WU_API_KEY']??'')),'DISPLAY_UNITS'=>strtolower(trim((string)($_POST['DISPLAY_UNITS']??''))),'ADMIN_PASSWORD_HASH'=>(string)($settings['ADMIN_PASSWORD_HASH']??'')];
    if($c['WU_API_KEY']===''&&($settings['WU_API_KEY']??'')!=='')$c['WU_API_KEY']=$settings['WU_API_KEY'];
    if($c['SITE_NAME']==='') $errors[]='Station / site name is required.';
    if($c['SITE_LOCATION']==='') $errors[]='Station location is required.';
    if(!valid_tz($c['STATION_TIMEZONE'])) $errors[]='Please select a valid station time zone.';
    if($c['WXSIM_LATEST_CSV']==='') $errors[]='The WXSIM latest.csv location is required.';
    if(!in_array($c['WXSIM_OUTPUT_UNITS'],$wuUnits,true)) $errors[]='Please select the units used in WXSIM latest.csv.';
    if(!in_array($c['FORECAST_START_DAY'],$days,true)) $errors[]='Please select the forecast start day.';
    if($c['WU_STATION_ID']==='') $errors[]='Weather Underground Location ID is required.';
    if($c['WU_API_KEY']==='') $errors[]='Weather Underground API Key is required.';
    if(!in_array($c['DISPLAY_UNITS'],$disp,true)) $errors[]='Please select the chart display units.';
    if(!$errors){
        ensure_dir(DATA_DIR);ensure_dir(WEEKS_DIR);ensure_dir(LOG_DIR);
        if(atomic_json(SETTINGS_FILE,$c)){
            /* Do not claim success until the file can be reopened and decoded. */
            $verifyRaw=@file_get_contents(SETTINGS_FILE);
            $verifyData=$verifyRaw!==false ? json_decode($verifyRaw,true) : null;
            if(is_file(SETTINGS_FILE) && is_array($verifyData)){
                $verifyOk=true;
                foreach($c as $k=>$v){
                    if(!array_key_exists($k,$verifyData) || (string)$verifyData[$k] !== (string)$v){
                        $verifyOk=false;
                        break;
                    }
                }
                if($verifyOk){
                    $settings=$verifyData;
                    $savedNow=true;
                    $messages[]='Settings saved and verified successfully in '.SETTINGS_FILE.'.';
                } else {
                    $errors[]='The settings file was written but its contents could not be verified. Please check file permissions and disk space.';
                    $settings=$c;
                }
            } else {
                $errors[]='The settings file could not be reopened after writing. Please check file permissions.';
                $settings=$c;
            }
        } else {
            $errors[]='Could not write '.SETTINGS_FILE.'. Check that the data directory is writable by PHP.';
            $settings=$c;
        }
    } else $settings=$c;
}
/* After a successful save, automatically run both connection tests. */
$runWx=isset($_POST['test_wxsim'])||isset($_POST['test_all'])||$savedNow;
$runWu=isset($_POST['test_wu'])||isset($_POST['test_all'])||$savedNow;
$dataOK=ensure_dir(DATA_DIR)&&is_writable(DATA_DIR);$weeksOK=ensure_dir(WEEKS_DIR)&&is_writable(WEEKS_DIR);$logsOK=ensure_dir(LOG_DIR)&&is_writable(LOG_DIR);$settingsOK=is_file(SETTINGS_FILE)?is_writable(SETTINGS_FILE):$dataOK;
$phpOK=version_compare(PHP_VERSION,'8.0.0','>=');$tzOK=valid_tz((string)$settings['STATION_TIMEZONE']);$wxuOK=in_array((string)$settings['WXSIM_OUTPUT_UNITS'],$wuUnits,true);$dispOK=in_array((string)$settings['DISPLAY_UNITS'],$disp,true);$dayOK=in_array((string)$settings['FORECAST_START_DAY'],$days,true);$wxCfg=trim((string)$settings['WXSIM_LATEST_CSV'])!=='';$wuId=trim((string)$settings['WU_STATION_ID'])!=='';$wuKey=trim((string)$settings['WU_API_KEY'])!=='';
$wx=$csv=null;if($runWx&&$wxCfg){$wx=fetch_source((string)$settings['WXSIM_LATEST_CSV']);if($wx['ok'])$csv=csv_diag((string)$wx['text']);}
$wu=null;if($runWu&&$wuId&&$wuKey)$wu=wu_diag((string)$settings['WU_STATION_ID'],(string)$settings['WU_API_KEY']);
$localReady=$phpOK&&$dataOK&&$weeksOK&&$logsOK&&$settingsOK&&$tzOK&&$wxuOK&&$dispOK&&$dayOK&&$wxCfg&&$wuId&&$wuKey;$wxReady=$wx!==null&&$wx['ok']&&$csv!==null&&$csv['ok'];$wuReady=$wu!==null&&$wu['ok'];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Setup & Tests</title>
<style>
:root{--bg:#f3f5f7;--panel:#fff;--ink:#1f2933;--muted:#65727e;--line:#d8dee4;--head:#173f5f;--accent:#1769aa;--pass:#16733c;--fail:#b42318;--warn:#9a6700}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:Arial,Helvetica,sans-serif}.wrap{max-width:1180px;margin:auto;padding:18px}header,.panel{background:#fff;border:1px solid var(--line);border-radius:10px;margin-bottom:12px}header{background:linear-gradient(135deg,#eaf4fb,#f7fbfd);border-color:#b9d5e8;padding:16px 20px}.panel{padding:16px 18px}h1{margin:0 0 5px;color:var(--head);font-size:25px}.sub,.small,.help{color:var(--muted);font-size:12px;line-height:1.5}.panel h2{font-size:18px;margin:0 0 12px;color:var(--head);border-bottom:2px solid #d7e9f4;padding-bottom:7px}.panel h3{font-size:15px;color:var(--head);margin:18px 0 8px}.summary{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:12px}.box{background:#fff;border:1px solid var(--line);border-top:4px solid #4a90b8;border-radius:9px;padding:12px}.box .k{font-size:11px;color:var(--muted);text-transform:uppercase}.box .v{margin-top:6px;font-size:18px;font-weight:bold}.badge{display:inline-block;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:bold}.pass{background:#e9f7ee;color:var(--pass)}.fail{background:#fdecec;color:var(--fail)}.wait{background:#fff5d9;color:var(--warn)}.message{padding:11px 13px;border-radius:7px;margin-bottom:10px}.ok{background:#edf8f0;border:1px solid #b9dfc4}.bad{background:#fff0f0;border:1px solid #efc0c0}.formgrid{display:grid;grid-template-columns:310px 1fr;border:1px solid var(--line);border-radius:8px;overflow:hidden}.formrow{display:contents}.formlabel,.formfield{padding:12px 14px;border-bottom:1px solid var(--line)}.formlabel{background:#fafbfc}.formlabel strong{display:block;font-size:13px;margin-bottom:4px}.formrow:last-child .formlabel,.formrow:last-child .formfield{border-bottom:0}input,select{width:100%;padding:9px 10px;border:1px solid #bcc7d0;border-radius:6px;background:#fff}code{background:#f0f2f4;padding:2px 5px;border-radius:4px}.buttons{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}button{border:0;border-radius:7px;padding:9px 14px;font-weight:bold;cursor:pointer;background:var(--accent);color:#fff}.test{background:#2e6f55}.secondary{background:#526673}table{width:100%;border-collapse:collapse}th,td{padding:8px 9px;border-bottom:1px solid #e5e9ed;text-align:left;vertical-align:top;font-size:13px}th{width:27%;background:#fafbfc}td:nth-child(2){width:82px}pre{white-space:pre-wrap;word-break:break-word;background:#f7f8fa;border:1px solid #e0e4e8;border-radius:6px;padding:10px;font-size:12px}footer{text-align:center;color:var(--muted);font-size:12px;padding:8px 0 20px}@media(max-width:800px){.summary{grid-template-columns:1fr}.formgrid{display:block}.formrow,.formlabel,.formfield{display:block}th,td{display:block;width:100%!important}}
.suite-nav{display:flex;gap:7px;flex-wrap:wrap;margin:0 0 10px}.suite-nav a{text-decoration:none;color:#174a70;background:#edf7fc;border:1px solid #b8d8e9;border-radius:7px;padding:7px 11px;font-size:12px;font-weight:700}.suite-nav a:hover{background:#dff1fa}.suite-nav a.current{background:#1769aa;color:#fff;border-color:#1769aa}</style></head><body><div class="wrap">
<header><h1>Setup &amp; Tests</h1><div class="sub">WXSIM Forecast vs Weather Underground Actuals. Complete the configuration, save it, then run both connection tests. Settings are stored in <code>data/settings.json</code>; <code>config.php</code> remains fixed.</div></header>
<nav class="suite-nav" aria-label="WXSIM forecast pages"><a href="index.php?page=stage1">7-Day Comparison</a><a href="index.php?page=detailed">Detailed Comparison</a><a href="index.php?page=stage2">Accuracy Dashboard</a><a href="index.php?page=cloud">Cloud Cover</a><a class="current" href="index.php?page=setup">Setup &amp; Tests</a><a href="index.php?page=setup&amp;logout=1">Log out</a></nav>
<?php foreach($messages as $m):?><div class="message ok"><?=h($m)?></div><?php endforeach;?><?php foreach($errors as $m):?><div class="message bad"><?=h($m)?></div><?php endforeach;?>
<div class="summary"><div class="box"><div class="k">Configuration / server</div><div class="v"><?=badge($localReady,'READY','NOT READY')?></div></div><div class="box"><div class="k">WXSIM latest.csv</div><div class="v"><?=$runWx?badge($wxReady,'READY','FAILED'):'<span class="badge wait">NOT TESTED</span>'?></div></div><div class="box"><div class="k">Weather Underground</div><div class="v"><?=$runWu?badge($wuReady,'READY','FAILED'):'<span class="badge wait">NOT TESTED</span>'?></div></div></div>
<div class="panel"><h2>Configuration</h2>
<p class="small">Complete all fields below, then press <strong>Save Settings</strong>. Internal server paths are created automatically and are shown separately below.</p>
<form method="post" autocomplete="off"><?=wxfa_admin_token_field()?><input type="hidden" name="action" value="save_settings"><div class="formgrid">
<div class="formrow"><div class="formlabel"><strong>SITE_NAME — Station / site name</strong><div class="help">Name displayed by the program, for example: My Weather Station.</div></div><div class="formfield"><input type="text" name="SITE_NAME" value="<?=h($settings['SITE_NAME'])?>" placeholder="Enter station or site name" required></div></div>
<div class="formrow"><div class="formlabel"><strong>SITE_LOCATION — Station location</strong><div class="help">Plain-English location, for example: Denver, Colorado, USA.</div></div><div class="formfield"><input type="text" name="SITE_LOCATION" value="<?=h($settings['SITE_LOCATION'])?>" placeholder="Enter station location" required></div></div>
<div class="formrow"><div class="formlabel"><strong>STATION_TIMEZONE — Station time zone</strong><div class="help">Select the IANA time zone for the weather station. These standard zones automatically apply local daylight-saving rules where applicable. <a href="https://www.iana.org/time-zones" target="_blank" rel="noopener">IANA time-zone information</a>.</div></div><div class="formfield"><select name="STATION_TIMEZONE" required><option value="">Please select your station time zone…</option><?php foreach(timezone_groups() as $region=>$zones):?><optgroup label="<?=h($region)?>"><?php foreach($zones as $tz):?><option value="<?=h($tz)?>"<?=$settings['STATION_TIMEZONE']===$tz?' selected':''?>><?=h($tz)?></option><?php endforeach;?></optgroup><?php endforeach;?></select></div></div>
<div class="formrow"><div class="formlabel"><strong>WXSIM_LATEST_CSV — WXSIM latest.csv</strong><div class="help">Enter either the full HTTP/HTTPS URL to <code>latest.csv</code>, or the full local server path to the file. The web server must be able to read it.</div></div><div class="formfield"><input type="text" name="WXSIM_LATEST_CSV" value="<?=h($settings['WXSIM_LATEST_CSV'])?>" placeholder="https://example.com/path/latest.csv or /full/server/path/latest.csv" required></div></div>
<div class="formrow"><div class="formlabel"><strong>WXSIM_OUTPUT_UNITS — WXSIM CSV units</strong><div class="help">Select the units actually produced in your WXSIM <code>latest.csv</code>. This is independent of the chart display units below.</div></div><div class="formfield"><select name="WXSIM_OUTPUT_UNITS" required><option value="">Please select the WXSIM CSV units…</option><option value="metric"<?=$settings['WXSIM_OUTPUT_UNITS']==='metric'?' selected':''?>>Metric — °C, mm, km/h</option><option value="imperial"<?=$settings['WXSIM_OUTPUT_UNITS']==='imperial'?' selected':''?>>Imperial — °F, inches, mph</option></select></div></div>
<div class="formrow"><div class="formlabel"><strong>FORECAST_START_DAY — Forecast start day</strong><div class="help">Weekday on which Stage 1 freezes its recurring seven-day forecast.</div></div><div class="formfield"><select name="FORECAST_START_DAY" required><option value="">Please select the forecast start day…</option><?php foreach($days as $d):?><option value="<?=h($d)?>"<?=$settings['FORECAST_START_DAY']===$d?' selected':''?>><?=h($d)?></option><?php endforeach;?></select></div></div>
<div class="formrow"><div class="formlabel"><strong>WU_STATION_ID — Weather Underground Location ID</strong><div class="help">Enter the station's <strong>Location ID</strong> shown on Weather Underground after login. Example format: <code>IXXXXXXX1</code>. <strong>Do not enter the station Key</strong> shown alongside the station Name and Location ID.</div></div><div class="formfield"><input type="text" name="WU_STATION_ID" value="<?=h($settings['WU_STATION_ID'])?>" placeholder="Enter Weather Underground Location ID" required></div></div>
<div class="formrow"><div class="formlabel"><strong>WU_API_KEY — Weather Underground API Key</strong><div class="help">Enter the <strong>API Key</strong> from your Weather Underground account/settings. This is <strong>not</strong> the station Key shown with the station Name and Location ID. The API key is never displayed after saving; leave this field blank later to keep the saved key.</div></div><div class="formfield"><input type="password" name="WU_API_KEY" value="" placeholder="<?=$wuKey?'API key already configured — leave blank to keep it':'Enter Weather Underground API Key'?>" autocomplete="new-password"<?=$wuKey?'':' required'?>></div></div>
<div class="formrow"><div class="formlabel"><strong>DISPLAY_UNITS — Chart display units</strong><div class="help">Choose how values are displayed on the website. UK = °C, mm, mph; Metric = °C, mm, km/h; Imperial = °F, inches, mph.</div></div><div class="formfield"><select name="DISPLAY_UNITS" required><option value="">Please select the chart display units…</option><option value="uk"<?=$settings['DISPLAY_UNITS']==='uk'?' selected':''?>>UK — °C, mm, mph</option><option value="metric"<?=$settings['DISPLAY_UNITS']==='metric'?' selected':''?>>Metric — °C, mm, km/h</option><option value="imperial"<?=$settings['DISPLAY_UNITS']==='imperial'?' selected':''?>>Imperial — °F, inches, mph</option></select></div></div>
</div><div class="buttons"><button type="submit">Save Settings</button></div></form></div>
<div class="panel"><h2>Server, files and configuration diagnostics</h2><table><?php row('PHP version',$phpOK,h(PHP_VERSION));row('Project directory',is_dir(BASE_DIR),'<code>'.h(BASE_DIR).'</code>');row('Data directory',$dataOK,'<code>'.h(DATA_DIR).'</code> — '.($dataOK?'writable':'NOT writable'));row('Weeks directory',$weeksOK,'<code>'.h(WEEKS_DIR).'</code> — '.($weeksOK?'writable':'NOT writable'));row('Logs directory',$logsOK,'<code>'.h(LOG_DIR).'</code> — '.($logsOK?'writable':'NOT writable'));row(
    'Settings file',
    $settingsOK && is_file(SETTINGS_FILE),
    '<code>'.h(SETTINGS_FILE).'</code> — '.
    (is_file(SETTINGS_FILE)
        ? ('EXISTS; '.number_format((int)@filesize(SETTINGS_FILE)).' bytes; '.(is_readable(SETTINGS_FILE)?'readable':'NOT readable').'; '.(is_writable(SETTINGS_FILE)?'writable':'NOT writable'))
        : 'DOES NOT EXIST — press Save Settings')
);row('Station name',trim((string)$settings['SITE_NAME'])!=='',h($settings['SITE_NAME']));row('Station location',trim((string)$settings['SITE_LOCATION'])!=='',h($settings['SITE_LOCATION']));row('Station timezone',$tzOK,h($settings['STATION_TIMEZONE']).($tzOK?' — valid':' — invalid'));row('WXSIM source configured',$wxCfg,$wxCfg?'<code>'.h($settings['WXSIM_LATEST_CSV']).'</code>':'Not configured');row('WXSIM source units',$wxuOK,h($settings['WXSIM_OUTPUT_UNITS']));row('Forecast start day',$dayOK,h($settings['FORECAST_START_DAY']));row('WU station ID',$wuId,$wuId?h($settings['WU_STATION_ID']):'Not configured');row('WU API key',$wuKey,$wuKey?'Configured — hidden for security':'Not configured');row('Display units',$dispOK,h($settings['DISPLAY_UNITS']));?></table></div>
<div class="panel"><h2>Administrator password</h2>
<p class="small">Use this section to change the password that protects Setup &amp; Tests. The password is not stored in the scripts.</p>
<?php if($passwordMessage!==''):?><div class="msg ok"><?=h($passwordMessage)?></div><?php endif;?>
<?php if($passwordError!==''):?><div class="msg bad"><?=h($passwordError)?></div><?php endif;?>
<form method="post" autocomplete="off"><?=wxfa_admin_token_field()?><input type="hidden" name="action" value="change_admin_password"><div class="formgrid">
<div class="formrow"><div class="formlabel"><strong>Current administrator password</strong></div><div class="formfield"><input type="password" name="current_admin_password" autocomplete="current-password" required></div></div>
<div class="formrow"><div class="formlabel"><strong>New administrator password</strong><div class="help">Minimum 10 characters.</div></div><div class="formfield"><input type="password" name="new_admin_password" minlength="10" autocomplete="new-password" required></div></div>
<div class="formrow"><div class="formlabel"><strong>Confirm new password</strong></div><div class="formfield"><input type="password" name="confirm_admin_password" minlength="10" autocomplete="new-password" required></div></div>
</div><div class="buttons"><button type="submit">Change Administrator Password</button></div></form></div>
<div class="panel"><h2>Run now</h2>
<p class="small"><strong>Run / Initialise Now</strong> creates the first frozen WXSIM forecast immediately, even if today is not the configured recurring start day.</p>
<form method="post" action="initialise.php"><?=wxfa_admin_token_field()?><div class="buttons"><button type="submit">Run / Initialise Now</button></div></form>
<p class="small"><strong>Update Now</strong> runs the existing completed-day Weather Underground actuals updater. Stage 2 continues on its normal CRON schedule.</p>
<form method="post" action="update_actuals.php"><?=wxfa_admin_token_field()?><div class="buttons"><button class="test" type="submit">Update Now</button></div></form>
</div>
<div class="panel"><h2>Connection tests</h2><form method="post"><?=wxfa_admin_token_field()?><div class="buttons"><button class="test" name="test_wxsim" value="1">Test WXSIM Connection</button><button class="test" name="test_wu" value="1">Test Weather Underground</button><button class="secondary" name="test_all" value="1">Run Both Tests</button></div></form><p class="small">Tests are read-only. They do not create archives or alter weather records.</p></div>
<?php if($runWx):?><div class="panel"><h2>Detailed WXSIM latest.csv test</h2><table><?php if(!$wxCfg)row('Configured source',false,'No source configured');else{row('Configured source',true,'<code>'.h($settings['WXSIM_LATEST_CSV']).'</code>');if($wx){row('Source type',true,h($wx['type']));row('Retrieval method',$wx['method']!=='',h($wx['method']));if($wx['status']!==null)row('HTTP status',$wx['status']>=200&&$wx['status']<300,h($wx['status']));row('Source accessible',$wx['ok'],$wx['ok']?'Source read successfully':h($wx['error']));row('File received',$wx['bytes']>0,number_format($wx['bytes']).' bytes');if($wx['ctype']!=='')row('Content type',true,h($wx['ctype']));if($csv){row('CSV recognised',$csv['ok'],$csv['ok']?'Header and data rows parsed successfully':h($csv['error']));row('CSV columns',$csv['cols']>1,h($csv['cols']));row('CSV data rows',$csv['rows']>0,h($csv['rows']));row('Date column',$csv['date']!=='',$csv['date']!==''?h($csv['date']):'No Date / Forecast Date column or Year + Month + Day columns detected');row('Time column',$csv['time']!=='',$csv['time']!==''?h($csv['time']):'No Time / Forecast Time / Hour column detected');}}}?></table><?php if($csv&&$csv['headers']):?><h3>CSV column headings returned</h3><pre><?=h(implode(' | ',$csv['headers']))?></pre><?php endif;?><?php if($csv&&$csv['times']):?><h3>First detected forecast times</h3><pre><?=h(implode(', ',$csv['times']))?></pre><?php endif;?><?php if($csv&&$csv['first']):?><h3>First CSV data row</h3><pre><?=h(implode(' | ',$csv['first']))?></pre><?php endif;?></div><?php endif;?>
<?php if($runWu):?><div class="panel"><h2>Detailed Weather Underground test</h2><table><?php row('Station ID',$wuId,$wuId?h($settings['WU_STATION_ID']):'Not configured');row('API key',$wuKey,$wuKey?'Configured — hidden for security':'Not configured');if($wu){row('Request endpoint',true,'<code>https://api.weather.com/v2/pws/observations/current</code> — API key hidden');row('Retrieval method',$wu['method']!=='',h($wu['method']));row('API response',$wu['received'],$wu['received']?'Response received':h($wu['error']));row('HTTP status',$wu['status']===200,$wu['status']>0?h($wu['status']):'Unknown');row('JSON response',$wu['json'],$wu['json']?'Valid JSON received':'Invalid JSON');row('Current observation',$wu['ok'],$wu['ok']?'Observation retrieved successfully':h($wu['error']));}?></table>
<?php if($wu&&$wu['ok']&&is_array($wu['data'])):$obs=$wu['data']['observations'][0]??[];$m=is_array($obs['metric']??null)?$obs['metric']:[];$items=[['Station ID',$obs['stationID']??null,''],['Observation time UTC',$obs['obsTimeUtc']??null,''],['Observation time local',$obs['obsTimeLocal']??null,''],['Neighbourhood',$obs['neighborhood']??null,''],['Temperature',$m['temp']??null,' °C'],['Dew point',$m['dewpt']??null,' °C'],['Humidity',$obs['humidity']??null,' %'],['Wind speed',$m['windSpeed']??null,' km/h'],['Wind gust',$m['windGust']??null,' km/h'],['Wind direction',$obs['winddir']??null,' °'],['Sea-level pressure',$m['pressure']??null,' hPa'],['Precipitation rate',$m['precipRate']??null,' mm/h'],['Daily precipitation total',$m['precipTotal']??null,' mm'],['Solar radiation',$obs['solarRadiation']??null,' W/m²'],['UV index',$obs['uv']??null,''],['Elevation',$m['elev']??null,' m']];?><h3>Current observation fields</h3><table><?php foreach($items as[$n,$v,$u]){ $p=$v!==null&&$v!=='';row($n,$p,$p?h((string)$v.$u):'Not supplied by this observation'); }?></table><h3>Top-level fields returned</h3><pre><?=h(implode(', ',array_keys($obs)))?></pre><h3>Metric fields returned</h3><pre><?=h(implode(', ',array_keys($m)))?></pre><?php elseif($wu&&$wu['excerpt']!==''):?><h3>API error response</h3><pre><?=h($wu['excerpt'])?></pre><?php endif;?></div><?php endif;?>
<div class="panel"><h2>Internal paths — automatic</h2><p class="small">These correspond to the internal path constants in the old config and require no user input.</p><table><tr><th>BASE_DIR</th><td colspan="2"><code><?=h(BASE_DIR)?></code></td></tr><tr><th>DATA_DIR</th><td colspan="2"><code><?=h(DATA_DIR)?></code></td></tr><tr><th>WEEKS_DIR</th><td colspan="2"><code><?=h(WEEKS_DIR)?></code></td></tr><tr><th>LOG_DIR</th><td colspan="2"><code><?=h(LOG_DIR)?></code></td></tr><tr><th>SETTINGS_FILE</th><td colspan="2"><code><?=h(SETTINGS_FILE)?></code></td></tr></table></div>
<footer>WXSIM Forecast vs Weather Underground Actuals — developed by MillbankPWS, Munlochy, Scotland</footer></div></body></html>

<?php
exit;
}

