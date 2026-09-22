<?php
/* Dedicated scheduled WXSIM forecast capture. */
declare(strict_types=1);
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

/*
 Production 7-day WXSIM latest.csv forecast capture.

 Rules:
 - Forecast length is fixed at 7 calendar days.
 - Capture is allowed only on FORECAST_START_DAY.
 - The first CSV forecast date must be today's station-local date.
 - The seven-day forecast is permanently frozen once written.
 - Existing archives are never overwritten.
 - ?test=1 performs all checks and calculations but does not write.
 - Internal archive units: deg C, mm, km/h, W/m2.
*/

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/config.php';

if (!defined('STATION_TIMEZONE') || trim((string)STATION_TIMEZONE) === '') {
    die('ERROR: STATION_TIMEZONE is not defined in config.php');
}
if (!@date_default_timezone_set(STATION_TIMEZONE)) {
    die('ERROR: Invalid STATION_TIMEZONE in config.php');
}
if (!defined('FORECAST_START_DAY') || trim((string)FORECAST_START_DAY) === '') {
    die('ERROR: FORECAST_START_DAY is not defined in config.php');
}
if (!defined('WXSIM_LATEST_CSV') || trim((string)WXSIM_LATEST_CSV) === '') {
    die('ERROR: WXSIM_LATEST_CSV is not defined in config.php');
}

define('CSV_FORECAST_DAYS', 7);

$isCli = (PHP_SAPI === 'cli');
$testMode = false;
if ($isCli) {
    global $argv;
    $testMode = isset($argv[1]) && $argv[1] === '--test';
} else {
    $testMode = isset($_GET['test']) && (string)$_GET['test'] === '1';
    header('Content-Type: text/html; charset=UTF-8');
}

function out_line($text, $class = '') {
    global $isCli;
    if ($isCli) {
        echo $text . PHP_EOL;
    } else {
        $safe = htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
        echo $class !== ''
            ? '<p class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '">' . $safe . '</p>'
            : '<p>' . $safe . '</p>';
    }
}
function clean_row($row) {
    foreach ($row as $k => $v) $row[$k] = trim((string)$v);
    return $row;
}
function numeric_or_null($v) {
    $v = trim((string)$v);
    return ($v !== '' && is_numeric($v)) ? (float)$v : null;
}
function day_label($date) {
    $ts = strtotime($date);
    return $ts === false ? $date : date('D j M', $ts);
}
function save_json_atomic($path, $data) {
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) return false;
    $tmp = $path . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}
function normalise_weekday($value) {
    $value = strtolower(trim((string)$value));
    $days = array(
        'sun'=>'Sunday','sunday'=>'Sunday',
        'mon'=>'Monday','monday'=>'Monday',
        'tue'=>'Tuesday','tues'=>'Tuesday','tuesday'=>'Tuesday',
        'wed'=>'Wednesday','wednesday'=>'Wednesday',
        'thu'=>'Thursday','thur'=>'Thursday','thurs'=>'Thursday','thursday'=>'Thursday',
        'fri'=>'Friday','friday'=>'Friday',
        'sat'=>'Saturday','saturday'=>'Saturday'
    );
    return isset($days[$value]) ? $days[$value] : null;
}
function read_csv_source($source, &$sourceDescription, &$error) {
    $error = null;
    $sourceDescription = $source;
    if (preg_match('~^https?://~i', $source)) {
        $separator = (strpos($source, '?') === false) ? '?' : '&';
        $requestSource = $source . $separator . '_=' . time();
        $context = stream_context_create(array(
            'http'=>array(
                'timeout'=>30,
                'user_agent'=>'MillbankPWS-WXSIM-Forecast-Capture/1.0',
                'header'=>"Cache-Control: no-cache\r\nPragma: no-cache\r\n"
            ),
            'https'=>array('timeout'=>30)
        ));
        $data = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($requestSource);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_USERAGENT => 'MillbankPWS-WXSIM-Forecast-Capture/1.0',
                CURLOPT_HTTPHEADER => array('Cache-Control: no-cache', 'Pragma: no-cache'),
            ));
            $curlData = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($curlData !== false && trim((string)$curlData) !== '' && ($httpCode === 0 || ($httpCode >= 200 && $httpCode < 400))) {
                $data = $curlData;
            }
        }
        if ($data === false) {
            $data = @file_get_contents($requestSource, false, $context);
        }
        if ($data === false || trim((string)$data) === '') {
            $error = 'Could not download WXSIM latest.csv from ' . $source;
            return null;
        }
        $tmp = fopen('php://temp', 'w+b');
        if (!$tmp) {
            $error = 'Could not create temporary CSV stream.';
            return null;
        }
        fwrite($tmp, $data);
        rewind($tmp);
        return $tmp;
    }
    if (!is_file($source) || !is_readable($source)) {
        $error = 'WXSIM latest.csv was not found or is not readable: ' . $source;
        return null;
    }
    $fh = @fopen($source, 'rb');
    if (!$fh) {
        $error = 'Could not open WXSIM latest.csv: ' . $source;
        return null;
    }
    $real = realpath($source);
    if ($real !== false) $sourceDescription = $real;
    return $fh;
}

