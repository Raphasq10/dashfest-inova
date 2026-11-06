<?php
/**
 * Ponte PHP para sincronização da API Yazo Tickets com o Dashboard.
 * Executado pelo servidor Apache local sem restrições de CORS.
 */

header('Content-Type: application/json; charset=utf-8');

$token = isset($_POST['token']) ? trim($_POST['token']) : (isset($_GET['token']) ? trim($_GET['token']) : '');

if (empty($token)) {
    echo json_encode([
        'success' => false,
        'message' => 'Token de API da Yazo não informado.'
    ]);
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
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'User-Agent: FestivalIguassuInova-Dashboard/1.0'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        return json_decode($response, true);
    }
    return null;
}

// 1. Localiza o Evento
$eventsData = yazoGet('/events', $token, ['size' => 50]);
if (!$eventsData) {
    echo json_encode([
        'success' => false,
        'message' => 'Falha ao autenticar com a Yazo. Verifique se o Token é válido.'
    ]);
    exit;
}

$events = isset($eventsData['items']) ? $eventsData['items'] : $eventsData;
$eventId = null;
foreach ($events as $ev) {
    $name = isset($ev['name']) ? strtoupper($ev['name']) : '';
    if (strpos($name, 'IGUASSU') !== false || strpos($name, 'INOVA') !== false) {
        $eventId = $ev['id'];
        break;
    }
}

if (!$eventId && !empty($events)) {
    $eventId = $events[0]['id'];
}

if (!$eventId) {
    echo json_encode([
        'success' => false,
        'message' => 'Nenhum evento encontrado nesta conta Yazo.'
    ]);
    exit;
}

// 2. Coleta Inscrições Paginadas
$page = 1;
$size = 100;
$allRegistrations = [];

do {
    $regData = yazoGet("/events/{$eventId}/registrations", $token, ['page' => $page, 'size' => $size]);
    if (!$regData || empty($regData['items'])) {
        break;
    }

    foreach ($regData['items'] as $item) {
        $allRegistrations[] = $item;
    }

    $totalPages = isset($regData['totalPages']) ? $regData['totalPages'] : 1;
    $page++;
} while ($page <= $totalPages && count($regData['items']) == $size);

// 3. Formata Registros
$records = [];
foreach ($allRegistrations as $item) {
    $user = isset($item['user']) ? $item['user'] : [];
    $name = isset($user['name']) ? trim($user['name']) : '';
    $email = isset($user['email']) ? trim($user['email']) : '';

    $ticket = isset($item['ticket']) ? $item['ticket'] : [];
    $tipo = isset($ticket['name']) ? $ticket['name'] : 'Geral';

    $perfil = 'Não informado';
    if (!empty($item['dynamicFormAnswers'])) {
        foreach ($item['dynamicFormAnswers'] as $ans) {
            $lbl = strtolower(isset($ans['label']) ? $ans['label'] : (isset($ans['question']) ? $ans['question'] : ''));
            if (strpos($lbl, 'perfil') !== false || strpos($lbl, 'representa') !== false) {
                $perfil = isset($ans['value']) ? $ans['value'] : (isset($ans['answer']) ? $ans['answer'] : $perfil);
                break;
            }
        }
    }

    $createdAt = isset($item['createdAt']) ? $item['createdAt'] : '';
    if (!$createdAt) continue;

    $dt = new DateTime($createdAt);
    // Fuso de Brasília
    $dt->setTimezone(new DateTimeZone('America/Sao_Paulo'));

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
]);
