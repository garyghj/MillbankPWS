<?php
/*
 WXSIM Forecast vs Weather Underground Actuals
 Stage 2 - Intra-day forecast/actual comparison

 Developed by MillbankPWS
 Munlochy, Scotland

 IMPORTANT
 - This is a Stage 2 diagnostic file only.
 - It does NOT modify chart.php, chart_detailed.php, capture_forecast.php,
   update_actuals.php, data/weeks, or any Stage 1 archive.
 - Normal page view is still read-only.
 - Browser views are read-only unless ?save=1 is used. CLI/CRON runs save automatically.
 - Stage 2 records are written only under data/stage2/.
 - Existing records are never overwritten.
 - Do NOT add this file to CRON yet.
*/

require_once __DIR__ . '/config.php';

date_default_timezone_set(STATION_TIMEZONE);

/* ---------- small helpers ---------- */

function s2_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function s2_utc_display(DateTimeImmutable $dt): string
{
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') . ' UTC';
}

function s2_number($value): ?float
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return null;
    }
    return (float)$value;
}

function s2_fetch_text(string $source): string
{
    if (preg_match('~^https?://~i', $source)) {
        $context = stream_context_create([
            'http' => [
                'timeout' => 25,
                'user_agent' => 'MillbankPWS-Stage2A/1.0'
            ]
        ]);

        $text = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($source);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_USERAGENT => 'MillbankPWS-Stage2/1.0',
            ]);
            $curlText = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($curlText !== false && trim((string)$curlText) !== '' && ($httpCode === 0 || ($httpCode >= 200 && $httpCode < 400))) {
                $text = $curlText;
            }
        }
        if ($text === false) {
            $text = @file_get_contents($source, false, $context);
        }
    } else {
        $text = @file_get_contents($source);
    }

    if ($text === false || trim($text) === '') {
        throw new RuntimeException('Unable to read WXSIM_LATEST_CSV.');
    }

    return $text;
}

function s2_read_csv(string $text): array
{
    $fh = fopen('php://temp', 'r+');
    if ($fh === false) {
        throw new RuntimeException('Unable to create temporary CSV reader.');
    }

    fwrite($fh, $text);
    rewind($fh);

    $raw = [];
    while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        $raw[] = $row;
    }
    fclose($fh);

    if (count($raw) < 3) {
        throw new RuntimeException('latest.csv does not contain enough rows.');
    }

    $headers = array_map(
        static fn($v) => trim((string)$v),
        $raw[0]
    );

    $units = array_map(
        static fn($v) => trim((string)$v),
        $raw[1]
    );

    $rows = [];

    for ($i = 2; $i < count($raw); $i++) {
        $nonBlank = false;
        foreach ($raw[$i] as $v) {
            if (trim((string)$v) !== '') {
                $nonBlank = true;
                break;
            }
        }
        if (!$nonBlank) {
            continue;
        }

        $assoc = [];
        foreach ($headers as $n => $header) {
            $assoc[$header] = $raw[$i][$n] ?? null;
        }
        $rows[] = $assoc;
    }

    return [$headers, $units, $rows];
}

function s2_forecast_datetime(array $row, DateTimeZone $tz): ?DateTimeImmutable
{
    $year  = (int)($row['Year'] ?? 0);
    $month = (int)($row['Month'] ?? 0);
    $day   = (int)($row['Day'] ?? 0);
    $time  = trim((string)($row['Time'] ?? ''));

    if (!$year || !$month || !$day || $time === '') {
        return null;
    }

    /*
     WXSIM latest.csv normally stores Time as decimal local hours:
       6    = 06:00
       6.5  = 06:30
       14.5 = 14:30

     Convert decimal hours to minutes after local midnight.  Round rather
     than truncate so normal floating-point representations do not turn
     6.5 into 06:29.
    */
    if (is_numeric($time)) {
        $decimalHours = (float)$time;

        if ($decimalHours < 0 || $decimalHours >= 24) {
            return null;
        }

        $minutesAfterMidnight = (int)round($decimalHours * 60.0);
        $hour   = intdiv($minutesAfterMidnight, 60);
        $minute = $minutesAfterMidnight % 60;

        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            return null;
        }

        $text = sprintf(
            '%04d-%02d-%02d %02d:%02d',
            $year,
            $month,
            $day,
            $hour,
            $minute
        );

        $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $text, $tz);
        return $dt !== false ? $dt : null;
    }

    /*
     Retain support for a conventional HH:MM or HH:MM:SS value in case
     another WXSIM installation exports Time in that form.
    */
    $text = sprintf('%04d-%02d-%02d %s', $year, $month, $day, $time);

    foreach (['!Y-m-d H:i:s', '!Y-m-d H:i'] as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $text, $tz);
        if ($dt !== false) {
            return $dt;
        }
    }

    return null;
}

