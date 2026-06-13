<?php
session_start();

require_once __DIR__ . '/Parsedown.php';

const DEFAULT_MODEL = 'grok-4.3';
const MAX_IMAGE_BYTES = 20 * 1024 * 1024;
const SUPPORTED_IMAGE_TYPES = [
    'image/jpeg' => 'jpeg',
    'image/png' => 'png',
];

$_SESSION['messages'] = is_array($_SESSION['messages'] ?? null)
    ? $_SESSION['messages']
    : [];
$_SESSION['notices'] = is_array($_SESSION['notices'] ?? null)
    ? $_SESSION['notices']
    : [];

$envFile = is_file(__DIR__ . '/.env')
    ? (parse_ini_file(__DIR__ . '/.env', false, INI_SCANNER_RAW) ?: [])
    : [];

function configValue(array $envFile, array $names, string $default = ''): string
{
    foreach ($names as $name) {
        $value = getenv($name);
        if ($value !== false && trim($value) !== '') {
            return trim($value);
        }

        if (isset($envFile[$name]) && trim((string) $envFile[$name]) !== '') {
            return trim((string) $envFile[$name]);
        }
    }

    return $default;
}

function appendAssistantMessage(string $message): void
{
    $_SESSION['messages'][] = [
        'role' => 'assistant',
        'content' => $message,
    ];
}

function appendNotice(string $message): void
{
    $_SESSION['notices'][] = $message;
}

function conversationMessages(array $messages): array
{
    return array_values(array_filter($messages, static function (array $message): bool {
        if (!in_array($message['role'] ?? null, ['user', 'assistant'], true)) {
            return false;
        }

        $content = $message['content'] ?? null;
        if (($message['role'] ?? null) !== 'assistant' || !is_string($content)) {
            return true;
        }

        foreach (['**Request error:**', '**Configuration error:**', '**Upload error:**'] as $prefix) {
            if (str_starts_with($content, $prefix)) {
                return false;
            }
        }

        return true;
    }));
}

function redirectToChat(): void
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    header('Location: ' . $path, true, 303);
    exit;
}

function apiEndpoint(string $accountId, string $gatewayId): string
{
    if ($accountId === '') {
        return 'https://api.x.ai/v1/chat/completions';
    }

    return sprintf(
        'https://gateway.ai.cloudflare.com/v1/%s/%s/grok/v1/chat/completions',
        rawurlencode($accountId),
        rawurlencode($gatewayId !== '' ? $gatewayId : 'default')
    );
}

