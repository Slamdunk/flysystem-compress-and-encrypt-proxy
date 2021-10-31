<?php

$config = new PhpCsFixer\Config();
$config->setRiskyAllowed(true);
$config->setRules([
    '@PhpCsFixer' => true,
    '@PhpCsFixer:risky' => true,
    '@PHPUnit84Migration:risky' => true,
]);
$config->getFinder()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/test')
;

return $config;
