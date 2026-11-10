<?php
/**
 * Backend API Service: Yazo Tickets API v1 (Modo War Room & Operacional)
 * Festival Iguassu Inova 2026
 *
 * Funcionalidades:
 * - Lê YAZO_API_TOKEN e YAZO_EVENT_ID de .env.local / .env (ou cabeçalho X-Yazo-Token)
 * - Busca inscrições paginadas em paralelo via curl_multi (~5-8s para 13k+ registros)
 * - Cache local em yazo_cache.json (TTL padrão: 5 minutos; ?refresh=1 força nova coleta)
 * - Gera snapshot sanitizado (sem PII) em yazo_operacao_data.js para uso offline / GitHub Pages
 */

@set_time_limit(120);
@ini_set('memory_limit', '256M');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Yazo-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

date_default_timezone_set('America/Sao_Paulo');

function loadEnvFile($dir) {
    foreach (['.env.local', '.env'] as $file) {
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        if (file_exists($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
                    continue;
                }
                list($name, $value) = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value, " \t\n\r\0\x0B\"'");
                if (!isset($_ENV[$name])) {
                    $_ENV[$name] = $value;
                    putenv("$name=$value");
                }
            }
        }
    }
}

loadEnvFile(__DIR__);

$headerToken = $_SERVER['HTTP_X_YAZO_TOKEN'] ?? '';
$paramToken  = $_GET['token'] ?? ($_POST['token'] ?? '');
$yazoToken   = trim($headerToken ?: ($paramToken ?: (getenv('YAZO_API_TOKEN') ?: ($_ENV['YAZO_API_TOKEN'] ?? ''))));
$eventId     = trim(getenv('YAZO_EVENT_ID') ?: ($_ENV['YAZO_EVENT_ID'] ?? ''));

$cacheFile   = __DIR__ . DIRECTORY_SEPARATOR . 'yazo_cache.json';
$staticJsFile = __DIR__ . DIRECTORY_SEPARATOR . 'yazo_operacao_data.js';
$cacheTTL    = 300; // 5 minutos
$forceRefresh = isset($_GET['refresh']) && ($_GET['refresh'] === '1' || $_GET['refresh'] === 'true');

// Retorna cache válido se não forçado refresh
if (!$forceRefresh && file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTTL)) {
    $cached = json_decode(file_get_contents($cacheFile), true);
    if ($cached && isset($cached['records'])) {
        $cached['source'] = 'cache';
        $cached['cached_at'] = date('c', filemtime($cacheFile));
        echo json_encode($cached, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if ($yazoToken === '') {
    // Fallback para cache antigo caso não haja token configurado no servidor
    if (file_exists($cacheFile)) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached) {
            $cached['source'] = 'stale_cache';
            $cached['warning'] = 'Token Yazo não configurado no .env.local; exibindo último snapshot.';
            echo json_encode($cached, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'YAZO_API_TOKEN não configurado em .env.local e não foi informado.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$baseUrl = 'https://api.yazotickets.com/v1';

function createYazoCurlHandle($url, $token) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'User-Agent: FestivalIguassuInova-WarRoom/2.0'
        ]
    ]);
    return $ch;
}

function yazoGetSingle($url, $token) {
    $ch = createYazoCurlHandle($url, $token);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err || $code < 200 || $code >= 300) {
        return ['ok' => false, 'code' => $code, 'error' => $err ?: $res];
    }
    return ['ok' => true, 'data' => json_decode($res, true)];
}