function s2_unit_for_header(array $headers, array $units, string $wanted): string
{
    foreach ($headers as $i => $header) {
        if ($header === $wanted) {
            return trim((string)($units[$i] ?? ''));
        }
    }
    return '';
}

function s2_wind_kmh(?float $value, string $unit): ?float
{
    if ($value === null) {
        return null;
    }

    $u = strtolower(trim($unit));

    if (strpos($u, 'knot') !== false) {
        return $value * 1.852;
    }

    if (strpos($u, 'mph') !== false) {
        return $value * 1.609344;
    }

    /* WXSIM metric output may already be km/h. */
    return $value;
}

function s2_wet_bulb_stull(?float $tempC, ?float $rh): ?float
{
    if ($tempC === null || $rh === null || $rh < 0 || $rh > 100) {
        return null;
    }

    /*
     Stull approximation.
     Used when the Weather Underground station does not provide a physical
     wet-bulb observation.
    */
    return
        $tempC * atan(0.151977 * sqrt($rh + 8.313659))
        + atan($tempC + $rh)
        - atan($rh - 1.676331)
        + 0.00391838 * pow($rh, 1.5) * atan(0.023101 * $rh)
        - 4.686035;
}

function s2_direction_error(?float $forecast, ?float $actual): ?float
{
    if ($forecast === null || $actual === null) {
        return null;
    }

    $d = fmod(($forecast - $actual + 540.0), 360.0) - 180.0;
    return $d;
}

function s2_wu_observations(DateTimeImmutable $localDate): array
{
    if (!defined('WU_STATION_ID') || trim((string)WU_STATION_ID) === '') {
        throw new RuntimeException('WU_STATION_ID is not configured.');
    }

    if (!defined('WU_API_KEY') || trim((string)WU_API_KEY) === '') {
        throw new RuntimeException('WU_API_KEY is not configured.');
    }

    $url =
        'https://api.weather.com/v2/pws/observations/current'
        . '?stationId=' . rawurlencode((string)WU_STATION_ID)
        . '&format=json'
        . '&units=m'
        . '&numericPrecision=decimal'
        . '&apiKey=' . rawurlencode((string)WU_API_KEY);

    /* Use the same proven HTTP method as update_actuals.php.
       cURL is preferred on hosted Linux because allow_url_fopen may be
       disabled or behave differently under CLI/CRON. */
    $body = false;
    $httpCode = 0;
    $httpError = '';

    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'MillbankPWS-WXSIM-WU-Stage2/1.0'
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $httpError = curl_error($ch);
        }
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'ignore_errors' => true,
                'header' => "User-Agent: MillbankPWS-WXSIM-WU-Stage2/1.0\r\n"
            ]
        ]);
        $body = @file_get_contents($url, false, $context);
        if (isset($http_response_header[0])
            && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $httpCode = (int)$m[1];
        }
    }

    if ($body === false || trim((string)$body) === '') {
        $detail = $httpError !== '' ? ' cURL: '.$httpError : '';
        throw new RuntimeException('Weather Underground current-observation request failed.' . $detail);
    }

    if ($httpCode !== 0 && ($httpCode < 200 || $httpCode >= 300)) {
        throw new RuntimeException('Weather Underground current-observation request returned HTTP ' . $httpCode . '.');
    }

    $json = json_decode($body, true);

    if (!is_array($json) || !isset($json['observations']) || !is_array($json['observations'])) {
        throw new RuntimeException('WU current response did not contain an observations array.');
    }

    return $json['observations'];
}


/* ---------- Stage 2B immutable save helpers ---------- */

function s2_iso(DateTimeImmutable $dt): string
{
    return $dt->format('Y-m-d\TH:i:sP');
}

function s2_stage2_dir(): string
{
    return __DIR__ . '/data/stage2';
}

function s2_rain_baseline_path(): string
{
    return __DIR__ . '/data/stage2_rainfall_baseline.json';
}