function uploadedImageContent(?array $image): array
{
    if ($image === null || ($image['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }

    if (($image['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return [null, 'The image upload failed. Please choose the file again.'];
    }

    $size = (int) ($image['size'] ?? 0);
    if ($size <= 0 || $size > MAX_IMAGE_BYTES) {
        return [null, 'Images must be no larger than 20 MiB.'];
    }

    $tmpName = (string) ($image['tmp_name'] ?? '');
    $mimeType = $tmpName !== '' ? (new finfo(FILEINFO_MIME_TYPE))->file($tmpName) : false;
    if (!is_string($mimeType) || !isset(SUPPORTED_IMAGE_TYPES[$mimeType])) {
        return [null, 'Only JPEG and PNG images are supported.'];
    }

    $bytes = file_get_contents($tmpName);
    if ($bytes === false) {
        return [null, 'The uploaded image could not be read.'];
    }

    return [[
        'type' => 'image_url',
        'image_url' => [
            'url' => sprintf('data:%s;base64,%s', $mimeType, base64_encode($bytes)),
            'detail' => 'high',
        ],
    ], null];
}

function providerErrorMessage(array $response, string $rawBody): ?string
{
    $error = $response['error'] ?? null;
    $candidates = [
        is_array($error) ? ($error['message'] ?? $error['detail'] ?? null) : $error,
        $response['message'] ?? null,
        $response['detail'] ?? null,
    ];

    $errors = $response['errors'] ?? [];
    if (is_array($errors)) {
        foreach ($errors as $item) {
            $candidates[] = is_array($item) ? ($item['message'] ?? $item['detail'] ?? null) : $item;
        }
    }

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            return trim($candidate);
        }
    }

    $body = trim(preg_replace('/\s+/', ' ', strip_tags($rawBody)) ?? '');
    return $body !== '' ? substr($body, 0, 500) : null;
}

function requestCompletion(string $endpoint, string $apiKey, string $model, array $messages): array
{
    if (!function_exists('curl_init')) {
        return [null, 'The server is missing the PHP cURL extension.'];
    }

    $payload = json_encode([
        'model' => $model,
        'messages' => $messages,
        'stream' => false,
    ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

    if ($payload === false) {
        return [null, 'The chat request could not be encoded.'];
    }

    $responseHeaders = [];
    $handle = curl_init($endpoint);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$responseHeaders): int {
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($header);
        },
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 120,
    ]);

    $body = curl_exec($handle);
    $curlError = curl_error($handle);
    $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    if ($body === false) {
        return [null, 'Grok could not be reached: ' . ($curlError ?: 'network error')];
    }

    $response = json_decode($body, true);
    if (!is_array($response)) {
        $details = providerErrorMessage([], $body);
        return [
            null,
            sprintf(
                'Grok returned an invalid response (HTTP %d)%s',
                $statusCode,
                $details !== null ? ': ' . $details : '.'
            ),
        ];
    }

    $content = $response['choices'][0]['message']['content'] ?? null;
    if ($statusCode >= 200 && $statusCode < 300 && is_string($content) && $content !== '') {
        return [$content, null];
    }

    $apiError = providerErrorMessage($response, $body);
    $requestId = $responseHeaders['x-request-id']
        ?? $responseHeaders['cf-ray']
        ?? $response['request_id']
        ?? null;
    $requestIdSuffix = is_string($requestId) && $requestId !== ''
        ? sprintf(' (request ID: %s)', $requestId)
        : '';

    return [
        null,
        $apiError !== null
            ? sprintf('Grok request failed (HTTP %d): %s%s', $statusCode, $apiError, $requestIdSuffix)
            : sprintf('Grok request failed with HTTP %d%s.', $statusCode, $requestIdSuffix),
    ];
}

$apiKey = configValue($envFile, ['XAI_API_KEY', 'api-key']);
$accountId = configValue($envFile, ['CLOUDFLARE_ACCOUNT_ID', 'cf-account-id']);
$gatewayId = configValue($envFile, ['CLOUDFLARE_GATEWAY_ID', 'cf-gateway-id'], 'ai');
$model = configValue($envFile, ['XAI_MODEL', 'model'], DEFAULT_MODEL);

header('Cache-Control: no-store, private');

if (isset($_GET['clear'])) {
    $_SESSION = [];
    session_destroy();
    redirectToChat();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $message = trim((string) ($_POST['message'] ?? ''));
    [$imageContent, $imageError] = uploadedImageContent($_FILES['image'] ?? null);

    if ($imageError !== null) {
        appendNotice('**Upload error:** ' . $imageError);
        redirectToChat();
    }

    $content = [];
    if ($message !== '') {
        $content[] = ['type' => 'text', 'text' => $message];
    }
    if ($imageContent !== null) {
        $content[] = $imageContent;
    }

    if ($content === []) {
        appendNotice('Please enter a message or attach an image.');
        redirectToChat();
    }

    $_SESSION['messages'][] = ['role' => 'user', 'content' => $content];

    if ($apiKey === '') {
        appendNotice(
            '**Configuration error:** Set `XAI_API_KEY` in the environment or in a local `.env` file.'
        );
        redirectToChat();
    }

    [$completion, $requestError] = requestCompletion(
        apiEndpoint($accountId, $gatewayId),
        $apiKey,
        $model,
        conversationMessages($_SESSION['messages'])
    );

    if ($completion !== null) {
        appendAssistantMessage($completion);
    } else {
        appendNotice('**Request error:** ' . $requestError);
    }
    redirectToChat();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Grok Chatbot</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fastly.jsdelivr.net/npm/remixicon@4.5.0/fonts/remixicon.css" rel="stylesheet"/>
  <link href="parsedown.css" rel="stylesheet"/>
  <style>form::after,textarea {grid-area: 1/1/2/2}form::after{content:attr(data-replicated-value)" ";white-space:pre-wrap;visibility:hidden;}</style>
</head>
<body class="bg-[#f9f8f6]">
<main class="h-dvh flex flex-col">
  <header class="fixed w-full z-40 bg-gradient-to-b from-gray-100 to-transparent p-3">
    <div class="max-w-[50rem] mx-auto flex justify-between items-center">
      <div class="flex items-center gap-2">
        <div class="w-8 h-8 rounded-lg bg-[#1C1C1C] flex items-center justify-center">
          <i class="ri-twitter-x-fill text-xl text-white"></i>
        </div>
        <span class="text-xl font-semibold">Grok</span>
      </div>
      <div class="flex items-center gap-4">
        <a href="?clear=1" title="Clear history" class="p-2 hover:bg-gray-200 rounded-full leading-none"><i class="ri-edit-line text-xl leading-none"></i></a>
        <button type="button" onclick="navigator.share({title:'Grok Chat',text:'Check out my chat with Grok',url:window.location.href})" class="p-2 hover:bg-gray-200 rounded-full leading-none">
          <i class="ri-share-2-line text-xl leading-none"></i>
        </button>
        <div class="h-8 w-8 rounded-full bg-violet-500 text-white flex items-center justify-center">M</div>
      </div>
    </div>
  </header>
  <div id="chat-container" class="flex-1 overflow-y-auto px-5 pt-20 pb-40">
    <div class="max-w-[50rem] mx-auto flex flex-col gap-8 pb-4">
      <?php 

      $parsedown = (new Parsedown())->setSafeMode(true);

      foreach ($_SESSION['notices'] as $notice): ?>
        <div class="flex justify-start">
          <div class="max-w-[80%] rounded-2xl border border-red-200 bg-red-50 p-3 text-red-800">
            <?= $parsedown->text($notice) ?>
          </div>
        </div>
      <?php endforeach;
      $_SESSION['notices'] = [];

      foreach ($_SESSION["messages"] as $m):
          $isUser = $m["role"] === "user"; ?>
        <div class="flex <?= $isUser ? "justify-end" : "parsedown justify-start" ?>">
          <div class="max-w-[80%] p-3 <?= $isUser
              ? "bg-blue-500 text-white rounded-l-3xl rounded-t-3xl"
              : "" ?>">
            <?php if (is_array($m["content"])): ?>
              <?php foreach ($m["content"] as $content): ?>
                <?php if ($content["type"] === "image_url"): ?>
                  <img src="<?= htmlspecialchars($content["image_url"]["url"], ENT_QUOTES, "UTF-8") ?>" alt="Uploaded image" class="max-w-full rounded-lg mb-2" />
                <?php else: ?>
                  <?= $parsedown->text($content["text"] ?? "") ?>
                <?php endif; ?>
              <?php endforeach; ?>
            <?php else: ?>
              <?= $parsedown->text($m["content"] ?? "Error: Missing content") ?>
            <?php endif; ?>
          </div>
        </div>
      <?php
      endforeach; ?>
    </div>
  </div>
  <div class="fixed bottom-0 w-full max-w-[50rem] left-1/2 -translate-x-1/2 p-3">
    <form method="POST" enctype="multipart/form-data" class="grid relative bg-stone-50 p-2 rounded-3xl ring-1 ring-gray-200 hover:ring-gray-300 hover:shadow hover:bg-white focus-within:ring-gray-300 duration-300" data-replicated-value="">
      <textarea name="message" class="w-full p-3 bg-transparent focus:outline-none" placeholder="How can Grok help?" style="resize:none;" oninput="this.parentNode.dataset.replicatedValue=this.value"></textarea>
      <div class="grid grid-cols-[auto_1fr] gap-2 absolute bottom-4 right-4">
        <select class="rounded-lg border px-3 py-1.5 text-sm"><option><?= htmlspecialchars($model, ENT_QUOTES, 'UTF-8') ?></option></select>
        <button id="submit-button" type="submit" disabled class="justify-self-end rounded-full bg-black hover:bg-gray-600 text-white p-2 leading-none disabled:bg-gray-300 duration-300"><i class="ri-arrow-up-line"></i></button>
      </div>
      <div class="absolute bottom-4 left-4">
        <label class="p-2 hover:bg-gray-200 rounded-full leading-none cursor-pointer">
          <input type="file" name="image" accept="image/jpeg,image/png" class="hidden" onchange="showImagePreview(this)"/>
          <i class="ri-attachment-2 leading-none"></i>
        </label>
        <div id="image-preview" class="hidden absolute bottom-12 left-0 bg-white p-2 rounded-lg shadow-lg">
          <img src="" alt="Preview" class="max-w-[100px] max-h-[100px] rounded"/>
          <button type="button" onclick="clearImage()" class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full w-5 h-5 flex items-center justify-center">×</button>
        </div>
      </div>
    </form>
  </div>
</main>
<script>
const a=document.getElementsByName('message')[0],
      b=document.getElementById('submit-button'),
      c=document.getElementById('chat-container');
const showImagePreview=i=>{if(!i.files[0])return;let r=new FileReader;r.onload=e=>{document.querySelector('#image-preview img').src=e.target.result;document.getElementById('image-preview').classList.remove('hidden');b.disabled=!1};r.readAsDataURL(i.files[0])}
const clearImage=()=>{document.querySelector('input[type=file]').value='',document.getElementById('image-preview').classList.add('hidden'),b.disabled=!a.value.trim()};
a.addEventListener('input',()=>b.disabled=!a.value.trim());
c.scrollTop=c.scrollHeight;
document.addEventListener('keydown',e=>e.shiftKey&&e.key==='Enter'?e:e.key==='Enter'&&(a.value.trim()||document.querySelector('input[type=file]').files.length)?(e.preventDefault(),document.querySelector('form').requestSubmit()):0);
</script>
</body>
</html>
