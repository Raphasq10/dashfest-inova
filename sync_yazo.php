<?php
/**
 * Ponte PHP para sincronização da API Yazo Tickets com o data.js (Painel Geral).
 * Compatível com Yazo API v1 (Bearer token, chave 'data', dynamicFormAnswers 'name').
 */

@set_time_limit(120);
@ini_set('memory_limit', '256M');
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Sao_Paulo');

function loadSyncEnv($dir) {
    foreach (['.env.local', '.env'] as $file) {
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        if (file_exists($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) continue;
                list($k, $v) = explode('=', $line, 2);
                $k = trim($k);
                $v = trim($v, " \t\n\r\0\x0B\"'");
                if (!isset($_ENV[$k])) {
                    $_ENV[$k] = $v;
                    putenv("$k=$v");
                }
            }
        }
    }
}

loadSyncEnv(__DIR__);

$token = trim($_POST['token'] ?? ($_GET['token'] ?? (getenv('YAZO_API_TOKEN') ?: ($_ENV['YAZO_API_TOKEN'] ?? ''))));

if ($token === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Token de API da Yazo não informado e não encontrado em .env.local.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$baseUrl = 'https://api.yazotickets.com/v1';

function yazoGet($endpoint, $token, $params = []) {
    global $baseUrl;
    $url = $baseUrl . $endpoint;
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'User-Agent: FestivalIguassuInova-Dashboard/2.0'
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        return json_decode($response, true);
    }
    return null;
}

function yazoGetBatch($urls, $token) {
    $mh = curl_multi_init();
    $handles = [];
    foreach ($urls as $k => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
                'User-Agent: FestivalIguassuInova-Dashboard/2.0'
            ]
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$k] = $ch;
    }

    $running = null;
    do {
        curl_multi_exec($mh, $running);
        if ($running > 0) curl_multi_select($mh, 0.2);
    } while ($running > 0);

    $out = [];
    foreach ($handles as $k => $ch) {
        $body = curl_multi_getcontent($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        $out[$k] = ($code >= 200 && $code < 300 && $body) ? json_decode($body, true) : null;
    }
    curl_multi_close($mh);
    return $out;
}

// 1. Localiza o Evento
$eventsData = yazoGet('/events', $token, ['size' => 50]);
if (!$eventsData) {
    echo json_encode([
        'success' => false,
        'message' => 'Falha ao autenticar com a Yazo. Verifique se o Token é válido.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$events = $eventsData['data'] ?? ($eventsData['items'] ?? $eventsData);
$eventId = getenv('YAZO_EVENT_ID') ?: null;
if (!$eventId && is_array($events)) {
    foreach ($events as $ev) {
        $name = strtoupper($ev['name'] ?? '');
        if (strpos($name, 'IGUASSU') !== false || strpos($name, 'INOVA') !== false) {
            $eventId = $ev['id'];
            break;
        }
    }
    if (!$eventId && !empty($events)) {
        $eventId = $events[0]['id'];
    }
}

if (!$eventId) {
    echo json_encode([
        'success' => false,
        'message' => 'Nenhum evento encontrado nesta conta Yazo.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. Coleta Inscrições Paginadas em paralelo
$first = yazoGet("/events/{$eventId}/registrations", $token, ['page' => 1, 'size' => 100]);
if (!$first) {
    echo json_encode(['success' => false, 'message' => 'Erro ao consultar inscrições.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$allRegistrations = $first['data'] ?? ($first['items'] ?? []);
$totalPages = (int)($first['totalPages'] ?? 1);

for ($p = 2; $p <= $totalPages; $p += 15) {
    $urls = [];
    for ($j = $p; $j < $p + 15 && $j <= $totalPages; $j++) {
        $urls[$j] = "{$baseUrl}/events/{$eventId}/registrations?page={$j}&size=100";
    }
    $batchRes = yazoGetBatch($urls, $token);
    ksort($batchRes);
    foreach ($batchRes as $res) {
        $rows = $res['data'] ?? ($res['items'] ?? []);
        foreach ($rows as $item) {
            $allRegistrations[] = $item;
        }
    }
}

// 3. Formata Registros Válidos
$records = [];
$tzSp = new DateTimeZone('America/Sao_Paulo');
$tzUtc = new DateTimeZone('UTC');

foreach ($allRegistrations as $item) {
    if (($item['status'] ?? 'valid') !== 'valid') continue;

    $user = $item['user'] ?? [];
    $name = trim($user['name'] ?? '');
    $email = trim($user['email'] ?? '');

    $ticket = $item['ticket'] ?? [];
    $tipo = $ticket['name'] ?? 'Geral';

    $perfil = 'Não informado';
    if (!empty($item['dynamicFormAnswers'])) {
        foreach ($item['dynamicFormAnswers'] as $ans) {
            $lbl = mb_strtolower($ans['name'] ?? ($ans['label'] ?? ($ans['question'] ?? '')), 'UTF-8');
            if (strpos($lbl, 'perfil') !== false || strpos($lbl, 'representa') !== false) {
                $perfil = $ans['value'] ?? ($ans['answer'] ?? $perfil);
                break;
            }
        }
    }

    $createdAt = $item['createdAt'] ?? '';
    if (!$createdAt) continue;

    try {
        $dt = new DateTime($createdAt, $tzUtc);
        $dt->setTimezone($tzSp);
    } catch (Exception $e) {
        continue;
    }

    $records[] = [
        'dt' => $dt->format('d/m/Y H:i'),
        'date' => $dt->format('Y-m-d'),
        'date_br' => $dt->format('d/m/Y'),
        'hour' => (int)$dt->format('G'),
        'email' => $email,
        'name' => $name,
        'tipo' => $tipo,
        'perfil' => $perfil
    ];
}

// 4. Salva data.js
$payload = [
    'sync_source' => 'yazo_api_web',
    'sync_time' => date('Y-m-d H:i:s'),
    'total_records' => count($records),
    'unique_emails' => count(array_unique(array_map('strtolower', array_column($records, 'email')))),
    'records' => $records
];

$jsContent = 'window.FESTIVAL_DATA = ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . ';';
file_put_contents(__DIR__ . '/data.js', $jsContent);

echo json_encode([
    'success' => true,
    'count' => count($records),
    'message' => 'Sincronização concluída com sucesso!'
], JSON_UNESCAPED_UNICODE);