// Authoritative live WXSIM CSV source — also used by wxsim_cloud_cover.php.
$liveCsvSource = WXSIM_LATEST_CSV;
$sources = array($liveCsvSource);

$csvHandle = null;
$csvSource = null;
$sourceError = null;
foreach ($sources as $candidate) {
    $desc = null;
    $err = null;
    $fh = read_csv_source($candidate, $desc, $err);
    if ($fh !== null) {
        $csvHandle = $fh;
        $csvSource = $desc;
        break;
    }
    $sourceError = $err;
}

if (!$isCli) {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>WXSIM 7-Day Forecast Capture</title>';
    echo '<style>body{font-family:Segoe UI,Arial,sans-serif;margin:24px;color:#172033;background:#f4f7fb}.wrap{max-width:1200px;margin:auto}.card{background:#fff;border:1px solid #dfe5ec;border-radius:12px;padding:20px;margin-bottom:16px}table{width:100%;border-collapse:collapse}th,td{padding:8px;border-bottom:1px solid #e4e8ee;text-align:right}th:first-child,td:first-child{text-align:left}.good{color:#18794e;font-weight:700}.warn{color:#9a6700;font-weight:700}.bad{color:#b42318;font-weight:700}.note{color:#667085;font-size:13px;line-height:1.5}</style></head><body><div class="wrap"><div class="card"><h1>WXSIM latest.csv — Production 7-Day Forecast Capture</h1>';
}

out_line('Station timezone: ' . STATION_TIMEZONE);
out_line('Configured forecast start day: ' . FORECAST_START_DAY);
out_line('Mode: ' . ($testMode ? 'TEST — no archive will be written' : 'PRODUCTION'));

$configuredDay = normalise_weekday(FORECAST_START_DAY);
if ($configuredDay === null) {
    out_line('ERROR: FORECAST_START_DAY must be Sunday through Saturday.', 'bad');
    if (!$isCli) echo '</div></div></body></html>';
    exit(1);
}

$todayDate = date('Y-m-d');
$todayDay = date('l');
$stationHour = (int)date('G');
$stationMinute = (int)date('i');
out_line('Station-local date: ' . $todayDate . ' (' . $todayDay . ')');

/*
 Automatic/cron scheduling guard.

 The hosting server may run cron in UTC or another timezone, so the cron
 entry itself should run every 15 minutes throughout the day.  This PHP
 guard decides whether the current invocation is inside the permitted
 station-local forecast-capture window.

 Production CLI capture is permitted only on FORECAST_START_DAY and only
 from 07:00 through 07:29 STATION_TIMEZONE.  This gives two normal
 15-minute opportunities: 07:00 and 07:15.

 Browser production operation retains the configured-weekday restriction,
 and test mode remains read-only and may run at any time for diagnostics.
*/
if ($isCli && !$testMode) {
    if ($todayDay !== $configuredDay || $stationHour !== 7 || $stationMinute >= 30) {
        exit(0);
    }
} elseif (!$testMode && $todayDay !== $configuredDay) {
    out_line('SKIP: Today is not the configured forecast start day. No archive was created.', 'warn');
    if (!$isCli) echo '</div></div></body></html>';
    exit(0);
}

if ($testMode && $todayDay !== $configuredDay) {
    out_line('TEST MODE: weekday capture restriction bypassed for diagnostics only.', 'warn');
}

if ($csvHandle === null) {
    out_line('ERROR: ' . ($sourceError ?: 'No readable WXSIM latest.csv source was found.'), 'bad');
    if (!$isCli) echo '</div></div></body></html>';
    exit(1);
}
out_line('CSV source: ' . $csvSource);

