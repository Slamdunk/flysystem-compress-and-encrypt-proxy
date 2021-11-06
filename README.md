# Slam / flysystem-compress-and-encrypt-proxy

[![Latest Stable Version](https://img.shields.io/packagist/v/slam/flysystem-compress-and-encrypt-proxy.svg)](https://packagist.org/packages/slam/flysystem-compress-and-encrypt-proxy)
[![Downloads](https://img.shields.io/packagist/dt/slam/flysystem-compress-and-encrypt-proxy.svg)](https://packagist.org/packages/slam/flysystem-compress-and-encrypt-proxy)
[![Integrate](https://github.com/Slamdunk/flysystem-compress-and-encrypt-proxy/workflows/Integrate/badge.svg?branch=master)](https://github.com/Slamdunk/flysystem-compress-and-encrypt-proxy/actions)
[![Code Coverage](https://codecov.io/gh/Slamdunk/flysystem-compress-and-encrypt-proxy/coverage.svg?branch=master)](https://codecov.io/gh/Slamdunk/flysystem-compress-and-encrypt-proxy?branch=master)
[![Type Coverage](https://shepherd.dev/github/Slamdunk/flysystem-compress-and-encrypt-proxy/coverage.svg)](https://shepherd.dev/github/Slamdunk/flysystem-compress-and-encrypt-proxy)
[![Infection MSI](https://badge.stryker-mutator.io/github.com/Slamdunk/flysystem-compress-and-encrypt-proxy/master)](https://dashboard.stryker-mutator.io/reports/github.com/Slamdunk/flysystem-compress-and-encrypt-proxy/master)

Compress and Encrypt files and streams before saving them to the final Flysystem destination.

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

// Create the final FilesystemAdapter, for example Aws S3
$remoteAdapter = new AwsS3V3Adapter(/* ... */);

$adapter = new CompressAndEncryptAdapter(
    $remoteAdapter,
    $key
);

// The FilesystemOperator
$filesystem = new \League\Flysystem\Filesystem($adapter);

// Upload a file, with stream
$handle = fopen('my-huge-file.txt', 'r');
$filesystem->writeStream('data.txt', $handle);
fclose($handle);

// Remotely a data.txt.gz.encrypted file has now been created

// Download a file, with stream
$handle = $filesystem->writeStream('data.txt');
file_put_contents('my-huge-file.txt', $handle);
fclose($handle);
```

## Streams

Both write and read operations leverage streams to keep memory usage low.

A 10 Gb `mysqldump` output can be streamed into a 1 Gb `dump.sql.gz.encrypted` file
with a 10 Mb RAM footprint of the running php process, and no additional local disk
space required.

## Compression

GZip's `zlib.deflate` and `zlib.inflate` compression filters are used.

## Encryption

[Sodium](https://www.php.net/manual/en/book.sodium.php) extension provides the backend for the
encrypted stream with [`XChaCha20-Poly1305`](https://www.php.net/manual/en/function.sodium-crypto-secretstream-xchacha20poly1305-init-push.php) algorithm.

## MIME types detection caveat

Some Flysystem adapters like the Local one try to guess the file mime type by
its nature (content or extension): in such cases it will fail due to the custom
extention and the encrypted content.
Other adapters like the Aws S3 one allow you to specify it manually (for ex.
with the `ContentType` key in the Config): it is a good idea to always manually
inject it, if you like the `Filesystem::mimeType($path)` call to be reliable.
