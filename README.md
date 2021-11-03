# Slam / flysystem-encrypted-zip-proxy

[![Latest Stable Version](https://img.shields.io/packagist/v/slam/flysystem-encrypted-zip-proxy.svg)](https://packagist.org/packages/slam/flysystem-encrypted-zip-proxy)
[![Downloads](https://img.shields.io/packagist/dt/slam/flysystem-encrypted-zip-proxy.svg)](https://packagist.org/packages/slam/flysystem-encrypted-zip-proxy)
[![Integrate](https://github.com/Slamdunk/flysystem-encrypted-zip-proxy/workflows/Integrate/badge.svg?branch=master)](https://github.com/Slamdunk/flysystem-encrypted-zip-proxy/actions)
[![Code Coverage](https://codecov.io/gh/Slamdunk/flysystem-encrypted-zip-proxy/coverage.svg?branch=master)](https://codecov.io/gh/Slamdunk/flysystem-encrypted-zip-proxy?branch=master)
[![Type Coverage](https://shepherd.dev/github/Slamdunk/flysystem-encrypted-zip-proxy/coverage.svg)](https://shepherd.dev/github/Slamdunk/flysystem-encrypted-zip-proxy)
[![Infection MSI](https://badge.stryker-mutator.io/github.com/Slamdunk/flysystem-encrypted-zip-proxy/master)](https://dashboard.stryker-mutator.io/reports/github.com/Slamdunk/flysystem-encrypted-zip-proxy/master)

Zip and Encrypt files before saving them to the final Flysystem destination.

## Installation

To install with composer run the following command:

```console
$ composer require slam/flysystem-encrypted-zip-proxy
```

## Usage

```php

use SlamFlysystemEncryptedZipProxy\EncryptedZipProxyAdapter;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;

// Create a strong password and save it somewhere
$password = EncryptedZipProxyAdapter::generateKey();

// Create the final FilesystemAdapter
$remoteAdapter = new AwsS3V3Adapter(/* ... */);

// A local writable directory is necessary to create the encrypted ZIP
// before sending it
$localWorkingDirectory = sys_get_temp_dir();

$adapter = new EncryptedZipProxyAdapter(
    $remoteAdapter,
    $password,
    $localWorkingDirectory
);

// The FilesystemOperator
$filesystem = new \League\Flysystem\Filesystem($adapter);

// The actual operation: a data.txt.zip file is created and encrypted
// before beeing sent to Aws
$filesystem->write('data.txt', 'My secret');
```

## Security & Usability

The encryption protocol used is the standard Zip with AES-256 as described here:
https://www.winzip.com/en/support/aes-encryption/

It has been choosen because it best fits all the following goals:

1. Strength: `AES-256-CTR`, `PBKDF2-HMAC-SHA1` and `HMAC-SHA1-80` algorithms for
   respectively encryption, key derivation and authentication are old but still
   good enough.
1. Ease of use: PHP's ZipArchive provides both compression and encryption with
   a simple and user-friendly API
1. Availability: ZIP-AES is supported by almost every zip-related tool, so if you
   get stuck and have to recover the plain files outside PHP, you have plenty
   of options to do it easily and quickly

## Difference with FlySystem ZipArchive

[`flysystem-ziparchive`](https://github.com/thephpleague/flysystem-ziparchive/tree/2.x)
stores everything into a single big ZIP file. This adapter instead creates
a Zip for every file you write/upload to the actual adapter.
