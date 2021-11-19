# Slam / flysystem-compress-and-encrypt-proxy

[![Latest Stable Version](https://img.shields.io/packagist/v/slam/flysystem-core-proxy.svg)](https://packagist.org/packages/slam/flysystem-core-proxy)
[![Downloads](https://img.shields.io/packagist/dt/slam/flysystem-core-proxy.svg)](https://packagist.org/packages/slam/flysystem-core-proxy)
[![Integrate](https://github.com/Slamdunk/flysystem-compress-and-encrypt-proxy/workflows/Integrate/badge.svg?branch=master)](https://github.com/Slamdunk/flysystem-compress-and-encrypt-proxy/actions)
[![Code Coverage](https://codecov.io/gh/Slamdunk/flysystem-compress-and-encrypt-proxy/coverage.svg?branch=master)](https://codecov.io/gh/Slamdunk/flysystem-compress-and-encrypt-proxy?branch=master)
[![Type Coverage](https://shepherd.dev/github/Slamdunk/flysystem-compress-and-encrypt-proxy/coverage.svg)](https://shepherd.dev/github/Slamdunk/flysystem-compress-and-encrypt-proxy)
[![Infection MSI](https://badge.stryker-mutator.io/github.com/Slamdunk/flysystem-compress-and-encrypt-proxy/master)](https://dashboard.stryker-mutator.io/reports/github.com/Slamdunk/flysystem-compress-and-encrypt-proxy/master)

Compress and Encrypt files and streams before saving them to the final Flysystem destination.

## Available packages

Use composer to install these available packages:

| Package name | Stream filter type | Adatper class |
|---|---|---|
|`slam/flysystem-v1encrypt-proxy`|[`XChaCha20-Poly1305`](https://www.php.net/manual/en/function.sodium-crypto-secretstream-xchacha20poly1305-init-push.php) encryption|`V1EncryptProxyAdapter`|
|`slam/flysystem-gzip-proxy`|[`Gzip`](https://datatracker.ietf.org/doc/html/rfc1952) compression|`GzipProxyAdapter`|
|`slam/flysystem-zip-proxy`|[`Zip`](https://pkware.cachefly.net/webdocs/casestudies/APPNOTE.TXT) compression|`ZipProxyAdapter`|

## Usage

```php
use SlamFlysystem\Gzip\GzipProxyAdapter;
use SlamFlysystem\V1Encrypt\V1EncryptProxyAdapter;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;

// Create a strong key and save it somewhere
$key = V1EncryptProxyAdapter::generateKey();

// Create the final FilesystemAdapter, for example Aws S3
$remoteAdapter = new AwsS3V3Adapter(/* ... */);

$adapter = new GzipProxyAdapter(new V1EncryptProxyAdapter(
    $remoteAdapter,
    $key
));

// The FilesystemOperator
$filesystem = new \League\Flysystem\Filesystem($adapter);

// Upload a file, with stream
$handle = fopen('my-huge-file.txt', 'r');
$filesystem->writeStream('data.txt', $handle);
fclose($handle);

// Remotely a data.txt.gz.encrypted file has now been created

// Download a file, with stream
$handle = $filesystem->readStream('data.txt');
file_put_contents('my-huge-file.txt', $handle);
fclose($handle);
```

## Streams

Both write and read operations leverage streams to keep memory usage low.

A 10 Gb `mysqldump` output can be streamed into a 1 Gb `dump.sql.gz.encrypted` file
with a 10 Mb RAM footprint of the running php process, and no additional local disk
space required.

## Why is encryption proxy Versioned?

Security is a moving target and we need to make space for future, more secure,
protocols.

No name and no configurations are intentional: [cipher agility is bad](https://paragonie.com/blog/2019/10/against-agility-in-cryptography-protocols).

The first time you use an encryption stream, you should use only the latest one.
If you are already using an encryption stream and a new version is released,
you are invited to re-encrypt all your assets with the new version.

## Why yet another Zip package?

This one combines Flysystem with a [`php_user_filter`](https://www.php.net/manual/en/class.php-user-filter.php), which allows
compression without knowing the source nor the destination of the stream.

1. PHP's [`ZipArchive`](https://www.php.net/manual/en/class.ziparchive.php) doesn't support streams for write, and for read
operations only supports a reading stream after you already saved a local copy of the archive
2. Flysystem's [`ZipArchive`](https://github.com/thephpleague/flysystem/tree/2.3.1/src/ZipArchive) acts as a big final bucket;
here instead we transparently zip content from the source to the final bucket, per file.
3. @maennchen [`ZipStream-PHP`](https://github.com/maennchen/ZipStream-PHP), which is awesome, can stream to Flysystem only
_after_ the whole archive is written somewhere, see https://github.com/maennchen/ZipStream-PHP/wiki/FlySystem-example; you
can stream the zip to S3, but not with Flysystem: https://github.com/maennchen/ZipStream-PHP/wiki/Stream-S3

The Zip proxy wasn't possible without copying what we needed from https://github.com/maennchen/ZipStream-PHP, so I strongly recommend
supporting that package financially if you like theirs or our package for Zip compression.

## Caveats

### MIME types detection

Some Flysystem adapters like the Local one try to guess the file mime type by
its nature (content or extension): in such cases it will fail due to the custom
extention and the encrypted content.
Other adapters like the Aws S3 one allow you to specify it manually (for ex.
with the `ContentType` key in the Config): it is a good idea to always manually
inject it, if you like the `Filesystem::mimeType($path)` call to be reliable.

### File size

The file size returned relates to the compressed and encrypted file, not the
original one.