function s2_load_rain_baseline(): ?array
{
    $path = s2_rain_baseline_path();

    if (!is_file($path)) {
        return null;
    }

    $text = @file_get_contents($path);
    if ($text === false || trim($text) === '') {
        return null;
    }

    $json = json_decode($text, true);
    return is_array($json) ? $json : null;
}

function s2_write_rain_baseline(array $baseline): void
{
    $path = s2_rain_baseline_path();
    $dir = dirname($path);

    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create rainfall baseline directory.');
    }

    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($json === false || @file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
        @unlink($tmp);
        throw new RuntimeException('Could not write rainfall baseline temporary file.');
    }

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Could not replace rainfall baseline file.');
    }
}

function s2_save_record(array $record, DateTimeImmutable $forecastTime): array
{
    $dir = s2_stage2_dir();

    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create Stage 2 data directory: data/stage2');
        }
    }

    if (!is_writable($dir)) {
        throw new RuntimeException('Stage 2 data directory is not writable: data/stage2');
    }

    /*
     One immutable comparison per forecast-valid instant.
     UTC is used in the filename so DST clock changes cannot create
     ambiguous duplicate filenames.
    */
    $utc = $forecastTime->setTimezone(new DateTimeZone('UTC'));
    $filename = $utc->format('Ymd\THis\Z') . '.json';
    $target = $dir . '/' . $filename;
    $lockPath = $dir . '/.stage2.lock';

    $lock = @fopen($lockPath, 'c');
    if ($lock === false) {
        throw new RuntimeException('Unable to open Stage 2 save lock.');
    }

    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Unable to obtain Stage 2 save lock.');
        }

        if (is_file($target)) {
            return [
                'status' => 'exists',
                'filename' => $filename,
                'path' => $target
            ];
        }

        $json = json_encode(
            $record,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            throw new RuntimeException('Unable to encode Stage 2 record as JSON.');
        }

        $tmp = $dir . '/.' . $filename . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write temporary Stage 2 record.');
        }

        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to finalize Stage 2 record.');
        }

        return [
            'status' => 'saved',
            'filename' => $filename,
            'path' => $target
        ];
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

/* ---------- diagnostic test ---------- */

$error = null;
$result = null;
$saveResult = null;
$isCli = (PHP_SAPI === 'cli') || empty($_SERVER['REQUEST_METHOD']);
$saveRequested = $isCli || (isset($_GET['save']) && (string)$_GET['save'] === '1');