$header = fgetcsv($csvHandle, 0, ',', '"', '\\');
$units = fgetcsv($csvHandle, 0, ',', '"', '\\');
if (!is_array($header) || !is_array($units)) {
    fclose($csvHandle);
    out_line('ERROR: CSV header or units row is missing.', 'bad');
    if (!$isCli) echo '</div></div></body></html>';
    exit(1);
}
$header = clean_row($header);
$units = clean_row($units);

$required = array('Year','Month','Day','Time','Temperature','Hi Temp','Low Temp','Wind Spd.','Tot.Prcp','Solar Rad','1 min Gust','10 min Gust','1 hr Gust','6 hr Gust');
$idx = array();
foreach ($required as $name) {
    $pos = array_search($name, $header, true);
    if ($pos === false) {
        fclose($csvHandle);
        out_line('ERROR: Required CSV column was not found: ' . $name, 'bad');
        if (!$isCli) echo '</div></div></body></html>';
        exit(1);
    }
    $idx[$name] = $pos;
}

$expectedUnits = array(
    'Temperature'=>'deg C','Hi Temp'=>'deg C','Low Temp'=>'deg C',
    'Tot.Prcp'=>'mm','Solar Rad'=>'W/m^2'
);
foreach ($expectedUnits as $name=>$expected) {
    $actualUnit = isset($units[$idx[$name]]) ? trim($units[$idx[$name]]) : '';
    if ($actualUnit !== $expected) {
        fclose($csvHandle);
        out_line('ERROR: Unexpected unit for ' . $name . ': ' . $actualUnit . '. Expected ' . $expected . '.', 'bad');
        if (!$isCli) echo '</div></div></body></html>';
        exit(1);
    }
}
$windUnit=trim($units[$idx['Wind Spd.']]);
$gustUnit=trim($units[$idx['10 min Gust']]);
function wind_to_kmh($v,$u) {
    if ($v===null) return null;
    $u=strtolower(trim($u));
    if (in_array($u,array('knots','knot','kt','kts'),true)) return $v*1.852;
    if (in_array($u,array('mi/hr','mph','miles/hr'),true)) return $v*1.609344;
    if (in_array($u,array('km/hr','km/h','kph'),true)) return $v;
    return null;
}
$valid=array('knots','knot','kt','kts','mi/hr','mph','miles/hr','km/hr','km/h','kph');
if (!in_array(strtolower($windUnit),$valid,true) || !in_array(strtolower($gustUnit),$valid,true)) {
    fclose($csvHandle);
    out_line('ERROR: Unsupported WXSIM wind/gust units: '.$windUnit.' / '.$gustUnit,'bad');
    if (!$isCli) echo '</div></div></body></html>';
    exit(1);
}
out_line('WXSIM wind units: Wind Spd. = '.$windUnit.'; 10 min Gust = '.$gustUnit.' (normalised internally to km/h)');

$records = array();
while (($row = fgetcsv($csvHandle, 0, ',', '"', '\\')) !== false) {
    $row = clean_row($row);
    $year = isset($row[$idx['Year']]) ? $row[$idx['Year']] : '';
    $month = isset($row[$idx['Month']]) ? $row[$idx['Month']] : '';
    $day = isset($row[$idx['Day']]) ? $row[$idx['Day']] : '';
    if (!ctype_digit($year) || !ctype_digit($month) || !ctype_digit($day)) continue;
    $date = sprintf('%04d-%02d-%02d',(int)$year,(int)$month,(int)$day);
    $records[] = array(
        'date'=>$date,
        'time'=>numeric_or_null(isset($row[$idx['Time']])?$row[$idx['Time']]:''),
        'temp'=>numeric_or_null(isset($row[$idx['Temperature']])?$row[$idx['Temperature']]:''),
        'hi'=>numeric_or_null(isset($row[$idx['Hi Temp']])?$row[$idx['Hi Temp']]:''),
        'lo'=>numeric_or_null(isset($row[$idx['Low Temp']])?$row[$idx['Low Temp']]:''),
        'windKnots'=>numeric_or_null(isset($row[$idx['Wind Spd.']])?$row[$idx['Wind Spd.']]:''),
        'precipCum'=>numeric_or_null(isset($row[$idx['Tot.Prcp']])?$row[$idx['Tot.Prcp']]:''),
        'solar'=>numeric_or_null(isset($row[$idx['Solar Rad']])?$row[$idx['Solar Rad']]:''),
        'gust1'=>numeric_or_null(isset($row[$idx['1 min Gust']])?$row[$idx['1 min Gust']]:''),
        'gust10'=>numeric_or_null(isset($row[$idx['10 min Gust']])?$row[$idx['10 min Gust']]:''),
        'gust1h'=>numeric_or_null(isset($row[$idx['1 hr Gust']])?$row[$idx['1 hr Gust']]:''),
        'gust6h'=>numeric_or_null(isset($row[$idx['6 hr Gust']])?$row[$idx['6 hr Gust']]:''),
    );
}
fclose($csvHandle);

