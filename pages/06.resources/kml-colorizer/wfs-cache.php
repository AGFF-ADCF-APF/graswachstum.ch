<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$lat = isset($_GET['lat']) ? (float)$_GET['lat'] : 46.8;
$lng = isset($_GET['lng']) ? (float)$_GET['lng'] : 8.2;
$radiusKm = isset($_GET['r']) ? (int)$_GET['r'] : 5;

$radiusKm = max(1, min(50, $radiusKm));

if (!is_finite($lat) || !is_finite($lng)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid lat/lng']);
    exit;
}

$latKey = round($lat, 3);
$lngKey = round($lng, 3);
$cacheKey = sprintf('nf_%s_%s_%dkm', $latKey, $lngKey, $radiusKm);

$cacheDir = __DIR__ . '/cache';
$cacheFile = $cacheDir . '/' . md5($cacheKey) . '.json';
$cacheTtlSeconds = 24 * 3600;

if (!is_dir($cacheDir) && !mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
    http_response_code(500);
    echo json_encode(['error' => 'cache directory could not be created']);
    exit;
}

$hasFreshCache = is_file($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtlSeconds);
if ($hasFreshCache) {
    header('X-Cache-Status: HIT');
    readfile($cacheFile);
    exit;
}

$dLat = $radiusKm / 111.32;
$cosLat = max(0.1, cos(deg2rad($lat)));
$dLng = $radiusKm / (111.32 * $cosLat);

$minLat = $lat - $dLat;
$maxLat = $lat + $dLat;
$minLng = $lng - $dLng;
$maxLng = $lng + $dLng;

$bbox = implode(',', [
    number_format($minLng, 6, '.', ''),
    number_format($minLat, 6, '.', ''),
    number_format($maxLng, 6, '.', ''),
    number_format($maxLat, 6, '.', ''),
    'EPSG:4326'
]);

$wfsBaseUrl = 'https://wfs.geodienste.ch/lwb_nutzungsflaechen_v3_0_0/deu';
$outputFormat = rawurlencode('application/json; subtype=geojson');
$wfsUrl = $wfsBaseUrl
    . '?SERVICE=WFS'
    . '&VERSION=2.0.0'
    . '&REQUEST=GetFeature'
    . '&TYPENAMES=ms:nutzungsflaechen'
    . '&BBOX=' . $bbox
    . '&COUNT=10000'
    . '&OUTPUTFORMAT=' . $outputFormat
    . '&srsName=EPSG:4326';

$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'timeout' => 25,
        'ignore_errors' => true,
        'header' => "User-Agent: MowTracker-WFS-Cache/1.0\r\n"
    ]
]);

$responseBody = @file_get_contents($wfsUrl, false, $context);
if ($responseBody === false || $responseBody === '') {
    http_response_code(502);
    echo json_encode(['error' => 'wfs request failed']);
    exit;
}

$isJson = json_decode($responseBody, true) !== null;
if (!$isJson) {
    http_response_code(502);
    echo json_encode(['error' => 'wfs response is not valid json']);
    exit;
}

file_put_contents($cacheFile, $responseBody, LOCK_EX);
header('X-Cache-Status: MISS');
echo $responseBody;
