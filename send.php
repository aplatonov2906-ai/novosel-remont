<?php
/**
 * Приём заявок с novosel-remont.ru и передача в CRM.
 *
 * Основной путь — вебхук crm-komfort.ru/api/intake с токеном в заголовке.
 * Запасной — письмо на разборщик, если вебхук недоступен.
 *
 * Токен читается из ../intake.env, то есть ВЫШЕ корня сайта: по HTTP его
 * не отдать и в репозиторий он не попадает. Код совместим с PHP 5.6+.
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

define('CRM_URL', 'https://crm-komfort.ru/api/intake');
define('SITE', 'novosel-remont.ru');
define('FALLBACK_MAIL', 'New-site002@yandex.ru');

function respond($code, $payload) {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function field($src, $key) {
    return isset($src[$key]) ? trim((string) $src[$key]) : '';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, array('ok' => false, 'error' => 'Только POST'));
}

/* ---------- входные данные: JSON или обычная форма ---------- */
$data = array();
$raw = file_get_contents('php://input');
if ($raw !== '' && substr(ltrim($raw), 0, 1) === '{') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}
if (!$data) {
    $data = $_POST;
}

/* ---------- ловушка для ботов ----------
   Поле скрыто стилями: человек его не видит, автозаполнялки ботов — заполняют.
   Такую заявку принимаем молча и в CRM не отправляем. */
if (field($data, 'company') !== '') {
    respond(200, array('ok' => true));
}

/* ---------- валидация ---------- */
$name    = field($data, 'name');
$phone   = field($data, 'phone');
$message = field($data, 'message');

if ($name === '' || $phone === '') {
    respond(422, array('ok' => false, 'error' => 'Укажите имя и телефон'));
}

$digits = preg_replace('/\D+/', '', $phone);
if (strlen($digits) < 10) {
    respond(422, array('ok' => false, 'error' => 'Проверьте номер телефона'));
}

if (function_exists('mb_substr')) {
    $name    = mb_substr($name, 0, 100, 'UTF-8');
    $message = mb_substr($message, 0, 2000, 'UTF-8');
}

/* ---------- защита от флуда: не чаще одной заявки в 20 секунд с адреса ---------- */
$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
$lock = sys_get_temp_dir() . '/nr-lead-' . md5($ip);
if (file_exists($lock) && (time() - filemtime($lock)) < 20) {
    respond(429, array('ok' => false, 'error' => 'Заявка уже отправлена, подождите немного'));
}
@touch($lock);

/* ---------- токен ---------- */
$token = '';
$envPath = dirname(__DIR__) . '/intake.env';
if (is_readable($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, 'INTAKE_TOKEN=') === 0) {
            $token = trim(substr($line, 13), " \t\"'");
        }
    }
}

/* ---------- идентификатор для защиты от дублей на стороне CRM ---------- */
$externalId = 'nr-' . date('Ymd-His') . '-' . substr(md5($digits . microtime(true)), 0, 8);

$payload = array(
    'name'       => $name,
    'phone'      => $phone,
    'message'    => $message,
    'site'       => SITE,
    'externalId' => $externalId,
);

/* ---------- основной путь: вебхук CRM ---------- */
$sent = false;
$problem = '';

if ($token !== '' && function_exists('curl_init')) {
    $ch = curl_init(CRM_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'x-intake-token: ' . $token,
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false) {
        $problem = 'сеть: ' . curl_error($ch);
    }
    curl_close($ch);

    if ($status >= 200 && $status < 300) {
        $sent = true;
    } elseif ($problem === '') {
        $problem = 'CRM ответила ' . $status . ': ' . substr((string) $body, 0, 300);
    }
} elseif ($token === '') {
    $problem = 'нет токена в intake.env';
}

/* ---------- запасной путь: письмо разборщику ---------- */
if (!$sent) {
    $subject = '[' . SITE . '] Novaya zayavka';
    $text = "Имя: {$name}\n"
          . "Телефон: {$phone}\n"
          . "Сообщение: {$message}\n"
          . "Сайт: " . SITE . "\n"
          . "ID заявки: {$externalId}\n"
          . "\nПисьмо отправлено запасным путём, вебхук не прошёл: {$problem}\n";
    $headers = "From: site@" . SITE . "\r\n"
             . "Content-Type: text/plain; charset=utf-8\r\n";
    if (@mail(FALLBACK_MAIL, $subject, $text, $headers)) {
        $sent = true;
    }
}

/* ---------- локальный журнал: чтобы заявка не потерялась совсем ---------- */
$logLine = date('c') . "\t" . ($sent ? 'ok' : 'FAIL') . "\t" . $externalId
         . "\t" . str_replace(array("\r", "\n", "\t"), ' ', $name)
         . "\t" . $phone
         . "\t" . str_replace(array("\r", "\n", "\t"), ' ', $message)
         . "\t" . $problem . "\n";
@file_put_contents(dirname(__DIR__) . '/leads.log', $logLine, FILE_APPEND | LOCK_EX);

if ($sent) {
    respond(200, array('ok' => true));
}

respond(502, array('ok' => false, 'error' => 'Не удалось отправить заявку. Позвоните нам: +7 (495) 019-96-75'));