if (count($records) === 0) {
    out_line('ERROR: No forecast records were found in latest.csv.', 'bad');
    if (!$isCli) echo '</div></div></body></html>';
    exit(1);
}

$csvFirstDate = $records[0]['date'];
if ($csvFirstDate !== $todayDate) {
    out_line('SKIP: latest.csv does not contain a forecast initialized today.', 'warn');
    out_line('First CSV forecast date: ' . $csvFirstDate);
    out_line('Expected date: ' . $todayDate);
    out_line('No archive was created.');
    if (!$isCli) echo '</div></div></body></html>';
    exit(0);
}

$grouped = array();
foreach ($records as $r) {
    if (!isset($grouped[$r['date']])) $grouped[$r['date']] = array();
    $grouped[$r['date']][] = $r;
}
ksort($grouped);

$dates = array();
for ($i=0; $i<CSV_FORECAST_DAYS; $i++) {
    $date = date('Y-m-d', strtotime($todayDate . ' +' . $i . ' day'));
    if (!isset($grouped[$date])) {
        out_line('ERROR: latest.csv does not contain all seven required forecast dates.', 'bad');
        out_line('Missing forecast date: ' . $date, 'bad');
        if (!$isCli) echo '</div></div></body></html>';
        exit(1);
    }
    $dates[] = $date;
}

$periodStart = $dates[0];
$periodEnd = $dates[CSV_FORECAST_DAYS-1];
$outDir = __DIR__ . '/data/weeks';
$outFile = $outDir . '/' . $periodStart . '.json';

if (is_file($outFile)) {
    out_line('SKIP: The 7-day forecast for this start date is already frozen.', 'good');
    out_line('Existing archive: ' . $outFile);
    out_line('The existing forecast was not changed.');
    if (!$isCli) echo '</div></div></body></html>';
    exit(0);
}

$days = array();
$previousDayFinalPrecip = null;
foreach ($dates as $n=>$date) {
    $rr = $grouped[$date];
    $hiVals=$loVals=$windVals=$solarVals=$gustVals=$precipVals=array();
    foreach ($rr as $r) {
        if ($r['hi']!==null) $hiVals[]=$r['hi'];
        if ($r['lo']!==null) $loVals[]=$r['lo'];
        if ($r['windKnots']!==null) $windVals[]=$r['windKnots'];
        if ($r['solar']!==null) $solarVals[]=$r['solar'];
        if ($r['gust10']!==null) $gustVals[]=$r['gust10'];
        if ($r['precipCum']!==null) $precipVals[]=$r['precipCum'];
    }
    $firstPrecip = count($precipVals) ? $precipVals[0] : null;
    $finalPrecip = count($precipVals) ? $precipVals[count($precipVals)-1] : null;
    if ($finalPrecip===null) {
        $rainMm=null;
    } elseif ($n===0) {
        $rainMm=$firstPrecip===null ? null : max(0.0,$finalPrecip-$firstPrecip);
    } elseif ($previousDayFinalPrecip!==null) {
        $rainMm=max(0.0,$finalPrecip-$previousDayFinalPrecip);
    } else {
        $rainMm=null;
    }
    $previousDayFinalPrecip=$finalPrecip;
    $days[] = array(
        'label'=>day_label($date),
        'date'=>$date,
        'forecast'=>array(
            'hiC'=>count($hiVals)?max($hiVals):null,
            'loC'=>count($loVals)?min($loVals):null,
            'rainMm'=>$rainMm,
            'windKmh'=>count($windVals)?wind_to_kmh(max($windVals),$windUnit):null,
            'gustKmh'=>count($gustVals)?wind_to_kmh(max($gustVals),$gustUnit):null,
            'solarWm2'=>count($solarVals)?max($solarVals):null
        ),
        'actual'=>array('hiC'=>null,'loC'=>null,'rainMm'=>null,'windKmh'=>null,'gustKmh'=>null,'solarWm2'=>null),
        'csvDiagnostic'=>array(
            'records'=>count($rr),
            'firstTime'=>$rr[0]['time'],
            'lastTime'=>$rr[count($rr)-1]['time'],
            'firstCumulativeRainMm'=>$firstPrecip,
            'finalCumulativeRainMm'=>$finalPrecip
        )
    );
}

