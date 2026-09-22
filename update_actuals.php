<?php
/*
 WXSIM Forecast vs Weather Underground Actuals
 CSV PROJECT updater — PRODUCTION VERSION

 Developed by MillbankPWS
 Munlochy, Scotland

 Browser test (read only):
   update_actuals.php?date=YYYY-MM-DD&test=1

 Browser manual write:
   update_actuals.php?date=YYYY-MM-DD

 Automatic/cron:
   php -q update_actuals.php
   -> targets yesterday in STATION_TIMEZONE
*/

error_reporting(E_ALL);
ini_set('display_errors', '1');
require __DIR__ . '/config.php';

function out($s = '') { echo $s, PHP_EOL; }
function cli_mode() { return PHP_SAPI === 'cli'; }
function esc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function read_json($path) {
    if (!is_file($path) || !is_readable($path)) return null;
    $txt = @file_get_contents($path);
    if ($txt === false || trim($txt) === '') return null;
    $j = json_decode($txt, true);
    return is_array($j) ? $j : null;
}

function write_json_atomic($path, $data) {
    $tmp = $path . '.tmp.' . getmypid() . '.' . mt_rand(1000,9999);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) return array(false, 'json_encode failed');
    if (@file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false)
        return array(false, 'Could not write temporary file');
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return array(false, 'Could not replace archive atomically');
    }
    return array(true, '');
}

function http_json($url, &$code, &$error) {
    $code = 0; $error = '';
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'MillbankPWS-WXSIM-WU-CSV-Actuals/1.0'
        ));
        $body = curl_exec($ch);
        if ($body === false) $error = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(array('http'=>array(
            'timeout'=>30,
            'ignore_errors'=>true,
            'header'=>"User-Agent: MillbankPWS-WXSIM-WU-CSV-Actuals/1.0\r\n"
        )));
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) { $error='HTTP request failed'; return null; }
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m))
            $code=(int)$m[1];
    }
    if (!isset($body) || $body === false || $body === '') {
        if ($error==='') $error='Empty response';
        return null;
    }
    $j=json_decode($body,true);
    if (!is_array($j)) { $error='Response was not valid JSON'; return null; }
    return $j;
}

function actual_complete($actual) {
    foreach (array('hiC','loC','rainMm','windKmh','gustKmh','solarWm2') as $k) {
        if (!is_array($actual) || !array_key_exists($k,$actual) || $actual[$k] === null) return false;
    }
    return true;
}

function find_archive_for_date($dir, $date) {
    $files = glob($dir . '/*.json');
    if (!$files) return array(null,null,'No JSON archives found in data/weeks');
    sort($files,SORT_STRING);
    foreach ($files as $path) {
        $j=read_json($path);
        if (!is_array($j) || !isset($j['days']) || !is_array($j['days'])) continue;
        foreach ($j['days'] as $i=>$day) {
            if (isset($day['date']) && $day['date']===$date) return array($path,$i,'');
        }
    }
    return array(null,null,'No CSV forecast archive contains '.$date);
}

if (!defined('STATION_TIMEZONE') || !defined('WU_STATION_ID') || !defined('WU_API_KEY'))
    die("Configuration error: STATION_TIMEZONE, WU_STATION_ID and WU_API_KEY are required.\n");

try { $tz=new DateTimeZone(STATION_TIMEZONE); }
catch (Exception $e) { die("Configuration error: invalid STATION_TIMEZONE.\n"); }

/*
 Production completed-day safeguard.

 Automatic/cron operation is deliberately restricted to station-local
 yesterday, and will not query/write before 06:00 station local time.
 This prevents an overnight partial WU daily record being permanently
 accepted simply because all six fields are already non-null.

 Browser ?date=...&test=1 remains read-only for diagnostics.
 Browser manual writes are also restricted to dates before station-local
 today; today's still-developing observations can never be written.
*/
$stationNow = new DateTime('now', $tz);
$stationToday = $stationNow->format('Y-m-d');
$stationHour = (int)$stationNow->format('G');

$test = (!cli_mode() && isset($_GET['test']) && $_GET['test']=='1');

if (!cli_mode() && isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/',$_GET['date'])) {
    $date=$_GET['date'];
} elseif (cli_mode() && isset($argv[1]) && preg_match('/^\d{4}-\d{2}-\d{2}$/',$argv[1])) {
    $date=$argv[1];
} else {
    $d=new DateTime('now',$tz);
    $d->modify('-1 day');
    $date=$d->format('Y-m-d');
}

