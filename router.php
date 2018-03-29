<?php

if (isset($_SERVER['DEVHELPER_PHP_APACHE_VERSION_ID'])) {
    $versionExpected = '2018032901';
    $versionActual = $_SERVER['DEVHELPER_PHP_APACHE_VERSION_ID'];
    if ($versionActual !== $versionExpected) {
        die(sprintf('Please rebuild Docker image. Expected %s, actual %s', $versionExpected, $versionActual));
    }
}

require('./library/DevHelper/Router.php');
DevHelper_Router::route(__FILE__);