function yazoGetMulti($urls, $token) {
    $mh = curl_multi_init();
    $handles = [];
    foreach ($urls as $key => $url) {
        $ch = createYazoCurlHandle($url, $token);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }

    $running = null;
    do {
        curl_multi_exec($mh, $running);
        if ($running > 0) {
            curl_multi_select($mh, 0.2);
        }
    } while ($running > 0);

    $results = [];
    foreach ($handles as $key => $ch) {
        $body = curl_multi_getcontent($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        if ($code >= 200 && $code < 300 && $body) {
            $results[$key] = json_decode($body, true);
        } else {
            $results[$key] = null;
        }
    }
    curl_multi_close($mh);
    return $results;
}

// 1. Descobre o evento se não estiver no .env
$eventInfo = null;
$eventsResp = yazoGetSingle("{$baseUrl}/events?size=20", $yazoToken);
if (!$eventsResp['ok']) {
    if (file_exists($cacheFile)) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached) {
            $cached['source'] = 'stale_cache';
            $cached['warning'] = 'Erro ao acessar Yazo API (HTTP ' . $eventsResp['code'] . '). Usando cache.';
            echo json_encode($cached, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    http_response_code($eventsResp['code'] ?: 502);
    echo json_encode([
        'status' => 'error',
        'message' => 'Falha ao autenticar na Yazo API.',
        'details' => $eventsResp['error']
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$eventsList = $eventsResp['data']['data'] ?? ($eventsResp['data']['items'] ?? []);
foreach ($eventsList as $ev) {
    if ($eventId && ($ev['id'] ?? '') === $eventId) {
        $eventInfo = $ev;
        break;
    }
    $evName = strtoupper($ev['name'] ?? '');
    if (strpos($evName, 'IGUASSU') !== false || strpos($evName, 'INOVA') !== false) {
        $eventId = $ev['id'];
        $eventInfo = $ev;
        break;
    }
}
if (!$eventId && !empty($eventsList)) {
    $eventId = $eventsList[0]['id'];
    $eventInfo = $eventsList[0];
}

// 2. Busca primeira página das inscrições e ingressos
$firstPage = yazoGetSingle("{$baseUrl}/events/{$eventId}/registrations?page=1&size=100", $yazoToken);
if (!$firstPage['ok']) {
    http_response_code(502);
    echo json_encode([
        'status' => 'error',
        'message' => 'Erro ao consultar inscrições do evento.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$totalItems = (int)($firstPage['data']['total'] ?? 0);
$totalPages = (int)($firstPage['data']['totalPages'] ?? 1);
$allItems   = $firstPage['data']['data'] ?? [];

// Coleta páginas restantes em lotes paralelos de 15
$batchSize = 15;
for ($p = 2; $p <= $totalPages; $p += $batchSize) {
    $batchUrls = [];
    for ($j = $p; $j < $p + $batchSize && $j <= $totalPages; $j++) {
        $batchUrls[$j] = "{$baseUrl}/events/{$eventId}/registrations?page={$j}&size=100";
    }
    $responses = yazoGetMulti($batchUrls, $yazoToken);
    ksort($responses);
    foreach ($responses as $res) {
        if ($res && !empty($res['data'])) {
            foreach ($res['data'] as $row) {
                $allItems[] = $row;
            }
        }
    }
}

// 3. Normaliza registros (sem PII) e computa métricas de status
$statusCounts = [];
$records = [];
$tzSp = new DateTimeZone('America/Sao_Paulo');
$tzUtc = new DateTimeZone('UTC');

function parseUtms($rawUtms) {
    $out = ['us' => '', 'um' => '', 'uc' => ''];
    if (empty($rawUtms)) return $out;
    if (is_array($rawUtms)) {
        $out['us'] = trim((string)($rawUtms['utm_source'] ?? ''));
        $out['um'] = trim((string)($rawUtms['utm_medium'] ?? ''));
        $out['uc'] = trim((string)($rawUtms['utm_campaign'] ?? ''));
        return $out;
    }
    $lines = preg_split('/[\r\n&]+/', (string)$rawUtms);
    foreach ($lines as $line) {
        if (strpos($line, '=') === false) continue;
        list($k, $v) = explode('=', $line, 2);
        $k = strtolower(trim($k));
        $v = urldecode(trim($v));
        if ($k === 'utm_source') $out['us'] = $v;
        elseif ($k === 'utm_medium') $out['um'] = $v;
        elseif ($k === 'utm_campaign') $out['uc'] = $v;
    }
    return $out;
}

function cleanSurveyArrayOrString($val) {
    if ($val === null || $val === '') return '';
    $s = trim((string)$val);
    if (strpos($s, '[') === 0) {
        $decoded = json_decode($s, true);
        if (is_array($decoded)) {
            return implode(' | ', array_map('trim', $decoded));
        }
    }
    return $s;
}

foreach ($allItems as $item) {
    $st = $item['status'] ?? 'unknown';
    $statusCounts[$st] = ($statusCounts[$st] ?? 0) + 1;

    // Painel contabiliza inscrições válidas confirmadas
    if ($st !== 'valid') continue;

    $createdAt = $item['createdAt'] ?? '';
    if (!$createdAt) continue;

    try {
        $dt = new DateTime($createdAt, $tzUtc);
        $dt->setTimezone($tzSp);
    } catch (Exception $e) {
        continue;
    }

    $user = $item['user'] ?? [];
    $email = strtolower(trim((string)($user['email'] ?? '')));
    $uid = $email !== '' ? substr(md5($email), 0, 10) : substr(md5($item['id'] ?? uniqid()), 0, 10);

    $ticketName = $item['ticket']['name'] ?? 'Geral';
    $utms = parseUtms($item['utms'] ?? null);

    $perfil = 'Não informado';
    $origem = 'Não informado';
    $cidade = '';
    $instituicao = '';

    $answers = $item['dynamicFormAnswers'] ?? [];
    foreach ($answers as $ans) {
        $q = mb_strtolower((string)($ans['name'] ?? ($ans['label'] ?? '')), 'UTF-8');
        $v = $ans['value'] ?? ($ans['answer'] ?? '');
        if ($v === '' || $v === null) continue;

        if (strpos($q, 'perfil') !== false || strpos($q, 'representa') !== false) {
            $perfil = trim((string)$v);
        } elseif (strpos($q, 'ficou sabendo') !== false) {
            $origem = cleanSurveyArrayOrString($v);
        } elseif ($q === 'cidade' || strpos($q, 'cidade') === 0) {
            $cidade = trim((string)$v);
        } elseif (strpos($q, 'institui') !== false || strpos($q, 'escola/universidade') !== false) {
            $instituicao = trim((string)$v);
        }
    }

    $records[] = [
        'd'  => $dt->format('Y-m-d'),
        'h'  => (int)$dt->format('G'),
        'm'  => (int)$dt->format('i'),
        't'  => $ticketName,
        'p'  => $perfil,
        'o'  => $origem,
        'us' => $utms['us'],
        'um' => $utms['um'],
        'uc' => $utms['uc'],
        'c'  => $cidade,
        'i'  => $instituicao,
        'u'  => $uid
    ];
}

// Ordena cronologicamente (do mais antigo ao mais recente)
usort($records, function($a, $b) {
    if ($a['d'] !== $b['d']) return strcmp($a['d'], $b['d']);
    if ($a['h'] !== $b['h']) return $a['h'] <=> $b['h'];
    return $a['m'] <=> $b['m'];
});

$payload = [
    'status'        => 'ok',
    'source'        => 'live_api',
    'sync_time'     => date('Y-m-d H:i:s'),
    'sync_iso'      => date('c'),
    'event_id'      => $eventId,
    'event_name'    => $eventInfo['name'] ?? 'Festival Iguassu Inova',
    'event_days'    => $eventInfo['days'] ?? [],
    'total_api'     => count($allItems),
    'total_valid'   => count($records),
    'status_counts' => $statusCounts,
    'records'       => $records
];

$jsonStr = json_encode($payload, JSON_UNESCAPED_UNICODE);
file_put_contents($cacheFile, $jsonStr);

// Salva fallback sanitizado em JS para GitHub Pages / uso offline
$jsSnapshot = $payload;
$jsSnapshot['source'] = 'snapshot_js';
file_put_contents($staticJsFile, 'window.YAZO_OPERACAO_DATA = ' . json_encode($jsSnapshot, JSON_UNESCAPED_UNICODE) . ';');

echo $jsonStr;
