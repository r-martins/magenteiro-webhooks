<?php
ini_set('display_errors', '-1');

// Capture raw body first: php://input is a single-read stream and may be empty after redirects
// (e.g. curl --location on 301/302 often resends as GET without a body).
$raw_post = file_get_contents('php://input');
if (!is_string($raw_post)) {
    $raw_post = '';
}
// Some stacks leave php://input empty but still populate $_POST (e.g. webhook as
// application/x-www-form-urlencoded or multipart with a text field holding JSON).
if ($raw_post === '') {
    if (isset($_POST['payload']) && is_string($_POST['payload']) && $_POST['payload'] !== '') {
        $raw_post = $_POST['payload'];
    } elseif (isset($_POST['json']) && is_string($_POST['json']) && $_POST['json'] !== '') {
        $raw_post = $_POST['json'];
    }
}

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
$raw_post = trim($raw_post);
if (strncmp($raw_post, "\xEF\xBB\xBF", 3) === 0) {
    $raw_post = substr($raw_post, 3);
}
if ($raw_post === '') {
    $customer = array();
} else {
    $parsed = json_decode($raw_post, true);
    $customer = (is_array($parsed) && json_last_error() === JSON_ERROR_NONE) ? $parsed : array();
}
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
$email = null;
if (isset($customer['email'])) {
    $e = trim(is_string($customer['email']) ? $customer['email'] : (string) $customer['email']);
    if ($e !== '') {
        $email = $e;
    }
}
$mauticCustomerId = 0;

if (empty($email)) {
    $jsonErr = ($raw_post === '') ? 'n/a (empty body)' : json_last_error_msg();
    $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';
    $ctype = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
    $clen = isset($_SERVER['CONTENT_LENGTH']) ? $_SERVER['CONTENT_LENGTH'] : '';
    file_put_contents(
        $logFile,
        date('d/m/Y H:i:s')
        . ' - Email is empty. json_error=' . $jsonErr
        . ' body_len=' . strlen($raw_post)
        . ' method=' . $method
        . ' CONTENT_TYPE=' . $ctype
        . ' CONTENT_LENGTH=' . $clen
        . (strlen($raw_post) === 0 ? ' hint=form_post_field_payload_or_json_with_JSON_string_or_fields_firstname_lastname_email' : '')
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
if ($listResult['total']) {
    reset($listResult['contacts']);
    $mauticCustomerId =  key($listResult['contacts']);
    $tags = reset($listResult['contacts'])['tags'];
    foreach ($tags as $k => $v) {
        $currentTags[] = $tags[$k]['tag'];
    }

}


$parameters = array(
    'firstname' => $firstname,
    'email'     => $email,
    'tags'      => array_merge(array('zendesk-ticket'), $currentTags),
);

$result = $contactsApi->edit($mauticCustomerId, $parameters, true);

if (!$result || isset($result['errors'])) {
    $errDetail = (is_array($result) && isset($result['errors'])) ? var_export($result['errors'], true) : var_export($result, true);
    file_put_contents($logFile, date('d/m/Y H:i:s') . ' - edit contact failed: ' . $errDetail . PHP_EOL, FILE_APPEND);
    header(var_export($result, true), true, 503);
    exit;
}

// Mautic segments: 8=updates, 9=segredos, 10=minha-jornada
$zendeskSegmentIds = array(8, 9, 10);
$contactRow = isset($result['contact']) && is_array($result['contact']) ? $result['contact'] : array();
$contactId = isset($contactRow['id']) ? (int) $contactRow['id'] : 0;
if ($contactId > 0) {
    $segmentsApi = $api->newApi('segments', $auth, $apiUrl);
    foreach ($zendeskSegmentIds as $segmentId) {
        $segResult = $segmentsApi->addContact($segmentId, $contactId);
        if (!$segResult || (is_array($segResult) && isset($segResult['errors']))) {
            $errDetail = (is_array($segResult) && isset($segResult['errors'])) ? var_export($segResult['errors'], true) : var_export($segResult, true);
            file_put_contents(
                $logFile,
                date('d/m/Y H:i:s') . ' - addContact to segment ' . $segmentId . ' failed: ' . $errDetail . PHP_EOL,
                FILE_APPEND
            );
        }
    }
}