try {
    $tz  = new DateTimeZone(STATION_TIMEZONE);
    $now = new DateTimeImmutable('now', $tz);

    if (!defined('WXSIM_LATEST_CSV') || trim((string)WXSIM_LATEST_CSV) === '') {
        throw new RuntimeException('WXSIM_LATEST_CSV is not configured.');
    }

    $csvText = s2_fetch_text((string)WXSIM_LATEST_CSV);
    $csvSha256 = hash('sha256', $csvText);
    [$headers, $units, $csvRows] = s2_read_csv($csvText);

    $required = [
        'Year', 'Month', 'Day', 'Time',
        'Temperature', 'Rel.Hum.', 'Dew Pt.', 'Wet Bulb',
        'S.L.P.', 'Wind Spd.', 'Wind Dir.', '10 min Gust', 'Tot.Prcp', 'Solar Rad', 'UV Index'
    ];

    foreach ($required as $column) {
        if (!in_array($column, $headers, true)) {
            throw new RuntimeException('Required WXSIM CSV column is missing: ' . $column);
        }
    }

    /*
     Stage 2 comparison rule:
     use the newest WU observation currently available, then select the
     most recent WXSIM forecast-valid row at or before that observation.
     There is no artificial waiting period and no future forecast-valid
     time is used for an earlier observation.
    */
    $forecastCandidates = [];

    foreach ($csvRows as $row) {
        $dt = s2_forecast_datetime($row, $tz);
        if ($dt === null) continue;
        $forecastCandidates[] = ['dt'=>$dt, 'row'=>$row];
    }

    if (!$forecastCandidates) {
        throw new RuntimeException('No usable WXSIM forecast Date/Time rows were found.');
    }

    usort($forecastCandidates, static fn($a,$b) =>
        $a['dt']->getTimestamp() <=> $b['dt']->getTimestamp()
    );

    $firstCsvForecastTime = $forecastCandidates[0]['dt'];

    $wuRows = s2_wu_observations($now);
    $nearestWu = null;

    foreach ($wuRows as $obs) {
        if (isset($obs['stationID']) && (string)$obs['stationID'] !== (string)WU_STATION_ID) continue;
        if (empty($obs['obsTimeLocal'])) continue;

        try {
            $obsTime=(new DateTimeImmutable((string)$obs['obsTimeLocal']))->setTimezone($tz);
        } catch (Throwable $e) {
            continue;
        }

        if ($nearestWu===null || $obsTime>$nearestWu['time']) {
            $nearestWu=['time'=>$obsTime,'obs'=>$obs];
        }
    }

    if ($nearestWu===null) {
        throw new RuntimeException('No usable Weather Underground observation was found for today.');
    }

    $selectedForecast=null;
    for ($i=count($forecastCandidates)-1; $i>=0; $i--) {
        if ($forecastCandidates[$i]['dt'] <= $nearestWu['time']) {
            $selectedForecast=$forecastCandidates[$i];
            break;
        }
    }

    if ($selectedForecast===null) {
        throw new RuntimeException(
            'The latest WU observation is earlier than the first usable WXSIM forecast time.'
        );
    }

    $forecastTime=$selectedForecast['dt'];
    $forecastRow=$selectedForecast['row'];
    $nearestSeconds=$nearestWu['time']->getTimestamp()-$forecastTime->getTimestamp();

    /*
     Rainfall forecast interval:
     WXSIM Tot.Prcp is cumulative, so the 30-minute forecast amount is the
     difference between the selected row and the exact row 30 minutes earlier.
    */
    $forecastRain30 = null;
    $previousForecastTime = $forecastTime->modify('-30 minutes');
    $previousForecastRow = null;

    foreach ($forecastCandidates as $candidate) {
        if ($candidate['dt']->getTimestamp() === $previousForecastTime->getTimestamp()) {
            $previousForecastRow = $candidate['row'];
            break;
        }
    }

    if ($previousForecastRow !== null) {
        $rainNow = s2_number($forecastRow['Tot.Prcp'] ?? null);
        $rainPrev = s2_number($previousForecastRow['Tot.Prcp'] ?? null);

        if ($rainNow !== null && $rainPrev !== null) {
            $delta = $rainNow - $rainPrev;
            if ($delta >= -0.001) {
                $forecastRain30 = max(0.0, $delta);
            }
        }
    }

    $wu = $nearestWu['obs'];
    $metric = isset($wu['metric']) && is_array($wu['metric'])
        ? $wu['metric']
        : [];

    $windUnit = s2_unit_for_header($headers, $units, 'Wind Spd.');

    $forecastTemp = s2_number($forecastRow['Temperature'] ?? null);
    $actualTemp   = s2_number($metric['temp'] ?? null);

    $forecastRh = s2_number($forecastRow['Rel.Hum.'] ?? null);
    $actualRh   = s2_number($wu['humidity'] ?? null);

    $pressureActual = s2_number($metric['pressure'] ?? null);

    /*
     WU current precipTotal is cumulative daily rainfall.
     A separate baseline file is used so rainfall does not depend on older
     immutable Stage 2 JSON records containing rainfall fields.

     For a valid 30-minute comparison, the baseline must belong to the exact
     previous WXSIM forecast-valid time. If a collection is missed, the stale
     baseline is not compared with a single 30-minute WXSIM interval; instead
     the next successful save resets the baseline and normal calculation then
     resumes on the following half-hour.
    */
    $wuPrecipTotalMm = s2_number($metric['precipTotal'] ?? null);
    $actualRain30 = null;
    $rainIntervalMinutes = null;
    $rainBaselineStatus = 'No saved WU rainfall baseline is available yet.';
    $rainBaseline = s2_load_rain_baseline();

    if ($rainBaseline !== null && $wuPrecipTotalMm !== null) {
        $previousTotal = $rainBaseline['precip_total_mm'] ?? null;
        $previousObsUtc = $rainBaseline['observation_time_utc'] ?? null;
        $previousLocalDate = $rainBaseline['station_local_date'] ?? null;
        $previousForecastUtc = $rainBaseline['forecast_valid_time_utc'] ?? null;

        if (is_numeric($previousTotal)
            && is_string($previousObsUtc)
            && is_string($previousLocalDate)
            && is_string($previousForecastUtc)) {
            try {
                $previousObsDt = new DateTimeImmutable($previousObsUtc);
                $previousForecastDt = new DateTimeImmutable($previousForecastUtc);
                $currentObsUtc = $nearestWu['time']->setTimezone(new DateTimeZone('UTC'));
                $currentForecastUtc = $forecastTime->setTimezone(new DateTimeZone('UTC'));

                $rainIntervalMinutes =
                    ($currentObsUtc->getTimestamp() - $previousObsDt->getTimestamp()) / 60.0;

                $forecastIntervalMinutes =
                    ($currentForecastUtc->getTimestamp() - $previousForecastDt->getTimestamp()) / 60.0;

                $currentLocalDate = $nearestWu['time']->setTimezone($tz)->format('Y-m-d');

                if ($currentLocalDate !== $previousLocalDate) {
                    $rainBaselineStatus = 'Rainfall baseline is from the previous station-local day; this save will start a new daily baseline.';
                } elseif (abs($forecastIntervalMinutes - 30.0) > 0.01) {
                    $rainBaselineStatus = 'Previous rainfall baseline is not from the immediately preceding 30-minute forecast period; this save will reset the baseline.';
                } elseif ($rainIntervalMinutes <= 0) {
                    $rainBaselineStatus = 'WU observation time is not later than the saved rainfall baseline.';
                } else {
                    $rainDelta = $wuPrecipTotalMm - (float)$previousTotal;

                    if ($rainDelta >= -0.001) {
                        $actualRain30 = max(0.0, $rainDelta);
                        $rainBaselineStatus = '30-minute WU rainfall calculated from cumulative precipTotal over ' . number_format($rainIntervalMinutes, 1) . ' minutes of actual observations.';
                    } else {
                        $rainBaselineStatus = 'WU cumulative rainfall decreased; this save will reset the rainfall baseline.';
                    }
                }
            } catch (Throwable $e) {
                $rainBaselineStatus = 'Saved rainfall baseline could not be read; this save will reset the baseline.';
            }
        } else {
            $rainBaselineStatus = 'Saved rainfall baseline is incomplete; this save will replace it.';
        }
    } elseif ($wuPrecipTotalMm === null) {
        $rainBaselineStatus = 'WU current observation did not contain precipTotal; rainfall cannot be calculated or baselined.';
    }

    $metrics = [
        'temperature' => [
            'label' => 'Temperature',
            'unit'  => '°C',
            'f'     => $forecastTemp,
            'a'     => $actualTemp
        ],
        'humidity' => [
            'label' => 'Relative humidity',
            'unit'  => '%',
            'f'     => $forecastRh,
            'a'     => $actualRh
        ],
        'dewpoint' => [
            'label' => 'Dew point',
            'unit'  => '°C',
            'f'     => s2_number($forecastRow['Dew Pt.'] ?? null),
            'a'     => s2_number($metric['dewpt'] ?? null)
        ],
        'wetbulb' => [
            'label' => 'Wet bulb',
            'unit'  => '°C',
            'f'     => s2_number($forecastRow['Wet Bulb'] ?? null),
            'a'     => s2_wet_bulb_stull($actualTemp, $actualRh)
        ],
        'pressure' => [
            'label' => 'Sea-level pressure',
            'unit'  => 'hPa',
            'f'     => s2_number($forecastRow['S.L.P.'] ?? null),
            'a'     => $pressureActual
        ],
        'rain' => [
            'label' => 'Rainfall (30 min)',
            'unit'  => 'mm',
            'f'     => $forecastRain30,
            'a'     => $actualRain30
        ],
        'wind' => [
            'label' => 'Wind speed',
            'unit'  => 'km/h',
            'f'     => s2_wind_kmh(
                s2_number($forecastRow['Wind Spd.'] ?? null),
                $windUnit
            ),
            'a'     => s2_number($metric['windSpeed'] ?? null)
        ],
        'gust' => [
            'label' => 'Wind gust',
            'unit'  => 'km/h',
            'f'     => s2_wind_kmh(
                s2_number($forecastRow['10 min Gust'] ?? null),
                $windUnit
            ),
            'a'     => s2_number($metric['windGust'] ?? null)
        ],
        'direction' => [
            'label' => 'Wind direction',
            'unit'  => '°',
            'f'     => s2_number($forecastRow['Wind Dir.'] ?? null),
            'a'     => s2_number($wu['winddir'] ?? null)
        ],
        'solar' => [
            'label' => 'Solar radiation',
            'unit'  => 'W/m²',
            'f'     => s2_number($forecastRow['Solar Rad'] ?? null),
            'a'     => s2_number($wu['solarRadiation'] ?? null)
        ],
        'uv' => [
            'label' => 'UV index',
            'unit'  => '',
            'f'     => s2_number($forecastRow['UV Index'] ?? null),
            'a'     => s2_number($wu['uv'] ?? null)
        ]
    ];

    $directionUsable =
        $metrics['wind']['f'] !== null
        && $metrics['wind']['a'] !== null
        && $metrics['wind']['f'] >= 1.0
        && $metrics['wind']['a'] >= 1.0;

    foreach ($metrics as $key => &$values) {
        if ($values['f'] === null || $values['a'] === null) {
            $values['error'] = null;
        } elseif ($key === 'direction') {
            $values['error'] = $directionUsable
                ? s2_direction_error($values['f'], $values['a'])
                : null;
        } else {
            $values['error'] = $values['f'] - $values['a'];
        }
    }
    unset($values);

    $result = [
        'run_time'                => $now,
        'first_csv_forecast_time' => $firstCsvForecastTime,
        'forecast_time'           => $forecastTime,
        'wu_time'                 => $nearestWu['time'],
        'wu_epoch'                => isset($wu['epoch']) && is_numeric($wu['epoch']) ? (int)$wu['epoch'] : $nearestWu['time']->getTimestamp(),
        'difference_minutes'      => $nearestSeconds / 60.0,
        'difference_seconds'      => $nearestSeconds,
        'wind_source_unit'        => $windUnit,
        'display_units'           => defined('DISPLAY_UNITS') ? strtolower((string)DISPLAY_UNITS) : 'metric',
        'direction_usable'        => $directionUsable,
        'wxsim_sha256'            => $csvSha256,
        'rain_baseline_status'    => $rainBaselineStatus,
        'rain_interval_minutes'   => $rainIntervalMinutes,
        'wu_precip_total_mm'      => $wuPrecipTotalMm,
        'metrics'                 => $metrics
    ];

    if ($saveRequested) {
        $metricRecord = [];

        foreach ($metrics as $key => $values) {
            $metricRecord[$key] = [
                'unit' => $values['unit'],
                'forecast' => $values['f'],
                'actual' => $values['a'],
                'error_forecast_minus_actual' => $values['error']
            ];
        }

        $record = [
            'schema_version' => 1,
            'record_type' => 'wxsim_wu_stage2_comparison',
            'created_at_local' => s2_iso($now),
            'created_at_utc' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'station_timezone' => STATION_TIMEZONE,
            'wu_station_id' => WU_STATION_ID,
            'forecast' => [
                'valid_time_local' => s2_iso($forecastTime),
                'valid_time_utc' => $forecastTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'first_valid_time_in_csv_local' => s2_iso($firstCsvForecastTime),
                'source_sha256' => $csvSha256,
                'wind_source_unit' => $windUnit
            ],
            'actual' => [
                'observation_time_local' => s2_iso($nearestWu['time']),
                'observation_time_utc' => $nearestWu['time']->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'epoch' => $result['wu_epoch'],
                'precip_total_mm' => $wuPrecipTotalMm
            ],
            'match' => [
                'difference_seconds' => $nearestSeconds,
                'difference_minutes' => $nearestSeconds / 60.0,
                'rule' => 'newest WU current observation; most recent WXSIM forecast-valid time at or before observation'
            ],
            'wind_direction' => [
                'minimum_wind_kmh_for_scoring' => 1.0,
                'scored' => $directionUsable
            ],
            'wind_gust' => [
                'forecast_field' => 'WXSIM 10 min Gust',
                'actual_field' => 'Weather Underground current metric.windGust',
                'comparison_status' => 'provisional',
                'note' => 'WU observations/current supplies the station current gust value, not a guaranteed 10-minute maximum. It is retained separately and not represented as an interval-equivalent gust.'
            ],
            'rainfall' => [
                'interval_minutes' => 30,
                'forecast_method' => 'difference in WXSIM cumulative Tot.Prcp between selected forecast-valid row and exact row 30 minutes earlier',
                'actual_method' => 'difference in WU current metric.precipTotal from an independent baseline saved at the immediately preceding 30-minute forecast-valid time',
                'baseline_status' => $rainBaselineStatus
            ],
            'wet_bulb_actual' => [
                'method' => 'calculated from WU temperature and relative humidity using Stull approximation'
            ],
            'metrics' => $metricRecord
        ];

        $saveResult = s2_save_record($record, $forecastTime);

        /*
         Maintain the independent rainfall baseline.

         Normal case:
           - a genuinely NEW immutable Stage 2 record advances the baseline.

         Recovery case:
           - if SAVE is skipped because this forecast-valid record already
             exists, but the rainfall baseline is missing or belongs to an
             older forecast-valid time, synchronise the separate baseline to
             the current slot exactly once.
           - the immutable comparison record is NEVER altered.
           - repeated duplicate saves for the same slot do NOT keep moving
             the baseline, because once repaired its forecast-valid time
             equals the current slot.
        */
        $saveStatus = $saveResult['status'] ?? '';
        $currentForecastUtc = $forecastTime
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');

        $baselineForecastUtc = is_array($rainBaseline)
            ? ($rainBaseline['forecast_valid_time_utc'] ?? null)
            : null;

        $shouldAdvanceRainBaseline =
            ($saveStatus === 'saved')
            || (
                $saveStatus === 'exists'
                && ($baselineForecastUtc === null || $baselineForecastUtc !== $currentForecastUtc)
            );

        if ($shouldAdvanceRainBaseline && $wuPrecipTotalMm !== null) {
            s2_write_rain_baseline([
                'schema_version' => 1,
                'precip_total_mm' => $wuPrecipTotalMm,
                'observation_time_utc' => $nearestWu['time']
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:s\Z'),
                'observation_time_local' => s2_iso($nearestWu['time']),
                'station_local_date' => $nearestWu['time']->setTimezone($tz)->format('Y-m-d'),
                'forecast_valid_time_utc' => $currentForecastUtc
            ]);
        }
    }

} catch (Throwable $e) {
    $error = $e->getMessage();
}

