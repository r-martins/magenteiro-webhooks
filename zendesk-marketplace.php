<?php
ini_set('display_errors', '-1');

// Bootup the Composer autoloader
include __DIR__ . '/vendor/autoload.php';

use Mautic\Auth\ApiAuth;
use Mautic\MauticApi;

session_start();

require __DIR__ . '/mautic-config.php';

// Initiate the auth object specifying to use BasicAuth
$initAuth = new ApiAuth();
$auth = $initAuth->newAuth($settings, 'BasicAuth');

$api = new MauticApi();
$contactsApi = $api->newApi('contacts', $auth, $apiUrl);
$rawPost = file_get_contents('php://input');
$post = json_decode($rawPost);
$message = $post->message;
$logFile = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'zendesk-marketplace.log';


preg_match_all('/Name ([A-Za-z\ ]+) Date/', $message, $matches, PREG_PATTERN_ORDER);
$fullName = isset($matches[1][0])?$matches[1][0]:'';
list($firstname, $lastname) = explode(" ", $fullName);
$firstname = ucfirst(mb_convert_case($firstname, MB_CASE_LOWER));
$lastname = ucfirst(mb_convert_case($lastname, MB_CASE_LOWER));

preg_match_all('/([a-z0-9_\.\-])+\@(([a-z0-9\-])+\.)+([a-z0-9]{2,4})+/i', $message, $matches);
$email = isset($matches[0][0])?mb_convert_case($matches[0][0], MB_CASE_LOWER):'';
$email = trim($email);

if (empty($email)) {
    $errorMsg = date('d/m/y H:i:s') . ' - EMAIL VAZIO PARA A MENSAGEM: ' . PHP_EOL . $message . PHP_EOL;
    file_put_contents($logFile, $errorMsg, FILE_APPEND);
}
//file_put_contents(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR  . 'zendesk-marketplace.log', var_export($debug, true) . PHP_EOL . '˜˜˜˜˜˜˜˜˜˜' . PHP_EOL, FILE_APPEND);


$mauticCustomerId = 0;

$result = $contactsApi->getList('email:'.$email, 0, 1, 'date_modified', 'DESC');
$currentTags = array();
$points = 0;
if ($result['total']) {
    reset($result['contacts']);
    $mauticCustomerId =  key($result['contacts']);
    $tags = reset($result['contacts'])['tags'];
    $points = reset($result['contacts'])['points'];
    foreach ($tags as $k => $v) {
        $currentTags[] = $tags[$k]['tag'];
    }

}


$parameters = ['firstname' => $firstname, 'lastname' => $lastname,
               'email'     => $email,
               'tags'      => array_merge(['pagseguro-marketplace'], $currentTags)
];

$result = $contactsApi->edit($mauticCustomerId, $parameters, true);

if (!$result || isset($result['errors'])) {
    header(var_export($result, true), true, 503);
    $errorMsg = date('d/m/Y H:i:s - ' . 'Deu ruim: ' . var_export($result['errors'], true) . PHP_EOL);
    file_put_contents($logFile, $errorMsg, FILE_APPEND);
    exit;
}