/* Never allow a write of station-local today or a future date. */
if (!$test && $date >= $stationToday) {
    $msg='SAFETY STOP: actuals may only be written for a completed date before station-local today. Archive unchanged.';
    if (cli_mode()) out($msg);
    else {
        echo '<!doctype html><html><head><meta charset="utf-8"><title>CSV WU Actuals Updater</title></head><body>';
        echo '<h1>CSV Weather Underground Actuals Updater</h1><p><strong>'.esc($msg).'</strong></p></body></html>';
    }
    exit;
}

/*
 Automatic/cron scheduling guard.

 The hosting server may run cron in UTC or another timezone, so the cron
 entry itself should run every 15 minutes throughout the day.  This PHP
 guard decides whether the current invocation is inside the permitted
 station-local retrieval window.

 Automatic WU actuals retrieval is permitted only from 06:00 through
 06:59 STATION_TIMEZONE.  Browser/manual diagnostic operation is not
 affected by this scheduling guard.

 A completed archive row is still detected below before any WU API
 request, so repeated 15-minute calls within the hour are safe.
*/
if (cli_mode() && $stationHour !== 6) {
    exit;
}

$weeksDir=__DIR__.'/data/weeks';
list($archivePath,$dayIndex,$findErr)=find_archive_for_date($weeksDir,$date);

