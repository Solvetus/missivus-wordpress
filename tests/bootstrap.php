<?php
/**
 * PHPUnit bootstrap: WordPress stubs + the plugin's own autoloader. No WordPress, no
 * Composer — the suite runs against the same code the zip ships.
 *
 * @package Missivus\Tests
 */

// phpcs:ignoreFile -- test bootstrap.

require_once __DIR__ . '/Support/WpStubs.php';
require_once dirname( __DIR__ ) . '/src/Autoloader.php';

Missivus\Autoloader::register();

require_once __DIR__ . '/Support/Doubles.php';
require_once __DIR__ . '/Support/MissivusTestCase.php';