function s2_display_convert(string $key, ?float $value, string $unit, bool $error=false): array
{
    if ($value === null) return [null, $unit];

    $mode = defined('DISPLAY_UNITS')
        ? strtolower(trim((string)DISPLAY_UNITS))
        : 'metric';

    /* Internal archive/comparison units stay metric. Display follows config.php. */
    if (in_array($key, ['wind','gust'], true) && $unit === 'km/h' && ($mode === 'uk' || $mode === 'imperial')) {
        return [$value / 1.609344, 'mph'];
    }

    if (in_array($key, ['temperature','dewpoint','wetbulb'], true)
        && $unit === '°C' && $mode === 'imperial') {
        return [$error ? $value * 9 / 5 : ($value * 9 / 5 + 32), '°F'];
    }

    if ($key === 'rain' && $unit === 'mm' && $mode === 'imperial') {
        return [$value / 25.4, 'in'];
    }

    return [$value, $unit];
}

function s2_display_value(?float $value, string $unit): string
{
    if ($value === null) return '—';
    return number_format($value, 1) . ($unit !== '' ? ' ' . $unit : '');
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Stage 2 WXSIM vs WU Collector</title>
<style>
body{
    margin:0;
    padding:24px;
    font-family:system-ui,-apple-system,"Segoe UI",Arial,sans-serif;
    background:#f4f6f8;
    color:#18212a;
}
main{
    max-width:1050px;
    margin:auto;
    background:#fff;
    padding:24px;
    border-radius:12px;
    box-shadow:0 2px 12px rgba(0,0,0,.08);
}
h1{margin-top:0}
.notice,.pass,.fail{
    padding:12px 14px;
    margin:14px 0;
    border-radius:8px;
}
.notice{background:#fff4d6}
.pass{background:#e7f6ea}
.fail{background:#fde8e8}
table{
    width:100%;
    border-collapse:collapse;
    margin-top:20px;
}
th,td{
    padding:9px 10px;
    border-bottom:1px solid #d9dee3;
    text-align:right;
}
th:first-child,td:first-child{text-align:left}
.meta{
    line-height:1.7;
}
code{
    background:#eef1f4;
    padding:2px 5px;
    border-radius:4px;
}
.small{
    color:#59636e;
    font-size:.92rem;
}
</style>
</head>
<body>
<main>

<h1>Stage 2 — WXSIM vs Weather Underground Collector</h1>

<div class="notice"><strong>Stage 2 collector.</strong> A normal page view is read-only. WXSIM rainfall is the change in cumulative <code>Tot.Prcp</code> across the selected 30-minute forecast interval. WU rainfall uses an independent cumulative <code>precipTotal</code> baseline. A new immutable comparison normally advances that baseline. If an immutable record already exists but the separate rainfall baseline is missing or stale, a deliberate save request can repair the baseline without changing the existing comparison record. The following half-hour can then calculate the next rainfall actual. A missed half-hour resets the baseline rather than creating a false 30-minute comparison. Wind gust remains provisional. Browser views are read-only; CLI/CRON executions save automatically.</div>

<?php if ($error !== null): ?>

<div class="fail">
<strong>TEST FAILED:</strong> <?= s2_h($error) ?>
</div>

<?php else: ?>

<div class="pass">
<strong>TEST PASS:</strong>
a WXSIM forecast row and a Weather Underground observation were matched.
<?= $saveRequested ? 'A Stage 2 save was requested.' : 'No data was saved.' ?>
</div>

<?php if ($saveRequested && $saveResult !== null): ?>
<div class="<?= $saveResult['status'] === 'saved' ? 'pass' : 'notice' ?>">
<?php if ($saveResult['status'] === 'saved'): ?>
<strong>SAVE PASS:</strong> permanent Stage 2 record created:
<code><?= s2_h($saveResult['filename']) ?></code>
<?php else: ?>
<strong>SAVE SKIPPED:</strong> a permanent record already exists for this WXSIM forecast-valid time:
<code><?= s2_h($saveResult['filename']) ?></code><br>
The existing record was left unchanged.
<?php endif; ?>
</div>
<?php endif; ?>

<div class="meta">
<strong>Page run:</strong>
<?= s2_h(s2_utc_display($result['run_time'])) ?><br>
<strong>First forecast-valid time in current latest.csv:</strong>
<?= s2_h(s2_utc_display($result['first_csv_forecast_time'])) ?><br>

<strong>Selected WXSIM forecast time:</strong>
<?= s2_h(s2_utc_display($result['forecast_time'])) ?><br>

<strong>Nearest WU observation:</strong>
<?= s2_h(s2_utc_display($result['wu_time'])) ?><br>

<strong>Timestamp difference:</strong>
<?= s2_h(number_format($result['difference_minutes'], 1)) ?> minutes<br>

<strong>WXSIM wind source unit:</strong>
<?= s2_h($result['wind_source_unit'] !== '' ? $result['wind_source_unit'] : 'not stated') ?><br>
<strong>WXSIM latest.csv SHA-256:</strong>
<code><?= s2_h($result['wxsim_sha256']) ?></code><br>
<strong>Configured display units:</strong>
<?= s2_h(strtoupper($result['display_units'])) ?><br>
<strong>Rainfall interval status:</strong>
<?= s2_h($result['rain_baseline_status']) ?>
</div>

<?php if (!$saveRequested): ?>
<p class="small">
This page is currently read-only.
For the first deliberate Stage 2B save test, use
<a href="?save=1"><strong>Save this comparison once</strong></a>.
</p>
<?php endif; ?>

<table>
<thead>
<tr>
<th>Metric</th>
<th>WXSIM forecast</th>
<th>WU actual</th>
<th>Error F − A</th>
</tr>
</thead>
<tbody>

<?php foreach ($result['metrics'] as $key => $values): ?>
<tr>
<td><?= s2_h($values['label']) ?><?php if ($key==='direction' && !$result['direction_usable']): ?><br><small>not scored: wind below 1 km/h</small><?php endif; ?></td>
<?php [$df,$du] = s2_display_convert($key,$values['f'],$values['unit']); ?>
<?php [$da,$dau] = s2_display_convert($key,$values['a'],$values['unit']); ?>
<td><?= s2_h(s2_display_value($df,$du)) ?></td>
<td><?= s2_h(s2_display_value($da,$dau)) ?></td>
<td>
<?php
if ($values['error'] === null) {
    echo '—';
} else {
    [$de,$deu] = s2_display_convert($key,$values['error'],$values['unit'],true);
    $prefix = $de > 0 ? '+' : '';
    echo s2_h($prefix . s2_display_value($de,$deu));
}
?>
</td>
</tr>
<?php endforeach; ?>

</tbody>
</table>

<p class="small">
Wet-bulb actual is calculated from WU temperature and relative humidity.
Wind-direction error is the shortest signed angular difference.
This first test deliberately compares instantaneous/near-instantaneous
metrics only; rainfall and gust interval handling will be added after
timestamp matching has been verified.
</p>

<?php endif; ?>

</main>
</body>
</html>
