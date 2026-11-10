<?php
/**
 * Backend API Service: Google Analytics 4 Data API (v1beta)
 * Festival Iguassu Inova 2026 - Rastreio da Página Yazo & Site
 *
 * Requisitos em .env.local (nunca versionar):
 * - GA4_PROPERTY_ID=123456789
 * - GA4_CREDENTIALS_FILE=ga4-credentials.json (JSON da Service Account do Google Cloud)
 */

@set_time_limit(60);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

date_default_timezone_set('America/Sao_Paulo');

function loadGa4Env($dir) {
    foreach (['.env.local', '.env'] as $file) {
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        if (!file_exists($path)) continue;
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) continue;
            list($k, $v) = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v, " \t\n\r\0\x0B\"'");
            if (!isset($_ENV[$k])) {
                $_ENV[$k] = $value = $v;
                putenv("$k=$value");
            }
        }
    }
}

loadGa4Env(__DIR__);

$propertyId = preg_replace('/[^0-9]/', '', (string)(getenv('GA4_PROPERTY_ID') ?: ($_ENV['GA4_PROPERTY_ID'] ?? '')));
$credFileName = trim((string)(getenv('GA4_CREDENTIALS_FILE') ?: ($_ENV['GA4_CREDENTIALS_FILE'] ?? 'ga4-credentials.json')));
$credPath = (strpos($credFileName, ':') !== false || strpos($credFileName, '/') === 0)
    ? $credFileName
    : __DIR__ . DIRECTORY_SEPARATOR . $credFileName;

$cacheFile = __DIR__ . DIRECTORY_SEPARATOR . 'ga4_cache.json';
$cacheTTL = 300; // 5 minutos
$forceRefresh = isset($_GET['refresh']) && ($_GET['refresh'] === '1' || $_GET['refresh'] === 'true');

