<?php
/**
 * Backend API Service: Meta Marketing API v21.0
 * Festival Iguassu Inova 2026
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 1. CARREGAR VARIÁVEIS DE AMBIENTE (.env.local ou .env)
function loadEnv($dir) {
    $envFiles = ['.env.local', '.env'];
    foreach ($envFiles as $file) {
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        if (file_exists($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line) || strpos($line, '#') === 0) continue;
                list($name, $value) = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value, " \t\n\r\0\x0B\"'");
                if (!isset($_ENV[$name])) {
                    $_ENV[$name] = $value;
                    putenv("$name=$value");
                }
            }
            return true;
        }
    }
    return false;
}

loadEnv(__DIR__);

$accessToken = getenv('META_ACCESS_TOKEN') ?: ($_ENV['META_ACCESS_TOKEN'] ?? '');
$adAccountId = getenv('META_AD_ACCOUNT_ID') ?: ($_ENV['META_AD_ACCOUNT_ID'] ?? 'act_140031283');
$apiVersion  = getenv('META_API_VERSION')  ?: ($_ENV['META_API_VERSION']  ?? 'v21.0');

if (empty($accessToken)) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'META_ACCESS_TOKEN não configurado no arquivo .env.local ou .env'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// 2. SISTEMA DE CACHE (15 minutos para evitar Rate Limit da Meta)
$cacheFile = __DIR__ . DIRECTORY_SEPARATOR . 'meta_cache.json';
$cacheTTL  = 900; // 15 minutos em segundos
$forceRefresh = isset($_GET['refresh']) && ($_GET['refresh'] === '1' || $_GET['refresh'] === 'true');

if (!$forceRefresh && file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTTL)) {
    $cachedData = file_get_contents($cacheFile);
    $decoded = json_decode($cachedData, true);
    if ($decoded && isset($decoded['data'])) {
        $decoded['source'] = 'cache';
        $decoded['cached_at'] = date('c', filemtime($cacheFile));
        echo json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}

// 3. REQUISIÇÃO À GRAPH API DA META (Por padrão foca no ciclo 2026: desde 01/08/2026)
$allTime = isset($_GET['all_time']) && ($_GET['all_time'] === '1' || $_GET['all_time'] === 'true');
$params = [
    'level'        => 'campaign',
    'limit'        => 100,
    'fields'       => 'campaign_name,date_start,date_stop,spend,impressions,clicks,cpc,ctr,actions',
    'access_token' => $accessToken
];
if ($allTime) {
    $params['date_preset'] = 'maximum';
} else {
    $params['time_range'] = json_encode(['since' => '2026-08-01', 'until' => date('Y-m-d')]);
}

$url = "https://graph.facebook.com/{$apiVersion}/{$adAccountId}/insights?" . http_build_query($params);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_USERAGENT      => 'FestivalIguassuInova-Dashboard/2.0'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr || $httpCode !== 200) {
    // Se falhar e tivermos cache antigo, entrega o cache como fallback gracioso
    if (file_exists($cacheFile)) {
        $cachedData = file_get_contents($cacheFile);
        $decoded = json_decode($cachedData, true);
        if ($decoded) {
            $decoded['source'] = 'stale_cache';
            $decoded['warning'] = 'API da Meta temporariamente indisponível. Dados carregados do cache de backup.';
            echo json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;
        }
    }
    
    http_response_code($httpCode ?: 500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Erro ao comunicar com a Meta Marketing API',
        'http_code' => $httpCode,
        'details' => $response ? json_decode($response, true) : $curlErr
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$rawJson = json_decode($response, true);
$rawCampaigns = $rawJson['data'] ?? [];

// 4. NORMALIZAÇÃO E LIMPEZA DOS DADOS CONFORME ESPECIFICAÇÃO
$normalizedCampaigns = [];

foreach ($rawCampaigns as $c) {
    $name        = $c['campaign_name'] ?? 'Sem nome';
    $spend       = (float)($c['spend'] ?? 0.0);
    $impressions = (int)($c['impressions'] ?? 0);
    $clicks      = (int)($c['clicks'] ?? 0);
    $ctr         = (float)($c['ctr'] ?? 0.0);
    $cpc         = (float)($c['cpc'] ?? 0.0);
    
    $actions = $c['actions'] ?? [];
    $linkClicks = 0;
    $leads = 0;
    $purchases = 0;
    $completeRegistrations = 0;
    $landingPageViews = 0;
    
    foreach ($actions as $a) {
        $type = $a['action_type'] ?? '';
        $val  = (int)($a['value'] ?? 0);
        
        if ($type === 'link_click') {
            $linkClicks += $val;
        } elseif (in_array($type, ['lead', 'onsite_web_lead', 'offsite_conversion.fb_pixel_lead'])) {
            $leads = max($leads, $val);
        } elseif (in_array($type, ['purchase', 'onsite_web_purchase', 'omni_purchase', 'offsite_conversion.fb_pixel_purchase'])) {
            $purchases = max($purchases, $val);
        } elseif (in_array($type, ['complete_registration', 'omni_complete_registration', 'offsite_conversion.fb_pixel_complete_registration'])) {
            $completeRegistrations = max($completeRegistrations, $val);
        } elseif (in_array($type, ['landing_page_view', 'omni_landing_page_view'])) {
            $landingPageViews = max($landingPageViews, $val);
        }
    }
    
    // Total de conversões: purchases ou complete_registrations ou leads
    $conversions = $purchases > 0 ? $purchases : ($completeRegistrations > 0 ? $completeRegistrations : $leads);
    $cpa = $conversions > 0 ? round($spend / $conversions, 2) : 0.0;
    
    $lowerName = mb_strtolower($name, 'UTF-8');
    $isLegacy = (strpos($lowerName, '[festival]') === 0) || (strpos($lowerName, '2024') !== false) || (strpos($lowerName, '2025') !== false);
    $isOfficial2026 = !$isLegacy && (
        strpos($lowerName, 'festival iguassu inova') !== false ||
        strpos($lowerName, 'iguassu inova') !== false ||
        (strpos($lowerName, 'festival') !== false && strpos($lowerName, '2026') !== false)
    );

    $normalizedCampaigns[] = [
        'campaign_name'          => $name,
        'date_start'             => $c['date_start'] ?? '',
        'date_stop'              => $c['date_stop'] ?? '',
        'spend'                  => round($spend, 2),
        'impressions'            => $impressions,
        'clicks'                 => $clicks,
        'link_clicks'            => $linkClicks,
        'ctr'                    => round($ctr, 2),
        'cpc'                    => round($cpc, 2),
        'leads'                  => $leads,
        'purchases'              => $purchases,
        'complete_registrations' => $completeRegistrations,
        'conversions'            => $conversions,
        'landing_page_views'     => $landingPageViews,
        'cpa'                    => $cpa,
        'is_festival_official'   => $isOfficial2026
    ];
}

// Ordenar pelo maior investimento (spend desc)
usort($normalizedCampaigns, function($a, $b) {
    if ($a['spend'] == $b['spend']) return 0;
    return ($a['spend'] > $b['spend']) ? -1 : 1;
});

// Resumo Geral da Conta
$totalSpend = 0.0;
$totalImpressions = 0;
$totalClicks = 0;
$totalLinkClicks = 0;
$totalConversions = 0;

foreach ($normalizedCampaigns as $camp) {
    $totalSpend += $camp['spend'];
    $totalImpressions += $camp['impressions'];
    $totalClicks += $camp['clicks'];
    $totalLinkClicks += $camp['link_clicks'];
    $totalConversions += $camp['conversions'];
}

$avgCpc = $totalClicks > 0 ? round($totalSpend / $totalClicks, 2) : 0.0;
$avgCtr = $totalImpressions > 0 ? round(($totalClicks / $totalImpressions) * 100, 2) : 0.0;
$avgCpa = $totalConversions > 0 ? round($totalSpend / $totalConversions, 2) : 0.0;

$result = [
    'status' => 'success',
    'source' => 'live_api',
    'timestamp' => date('c'),
    'account_id' => $adAccountId,
    'api_version' => $apiVersion,
    'totals' => [
        'total_spend'       => round($totalSpend, 2),
        'total_impressions' => $totalImpressions,
        'total_clicks'      => $totalClicks,
        'total_link_clicks' => $totalLinkClicks,
        'avg_cpc'           => $avgCpc,
        'avg_ctr'           => $avgCtr,
        'total_conversions' => $totalConversions,
        'avg_cpa'           => $avgCpa,
        'campaigns_count'   => count($normalizedCampaigns)
    ],
    'data' => $normalizedCampaigns
];

// Salvar no cache e atualizar snapshot JS para modo estático/GitHub Pages
file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$jsSnapshot = ['updated_at' => date('c'), 'data' => $normalizedCampaigns];
file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . 'meta_data.js', 'window.META_MARKETING_DATA = ' . json_encode($jsSnapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . ';');

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