$firstTime=$records[0]['time'];
$forecastInit=$records[0]['date'];
if ($firstTime!==null) {
    $hour=(int)floor($firstTime);
    $minute=(int)round(($firstTime-floor($firstTime))*60);
    if ($minute>=60) { $hour++; $minute=0; }
    $forecastInit .= ' ' . sprintf('%02d:%02d',$hour,$minute);
}

$archive = array(
    'periodStart'=>$periodStart,
    'periodEnd'=>$periodEnd,
    'startDay'=>$configuredDay,
    'forecastDays'=>CSV_FORECAST_DAYS,
    'forecastSource'=>'WXSIM latest.csv',
    'forecastSourceLocation'=>$csvSource,
    'forecastMethod'=>array(
        'high'=>'maximum Hi Temp',
        'low'=>'minimum Low Temp',
        'rain'=>'daily increment from cumulative Tot.Prcp',
        'wind'=>'maximum Wind Spd.',
        'gust'=>'maximum 10 min Gust',
        'solar'=>'maximum Solar Rad'
    ),
    'forecastInit'=>$forecastInit,
    'forecastCapturedLocal'=>date('Y-m-d H:i:s'),
    'forecastCapturedUTC'=>gmdate('Y-m-d H:i:s'),
    'actualLastUpdate'=>null,
    'actualLastUpdateUTC'=>null,
    'days'=>$days
);

if (!$isCli) {
    echo '</div><div class="card"><h2>7-Day Forecast</h2><table><thead><tr><th>Date</th><th>Rows</th><th>High °C</th><th>Low °C</th><th>Rain mm</th><th>Max wind kt</th><th>Max gust 10-min kt</th><th>Solar W/m²</th></tr></thead><tbody>';
    foreach ($days as $day) {
        $f=$day['forecast']; $d=$day['csvDiagnostic'];
        echo '<tr><td>'.htmlspecialchars($day['label'],ENT_QUOTES,'UTF-8').'</td><td>'.(int)$d['records'].'</td><td>'.($f['hiC']===null?'—':number_format($f['hiC'],1)).'</td><td>'.($f['loC']===null?'—':number_format($f['loC'],1)).'</td><td>'.($f['rainMm']===null?'—':number_format($f['rainMm'],2)).'</td><td>'.($f['windKmh']===null?'—':number_format($f['windKmh']/1.852,1)).'</td><td>'.($f['gustKmh']===null?'—':number_format($f['gustKmh']/1.852,1)).'</td><td>'.($f['solarWm2']===null?'—':number_format($f['solarWm2'],1)).'</td></tr>';
    }
    echo '</tbody></table></div><div class="card">';
}

out_line('Forecast initialization: ' . $forecastInit);
out_line('Forecast period: ' . $periodStart . ' to ' . $periodEnd);

if ($testMode) {
    out_line('TEST PASS: The production capture checks and 7-day calculations completed successfully.', 'good');
    out_line('No archive was written because test mode is active.');
    out_line('Production archive would be: ' . $outFile);
} else {
    if (!save_json_atomic($outFile,$archive)) {
        out_line('ERROR: Could not write the frozen forecast archive: ' . $outFile, 'bad');
        if (!$isCli) echo '</div></div></body></html>';
        exit(1);
    }
    out_line('FORECAST CAPTURED AND FROZEN', 'good');
    out_line('Archive: ' . $outFile);
    out_line('This forecast is now protected from overwrite.');
}

if (!$isCli) {
    echo '<p class="note">Day 1 may be a partial calendar day. Days 2–7 are the following six calendar dates. Production capture is permitted only on the configured start weekday, and latest.csv must begin on today\'s station-local date. Test mode may run on any weekday and never writes an archive. An existing frozen archive is never overwritten.</p></div></div></body></html>';
}
exit(0);
