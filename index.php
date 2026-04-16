<?php

$userCheckApiKey = 'prd_xxxxxxxxxxxxxxxxx';
$whiteListFile = __DIR__ . '/data/white.list';
$blackListFile = __DIR__ . '/data/black.list';

function getDomainFromEmail($email)
{
    $parts = explode('@', $email);
    if (count($parts) !== 2) {
        return null;
    }
    return strtolower(trim($parts[1]));
}

function isDomainInList($domain, $listFile)
{
    if (!file_exists($listFile)) {
        return false;
    }

    $domains = file($listFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return in_array(strtolower(trim($domain)), array_map('strtolower', array_map('trim', $domains)));
}

function appendDomainToList($domain, $listFile)
{
    $domain = strtolower(trim($domain));

    if (isDomainInList($domain, $listFile)) {
        return true;
    }


    if (file_put_contents($listFile, $domain . PHP_EOL, FILE_APPEND)) {
        return true;
    }

    return false;
}

function checkIfDisposable($domain, $apiKey)
{
    $url = 'https://api.usercheck.com/domain/' . urlencode($domain);

    $options = [
        'http' => [
            'method' => 'GET',
            'header' => 'Authorization: Bearer ' . $apiKey . "\r\n",
            'timeout' => 10
        ]
    ];

    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        throw new \Exception('API call failed');
    }

    $data = json_decode($response, true);
    return $data;
}

function checkEmailDomain($email)
{
    global $userCheckApiKey, $whiteListFile, $blackListFile;

    $domain = getDomainFromEmail($email);

    if ($domain === null) {
        throw new \Exception('Invalid email');
    }

    if (isDomainInList($domain, $whiteListFile)) {
        return true;
    }

    if (isDomainInList($domain, $blackListFile)) {
        throw new \Exception('Blacklisted email');
    }

    $apiResponse = checkIfDisposable($domain, $userCheckApiKey);
    $isDisposable = isset($apiResponse['disposable']) ? $apiResponse['disposable'] : false;

    if ($isDisposable) {
        appendDomainToList($domain, $blackListFile);

        throw new \Exception('Blacklisted email');
    }

    appendDomainToList($domain, $whiteListFile);

    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $email = $_POST['email'] ?? null;

    if (!$email) {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Email parameter is required'
        ]);
        exit;
    }

    try {
        checkEmailDomain($email);
    } catch (\Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }

    echo json_encode(['success' => true, 'message' => '']);
}