if (!$forceRefresh && file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTTL)) {
    $cached = json_decode(file_get_contents($cacheFile), true);
    if ($cached && ($cached['status'] ?? '') === 'ok') {
        $cached['source'] = 'cache';
        $cached['cached_at'] = date('c', filemtime($cacheFile));
        echo json_encode($cached, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if ($propertyId === '' || !file_exists($credPath)) {
    echo json_encode([
        'status' => 'not_configured',
        'message' => 'GA4 ainda não configurado. Defina GA4_PROPERTY_ID em .env.local e salve o JSON da Service Account em ' . basename($credPath),
        'has_property_id' => $propertyId !== '',
        'has_credentials_file' => file_exists($credPath)
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$creds = json_decode(file_get_contents($credPath), true);
if (!$creds || empty($creds['client_email']) || empty($creds['private_key'])) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Arquivo JSON de credenciais do Google inválido (ausência de client_email ou private_key).'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function getGoogleAccessToken($creds) {
    $now = time();
    $header = base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claim  = base64UrlEncode(json_encode([
        'iss'   => $creds['client_email'],
        'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'exp'   => $now + 3600,
        'iat'   => $now
    ]));

    $signatureInput = "{$header}.{$claim}";
    $signature = '';
    if (!openssl_sign($signatureInput, $signature, $creds['private_key'], OPENSSL_ALGO_SHA256)) {
        return ['ok' => false, 'error' => 'Falha ao assinar JWT com a chave privada RSA.'];
    }
    $jwt = $signatureInput . '.' . base64UrlEncode($signature);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt
        ])
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $json = json_decode((string)$res, true);
    if ($err || $code !== 200 || empty($json['access_token'])) {
        return ['ok' => false, 'error' => $err ?: ($json['error_description'] ?? $res)];
    }
    return ['ok' => true, 'token' => $json['access_token']];
}

$tokenRes = getGoogleAccessToken($creds);
if (!$tokenRes['ok']) {
    http_response_code(502);
    echo json_encode([
        'status' => 'error',
        'message' => 'Erro na autenticação OAuth2 com o Google: ' . $tokenRes['error']
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$accessToken = $tokenRes['token'];

// Executa batchRunReports (Global + Filtrado por Yazo + Últimas 48h + Por Dia)
$yazoFilter = [
    'filter' => [
        'fieldName' => 'hostName',
        'stringFilter' => [
            'matchType' => 'CONTAINS',
            'value' => 'yazo',
            'caseSensitive' => false
        ]
    ]
];

$batchBody = [
    'requests' => [
        // 0: Total da Propriedade (toda a campanha)
        [
            'dateRanges' => [['startDate' => '2026-08-25', 'endDate' => 'today']],
            'metrics' => [
                ['name' => 'totalUsers'],
                ['name' => 'activeUsers'],
                ['name' => 'sessions'],
                ['name' => 'screenPageViews']
            ]
        ],
        // 1: Apenas página da Yazo (filtrando hostName contendo 'yazo')
        [
            'dateRanges' => [['startDate' => '2026-08-25', 'endDate' => 'today']],
            'metrics' => [
                ['name' => 'totalUsers'],
                ['name' => 'activeUsers'],
                ['name' => 'sessions'],
                ['name' => 'screenPageViews']
            ],
            'dimensionFilter' => $yazoFilter
        ],
        // 2: Últimas 48h (yesterday + today) na página da Yazo
        [
            'dateRanges' => [['startDate' => 'yesterday', 'endDate' => 'today']],
            'metrics' => [
                ['name' => 'totalUsers'],
                ['name' => 'activeUsers'],
                ['name' => 'sessions'],
                ['name' => 'screenPageViews']
            ],
            'dimensionFilter' => $yazoFilter
        ],
        // 3: Últimas 48h Global (fallback caso a propriedade seja exclusiva da Yazo ou outro hostname)
        [
            'dateRanges' => [['startDate' => 'yesterday', 'endDate' => 'today']],
            'metrics' => [
                ['name' => 'totalUsers'],
                ['name' => 'activeUsers'],
                ['name' => 'sessions'],
                ['name' => 'screenPageViews']
            ]
        ],
        // 4: Série diária na página da Yazo (últimos 30 dias)
        [
            'dateRanges' => [['startDate' => '30daysAgo', 'endDate' => 'today']],
            'dimensions' => [['name' => 'date']],
            'metrics' => [
                ['name' => 'totalUsers'],
                ['name' => 'sessions'],
                ['name' => 'screenPageViews']
            ],
            'dimensionFilter' => $yazoFilter,
            'orderBys' => [['dimension' => ['dimensionName' => 'date']]]
        ]
    ]
];

$apiUrl = "https://analyticsdata.googleapis.com/v1beta/properties/{$propertyId}:batchRunReports";
$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ],
    CURLOPT_POSTFIELDS => json_encode($batchBody)
]);

$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($curlErr || $httpCode !== 200) {
    http_response_code($httpCode ?: 502);
    echo json_encode([
        'status' => 'error',
        'message' => 'Erro ao consultar Google Analytics Data API (HTTP ' . $httpCode . ')',
        'details' => json_decode((string)$resp, true) ?: $curlErr
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = json_decode($resp, true);
$reports = $raw['reports'] ?? [];

function extractTotals($report) {
    $row = $report['rows'][0]['metricValues'] ?? null;
    if (!$row) {
        return ['totalUsers' => 0, 'activeUsers' => 0, 'sessions' => 0, 'pageViews' => 0];
    }
    return [
        'totalUsers'  => (int)($row[0]['value'] ?? 0),
        'activeUsers' => (int)($row[1]['value'] ?? 0),
        'sessions'    => (int)($row[2]['value'] ?? 0),
        'pageViews'   => (int)($row[3]['value'] ?? 0)
    ];
}

$globalTotals = extractTotals($reports[0] ?? []);
$yazoTotals   = extractTotals($reports[1] ?? []);
$yazo48h      = extractTotals($reports[2] ?? []);
$global48h    = extractTotals($reports[3] ?? []);

// Se não houver tráfego filtrado por hostname "yazo", utiliza o global como propriedade dedicada
$usesYazoHostFilter = ($yazoTotals['totalUsers'] > 0);
$effectiveTotals = $usesYazoHostFilter ? $yazoTotals : $globalTotals;
$effective48h    = $usesYazoHostFilter ? $yazo48h : $global48h;

$dailyYazo = [];
foreach (($reports[4]['rows'] ?? []) as $r) {
    $rawDate = $r['dimensionValues'][0]['value'] ?? '';
    if (strlen($rawDate) === 8) {
        $iso = substr($rawDate, 0, 4) . '-' . substr($rawDate, 4, 2) . '-' . substr($rawDate, 6, 2);
        $dailyYazo[$iso] = [
            'users' => (int)($r['metricValues'][0]['value'] ?? 0),
            'sessions' => (int)($r['metricValues'][1]['value'] ?? 0),
            'pageViews' => (int)($r['metricValues'][2]['value'] ?? 0)
        ];
    }
}

$output = [
    'status' => 'ok',
    'source' => 'live_ga4_api',
    'sync_time' => date('Y-m-d H:i:s'),
    'property_id' => $propertyId,
    'filter_mode' => $usesYazoHostFilter ? 'hostname_yazo' : 'property_global',
    'yazo_page' => $effectiveTotals,
    'yazo_48h' => $effective48h,
    'site_global' => $globalTotals,
    'daily_yazo' => $dailyYazo
];

file_put_contents($cacheFile, json_encode($output, JSON_UNESCAPED_UNICODE));

$ga4StaticJs = $output;
$ga4StaticJs['source'] = 'snapshot_js';
unset($ga4StaticJs['property_id']);
file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . 'ga4_operacao_data.js', 'window.GA4_OPERACAO_DATA = ' . json_encode($ga4StaticJs, JSON_UNESCAPED_UNICODE) . ';');

echo json_encode($output, JSON_UNESCAPED_UNICODE);
