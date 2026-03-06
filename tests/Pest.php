<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "Storvia\Vantage\Tests\TestCase". You may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

use Storvia\Vantage\Tests\CustomRoutePrefixTestCase;
use Storvia\Vantage\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');
uses(CustomRoutePrefixTestCase::class)->in('RoutePrefixTest.php');
