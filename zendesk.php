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
$raw_post = file_get_contents('php://input');
$customer = json_decode($raw_post);
$customer = (array)$customer;

$firstname = isset($customer['firstname']) && !empty($customer['firstname']) ? $customer['firstname'] : null;
$lastname = isset($customer['lastname']) && !empty($customer['lastname']) ? $customer['lastname'] : null;
$email = isset($customer['email']) && !empty($customer['email']) ? $customer['email'] : null;
$mauticCustomerId = 0;

$result = $contactsApi->getList('email:'.$email, 0, 1, 'date_modified', 'DESC');
if(empty($email)) {
    header('Email is empty.', true, 503);
    exit;
}
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
               'tags'      => array_merge(['pagseguro-zendesk'], $currentTags)
];

$result = $contactsApi->edit($mauticCustomerId, $parameters, true);

if (!$result || isset($result['errors'])) {
    header(var_export($result, true), true, 503);
    exit;
}


$contact = $result['contact'];
if (false === array_search('pagseguro-zendesk', $currentTags) && $contact) {
    $mauticContactId = $contact['id'];
    $pointInfo = ['eventName' => 'New Zendesk Lead', 'actionName' => 'Adding'];
    $contactsApi->addPoints($mauticContactId, $points+10, $pointInfo);
}