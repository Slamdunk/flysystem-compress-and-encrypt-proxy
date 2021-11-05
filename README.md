# Slam / flysystem-compress-and-encrypt-proxy

[![Latest Stable Version](https://img.shields.io/packagist/v/slam/flysystem-compress-and-encrypt-proxy.svg)](https://packagist.org/packages/slam/flysystem-compress-and-encrypt-proxy)
[![Downloads](https://img.shields.io/packagist/dt/slam/flysystem-compress-and-encrypt-proxy.svg)](https://packagist.org/packages/slam/flysystem-compress-and-encrypt-proxy)
[![Integrate](https://github.com/Slamdunk/flysystem-compress-and-encrypt-proxy/workflows/Integrate/badge.svg?branch=master)](https://github.com/Slamdunk/flysystem-compress-and-encrypt-proxy/actions)
[![Code Coverage](https://codecov.io/gh/Slamdunk/flysystem-compress-and-encrypt-proxy/coverage.svg?branch=master)](https://codecov.io/gh/Slamdunk/flysystem-compress-and-encrypt-proxy?branch=master)
[![Type Coverage](https://shepherd.dev/github/Slamdunk/flysystem-compress-and-encrypt-proxy/coverage.svg)](https://shepherd.dev/github/Slamdunk/flysystem-compress-and-encrypt-proxy)
[![Infection MSI](https://badge.stryker-mutator.io/github.com/Slamdunk/flysystem-compress-and-encrypt-proxy/master)](https://dashboard.stryker-mutator.io/reports/github.com/Slamdunk/flysystem-compress-and-encrypt-proxy/master)

Zip and Encrypt files before saving them to the final Flysystem destination.

## Installation

To install with composer run the following command:

```console
$ composer require slam/flysystem-compress-and-encrypt-proxy
```

## Usage

```php

use SlamCompressAndEncryptProxy\CompressAndEncryptAdapter;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;

// Create a strong key and save it somewhere
$key = EncryptedZipProxyAdapter::generateKey();

// Create the final FilesystemAdapter
$remoteAdapter = new AwsS3V3Adapter(/* ... */);

$adapter = new CompressAndEncryptAdapter(
    $remoteAdapter,
    $key
);

// The FilesystemOperator
$filesystem = new \League\Flysystem\Filesystem($adapter);

$handle = fopen('my-huge-file.txt', 'r');
$filesystem->writeStream('data.txt', $handle);
fclose($handle);
```
