<?php
declare(strict_types=1);
require_once __DIR__ . '/_boot.php';

if (!rate_limit('chat', 40, 300)) {
    api_fail('You are sending messages faster than I can answer. Give it a moment.', 429);
}

$message = (string) (api_input()['message'] ?? '');
$answer  = ShopAssistant::answer(mb_substr($message, 0, 400), current_user());

api_ok(['reply' => $answer['reply'], 'chips' => $answer['chips']]);
