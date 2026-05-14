<?php
$logDir = '/home/adf470bf/webhooks.magenteiro.com/html/logs/';


$homologa = shell_exec('cd /home/adf470bf/homologa.magenteiro.com/html; git fetch origin; git reset --hard origin/homologa');

file_put_contents($logDir . 'build.log', sprintf("HOMOLOGA FEITO EM %s: \n %s \n \n", date('d/m/Y H:i:s'), $homologa), FILE_APPEND);


$prod = shell_exec('cd /home/adf470bf/magenteiro.com/html; git fetch origin; git reset --hard origin/master');

file_put_contents($logDir . 'build.log', sprintf("PROD FEITO EM %s: \n %s \n \n", date('d/m/Y H:i:s'), $prod), FILE_APPEND);


header("HTTP/1.1 200 OK");
