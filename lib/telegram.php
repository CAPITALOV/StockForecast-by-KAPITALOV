<?php
/**
 * Отправка сообщений в Telegram.
 * Требует TELEGRAM_BOT_TOKEN и TELEGRAM_CHAT_ID в config.php.
 * Если не заданы — функция молча возвращает false (уведомления отключены).
 */
function telegram_send(string $message): bool {
    if (!defined('TELEGRAM_BOT_TOKEN') || TELEGRAM_BOT_TOKEN === '') return false;
    if (!defined('TELEGRAM_CHAT_ID') || TELEGRAM_CHAT_ID === '') return false;

    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage';
    $data = [
        'chat_id' => TELEGRAM_CHAT_ID,
        'text' => $message,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];
    $options = ['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($data),
        'timeout' => 10,
    ]];
    $context = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);
    if ($result === false) return false;
    $json = json_decode($result, true);
    return isset($json['ok']) && $json['ok'] === true;
}