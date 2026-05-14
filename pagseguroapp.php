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
$emailsApi = $api->newApi('emails', $auth, $apiUrl);
$raw_post = file_get_contents('php://input');

//POST Data
$name = trim($_POST['name']);
$email = trim($_POST['email']);
$publickey = $_POST['publickey'];
$url = isset($_POST['url']) ? $_POST['url'] : '';


$firstname = $name;
$lastname = '';
$names = explode(' ', $name);
if ($names && count($names) > 1) {
    $firstname = trim($names[0]);
    $lastname = trim($names[count($names)-1]);
}

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
               'publickey' => $publickey,
               'website'   => $url,
               'tags'      => array_merge(['pagseguro-app'], $currentTags)
];
if (!$firstname) {
    unset($parameters['firstname']);
}
if (!$lastname) {
    unset($parameters['lastname']);
}


$result = $contactsApi->edit($mauticCustomerId, $parameters, true);

if (!$result || isset($result['errors'])) {
    header(var_export($result, true), true, 503);
    exit;
}


$contact = $result['contact'];
if (false === array_search('pagseguro-app', $currentTags) && $contact) {
    $mauticCustomerId = $contact['id'];
    $pointInfo = ['eventName' => 'PagSeguro APP Authorized', 'actionName' => 'Adding'];
    $contactsApi->addPoints($mauticCustomerId, $points+20, $pointInfo);
}
$contactsApi->removeDnc($mauticCustomerId, 'email');
$emailsApi->sendToContact(76, $mauticCustomerId);