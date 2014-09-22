#!/usr/bin/env php
<?php

/**
 * @file
 * The main entry point, passes everything over to the Ektu class.
 */

$autoload = __dir__ . '/vendor/autoload.php';

// CHeck that the dependencies have been installed.
if (!file_exists($autoload)) {
  echo " - Could not load Ektu classes. Please install composer, run 'composer install' and try again.\n\n";
  die();
}

// Require all dependencies.
require_once $autoload;

$args = new Commando\command();

// Instantiate our Ektu class with our arguments.
$ektu = new Ektu\Ektu($args, __dir__);
