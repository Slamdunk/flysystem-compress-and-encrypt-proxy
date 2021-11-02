<?php

$config = new PhpCsFixer\Config();
$config->setRiskyAllowed(true);
$config->setRules([
    '@PhpCsFixer' => true,
    '@PhpCsFixer:risky' => true,
    '@PHPUnit84Migration:risky' => true,
    'php_unit_test_annotation' => ['style' => 'annotation'],
    'php_unit_method_casing' => ['case' => 'snake_case'],
]);
$config->getFinder()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/test')
;

return $config;