if (!cli_mode()) {
    echo '<!doctype html><html><head><meta charset="utf-8"><title>CSV WU Actuals Updater</title>
    <style>body{font-family:Arial,sans-serif;max-width:950px;margin:35px auto;padding:0 20px;line-height:1.45}
    table{border-collapse:collapse;width:100%;margin:18px 0}th,td{border:1px solid #ccc;padding:8px 10px;text-align:left}
    th{background:#eee}.ok{color:#087a2f;font-weight:bold}.warn{color:#9a6700;font-weight:bold}.bad{color:#b00020;font-weight:bold}
    code{background:#f3f3f3;padding:2px 4px}</style></head><body>';
    echo '<h1>CSV Weather Underground Actuals Updater</h1>';
    echo '<p><strong>Target date:</strong> '.esc($date).'<br><strong>Mode:</strong> '.($test?'TEST — read only':'WRITE').'<br>';
    echo '<strong>Station:</strong> '.esc(WU_STATION_ID).'<br><strong>Timezone:</strong> '.esc(STATION_TIMEZONE).'</p>';
} else {
    out('CSV Weather Underground Actuals Updater');
    out('Target date: '.$date);
    out('Mode: automatic/cron');
}

if ($archivePath===null) {
    $msg='SKIP: '.$findErr;
    if (cli_mode()) out($msg); else echo '<p class="warn">'.esc($msg).'</p></body></html>';
    exit;
}

$archive=read_json($archivePath);
$actualExisting=(isset($archive['days'][$dayIndex]['actual']) && is_array($archive['days'][$dayIndex]['actual']))
    ? $archive['days'][$dayIndex]['actual'] : array();

if (cli_mode()) {
    out('Archive: '.basename($archivePath));
    out('Matched row: '.($dayIndex+1));
} else {
    echo '<p><strong>Archive:</strong> <code>'.esc(basename($archivePath)).'</code><br><strong>Matched row:</strong> '.esc($dayIndex+1).'</p>';
}

if (actual_complete($actualExisting)) {
    $msg='SKIP: all six actual values are already complete. No Weather Underground API request made.';
    if (cli_mode()) out($msg); else echo '<p class="ok">'.esc($msg).'</p></body></html>';
    exit;
}

$apiDate=str_replace('-','',$date);
$url='https://api.weather.com/v2/pws/history/daily'
    .'?stationId='.rawurlencode(WU_STATION_ID)
    .'&format=json&units=m&numericPrecision=decimal'
    .'&date='.rawurlencode($apiDate)
    .'&apiKey='.rawurlencode(WU_API_KEY);

$code=0; $error='';
$data=http_json($url,$code,$error);

if ($data===null || $code!==200) {
    $msg='WAIT/FAIL: WU did not return a usable daily record. HTTP '.$code.($error!==''?' — '.$error:'');
    if (cli_mode()) out($msg); else echo '<p class="warn">'.esc($msg).'</p></body></html>';
    exit;
}

$obs=array();
if (isset($data['observations']) && is_array($data['observations']) && count($data['observations'])>0)
    $obs=$data['observations'][0];
elseif (isset($data['metric']) && is_array($data['metric']))
    $obs=$data;

/*
 Confirm the WU record itself belongs to the requested station-local date.
 This is additional protection against accepting the wrong daily record.
*/
$wuObsDate = null;
if (isset($obs['obsTimeLocal']) && is_string($obs['obsTimeLocal'])) {
    try {
        $wuDt = new DateTime($obs['obsTimeLocal']);
        $wuDt->setTimezone($tz);
        $wuObsDate = $wuDt->format('Y-m-d');
    } catch (Exception $e) {
        $wuObsDate = null;
    }
}

if ($wuObsDate === null || $wuObsDate !== $date) {
    $shown = ($wuObsDate === null) ? 'unavailable' : $wuObsDate;
    $msg='WAIT/FAIL: WU daily record date is '.$shown.'; expected '.$date.'. Archive unchanged.';
    if (cli_mode()) out($msg); else echo '<p class="warn">'.esc($msg).'</p></body></html>';
    exit;
}

$metric=(isset($obs['metric']) && is_array($obs['metric'])) ? $obs['metric'] : array();

$new=array(
    'hiC'      => array_key_exists('tempHigh',$metric) ? $metric['tempHigh'] : null,
    'loC'      => array_key_exists('tempLow',$metric) ? $metric['tempLow'] : null,
    'rainMm'   => array_key_exists('precipTotal',$metric) ? $metric['precipTotal'] : null,
    'windKmh'  => array_key_exists('windspeedHigh',$metric) ? $metric['windspeedHigh'] : null,
    'gustKmh'  => array_key_exists('windgustHigh',$metric) ? $metric['windgustHigh'] : null,
    'solarWm2' => array_key_exists('solarRadiationHigh',$obs) ? $obs['solarRadiationHigh'] : null
);

$labels=array(
    'hiC'=>'Maximum temperature','loC'=>'Minimum temperature','rainMm'=>'Rainfall total',
    'windKmh'=>'Maximum sustained wind','gustKmh'=>'Maximum wind gust','solarWm2'=>'Maximum solar radiation'
);
$units=array('hiC'=>'°C','loC'=>'°C','rainMm'=>'mm','windKmh'=>'km/h','gustKmh'=>'km/h','solarWm2'=>'W/m²');

$missing=array();
foreach ($new as $k=>$v) if ($v===null) $missing[]=$labels[$k];

if (!cli_mode()) {
    echo '<p><strong>HTTP status:</strong> '.esc($code).'<br>';
    echo '<strong>WU returned station:</strong> '.esc(isset($obs['stationID'])?$obs['stationID']:'—').'<br>';
    echo '<strong>Observation time local:</strong> '.esc(isset($obs['obsTimeLocal'])?$obs['obsTimeLocal']:'—').'<br>';
    echo '<strong>Observation time UTC:</strong> '.esc(isset($obs['obsTimeUtc'])?$obs['obsTimeUtc']:'—').'</p>';
    echo '<table><thead><tr><th>Actual field</th><th>WU value</th><th>Archive key</th></tr></thead><tbody>';
    foreach ($new as $k=>$v) {
        $display=($v===null)?'MISSING':number_format((float)$v,2,'.','').' '.$units[$k];
        echo '<tr><td>'.esc($labels[$k]).'</td><td>'.esc($display).'</td><td><code>'.esc($k).'</code></td></tr>';
    }
    echo '</tbody></table>';
} else {
    foreach ($new as $k=>$v) {
        $display=($v===null)?'MISSING':number_format((float)$v,2,'.','').' '.$units[$k];
        out($labels[$k].': '.$display);
    }
}

if ($missing) {
    $msg='WAIT: completed six-variable daily record is not yet available. Missing: '.implode(', ',$missing).'. Archive unchanged.';
    if (cli_mode()) out($msg); else echo '<p class="warn">'.esc($msg).'</p></body></html>';
    exit;
}

if ($test) {
    echo '<p class="ok">TEST PASS — all six values are available. No archive changes were made.</p></body></html>';
    exit;
}

$beforeForecast=$archive['days'][$dayIndex]['forecast'] ?? null;
$archive['days'][$dayIndex]['actual']=$new;
$archive['actualLastUpdate']=gmdate('Y-m-d H:i');

list($ok,$err)=write_json_atomic($archivePath,$archive);
if (!$ok) {
    $msg='ERROR: archive write failed — '.$err;
    if (cli_mode()) out($msg); else echo '<p class="bad">'.esc($msg).'</p></body></html>';
    exit(1);
}

$check=read_json($archivePath);
$afterForecast=$check['days'][$dayIndex]['forecast'] ?? null;
if ($beforeForecast !== $afterForecast) {
    $msg='CRITICAL: forecast verification failed after write.';
    if (cli_mode()) out($msg); else echo '<p class="bad">'.esc($msg).'</p></body></html>';
    exit(1);
}

$msg='SAVED: six WU actual values added for '.$date.'. Frozen forecast unchanged.';
if (cli_mode()) out($msg); else echo '<p class="ok">'.esc($msg).'</p></body></html>';
?>
