<?php

$targetDir = __DIR__ . '/uploads';
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0777, true);
}

function getFileList($targetDir)
{
    $files = scandir($targetDir);
    $files = array_diff($files, ['.', '..']);
    $files = array_reverse($files);
    return array_values($files);
}

function getRequestHeaders()
{
    $headers = [];
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0) {
            $name = str_replace('_', '-', strtolower(substr($k, 5)));
            $headers[$name] = $v;
        }
    }
    return $headers;
}

function writeLog($logFile, $data)
{
    file_put_contents($logFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
}

$response = ["success" => false];
$logData = [
    'timestamp' => date('c'),
    'request' => [
        'POST' => $_POST,
        'FILES' => $_FILES,
        'headers' => getRequestHeaders(),
    ],
];
$deepgramResult = null;
$logFile = $targetDir . '/init.log';

if (isset($_FILES['audio']) && $_FILES['audio']['error'] === UPLOAD_ERR_OK) {
    $fileTmpPath = $_FILES['audio']['tmp_name'];
    $originalName = $_FILES['audio']['name'];
    $extension = pathinfo($originalName, PATHINFO_EXTENSION);
    $filename = date('Ymd_His') . '.' . $extension;
    $destination = $targetDir . '/' . $filename;
    $logFile = $targetDir . '/' . pathinfo($filename, PATHINFO_FILENAME) . '.log';

    if (move_uploaded_file($fileTmpPath, $destination)) {
        // Deepgram: если нужный формат, отправляем на распознавание
        $ext = strtolower($extension);
        if (in_array($ext, ['webm', 'm4a', 'wav'])) {
            $lang = isset($_POST['lang']) ? $_POST['lang'] : 'en';
            $deepgramApiKey = 'e43a7ffd67ef05aa2d6565a68baf93ec0773d670';
            $deepgramUrl = 'https://api.deepgram.com/v1/listen?smart_format=true&punctuate=true&paragraphs=true&utterances=true&model=nova-2&language=' . $lang;

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $deepgramUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Token ' . $deepgramApiKey,
                'Content-Type: audio/' . ($ext === 'webm' ? 'webm' : ($ext === 'm4a' ? 'mp4' : 'wav'))
            ]);
            $audioData = file_get_contents($destination);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $audioData);
            $result = curl_exec($ch);
            $info = curl_getinfo($ch);
            $err = curl_error($ch);
            curl_close($ch);

            $deepgramResult = [
                'request_headers' => [
                    'Authorization' => 'Token ...',
                    'Content-Type' => 'audio/' . ($ext === 'webm' ? 'webm' : ($ext === 'm4a' ? 'mp4' : 'wav'))
                ],
                'url' => $deepgramUrl,
                'response_http_code' => $info['http_code'],
                'response_headers' => $info,
                'response_body' => $result,
                'curl_error' => $err,
            ];

            $txtFile = $targetDir . '/' . pathinfo($filename, PATHINFO_FILENAME) . '.txt';
            $text = '';
            if ($result) {
                $json = json_decode($result, true);
                if (isset($json['results']['channels'][0]['alternatives'][0]['transcript'])) {
                    $text = $json['results']['channels'][0]['alternatives'][0]['transcript'];
                    $response['success'] = true;
                } else {
                    $text = $result;
                    $response['success'] = false;
                    $response['error'] = 'No transcript';
                }
            } else {
                $text = 'Deepgram error';
                $response['success'] = false;
                $response['error'] = 'Deepgram error';
            }
            file_put_contents($txtFile, $text);
            // Пишем лог только если был Deepgram
            $response['file'] = $filename;
            $response['files'] = getFileList($targetDir);
            $logData['response'] = $response;
            $logData['deepgram'] = $deepgramResult;
            $logData['files'] = $response['files'];
            writeLog($logFile, $logData);
        } else {
            $response['success'] = true;
            $response['file'] = $filename;
        }
    } else {
        $response['error'] = 'Could not move file.';
    }
} else {
    // init-запрос или ошибка
    if (empty($_FILES)) {
        $response['success'] = true;
        $response['info'] = 'init';
    } else {
        $response['error'] = 'No file uploaded or error occurred.';
    }
}

$response['files'] = getFileList($targetDir);
// Лог пишем только если был Deepgram (см. выше)

echo json_encode($response);
