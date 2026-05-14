<?php
ini_set('display_errors', '-1');

// Bootup the Composer autoloader
include __DIR__ . '/vendor/autoload.php';

use Mautic\Auth\ApiAuth;
use Mautic\MauticApi;

session_start();

require __DIR__ . '/mautic-config.php';

$logFile = __DIR__ . '/logs/zendesk.log';

// Initiate the auth object specifying to use BasicAuth
$initAuth = new ApiAuth();
$auth = $initAuth->newAuth($settings, 'BasicAuth');

$api = new MauticApi();
$contactsApi = $api->newApi('contacts', $auth, $apiUrl);
$raw_post = file_get_contents('php://input');
if (!is_string($raw_post)) {
    $raw_post = '';
}
$raw_post = trim($raw_post);
if (strncmp($raw_post, "\xEF\xBB\xBF", 3) === 0) {
    $raw_post = substr($raw_post, 3);
}
$parsed = json_decode($raw_post, true);
$customer = (is_array($parsed) && json_last_error() === JSON_ERROR_NONE) ? $parsed : array();
$postFields = array_intersect_key($_POST, array_flip(array('firstname', 'lastname', 'email')));
$customer = array_merge($postFields, $customer);
$normalized = array();
foreach ($customer as $key => $value) {
    if (is_string($key)) {
        $normalized[strtolower($key)] = $value;
    } else {
        $normalized[$key] = $value;
    }
}
$customer = $normalized;

$firstname = null;
if (isset($customer['firstname']) && $customer['firstname'] !== '' && $customer['firstname'] !== null) {
    $t = trim(preg_replace('/\s+/u', ' ', (string) $customer['firstname']));
    $parts = preg_split('/\s+/u', $t, -1, PREG_SPLIT_NO_EMPTY);
    if ($parts) {
        $token = $parts[0];
        $l = mb_strtolower($token, 'UTF-8');
        $firstname = mb_strtoupper(mb_substr($l, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($l, 1, null, 'UTF-8');
    }
}
$lastname = null;
if (isset($customer['lastname']) && $customer['lastname'] !== '' && $customer['lastname'] !== null) {
    $t = trim(preg_replace('/\s+/u', ' ', (string) $customer['lastname']));
    $parts = preg_split('/\s+/u', $t, -1, PREG_SPLIT_NO_EMPTY);
    if ($parts) {
        $token = $parts[count($parts) - 1];
        $l = mb_strtolower($token, 'UTF-8');
        $lastname = mb_strtoupper(mb_substr($l, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($l, 1, null, 'UTF-8');
    }
}
$email = null;
if (isset($customer['email'])) {
    $e = trim(is_string($customer['email']) ? $customer['email'] : (string) $customer['email']);
    if ($e !== '') {
        $email = $e;
    }
}
$mauticCustomerId = 0;

if (empty($email)) {
    file_put_contents(
        $logFile,
        date('d/m/Y H:i:s')
        . ' - Email is empty. json_error=' . json_last_error_msg()
        . ' body_len=' . strlen($raw_post)
        . PHP_EOL,
        FILE_APPEND
    );
    header('Email is empty.', true, 503);
    exit;
}

$listResult = $contactsApi->getList('email:'.$email, 0, 1, 'date_modified', 'DESC');
if (isset($listResult['errors'])) {
    file_put_contents($logFile, date('d/m/Y H:i:s') . ' - getList: ' . var_export($listResult['errors'], true) . PHP_EOL, FILE_APPEND);
    header('Mautic error.', true, 503);
    exit;
}
$currentTags = array();
$points = 0;
if ($listResult['total']) {
    reset($listResult['contacts']);
    $mauticCustomerId =  key($listResult['contacts']);
    $tags = reset($listResult['contacts'])['tags'];
    $points = reset($listResult['contacts'])['points'];
    foreach ($tags as $k => $v) {
        $currentTags[] = $tags[$k]['tag'];
    }

}


$parameters = ['firstname' => $firstname, 'lastname' => $lastname,
               'email'     => $email,
               'tags'      => array_merge(['zendesk-ticket'], $currentTags)
];

$result = $contactsApi->edit($mauticCustomerId, $parameters, true);

if (!$result || isset($result['errors'])) {
    $errDetail = (is_array($result) && isset($result['errors'])) ? var_export($result['errors'], true) : var_export($result, true);
    file_put_contents($logFile, date('d/m/Y H:i:s') . ' - edit contact failed: ' . $errDetail . PHP_EOL, FILE_APPEND);
    header(var_export($result, true), true, 503);
    exit;
}


