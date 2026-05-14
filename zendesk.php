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
$customer = json_decode($raw_post);
$customer = (array)$customer;

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
$email = isset($customer['email']) && !empty($customer['email']) ? $customer['email'] : null;
$mauticCustomerId = 0;

if (empty($email)) {
    file_put_contents($logFile, date('d/m/Y H:i:s') . ' - Email is empty.' . PHP_EOL, FILE_APPEND);
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


