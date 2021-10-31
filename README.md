# Slam / php-symmetric-encryption

[![Latest Stable Version](https://img.shields.io/packagist/v/slam/php-symmetric-encryption.svg)](https://packagist.org/packages/slam/php-symmetric-encryption)
[![Downloads](https://img.shields.io/packagist/dt/slam/php-symmetric-encryption.svg)](https://packagist.org/packages/slam/php-symmetric-encryption)
[![Integrate](https://github.com/Slamdunk/php-symmetric-encryption/workflows/Integrate/badge.svg?branch=master)](https://github.com/Slamdunk/php-symmetric-encryption/actions)
[![Code Coverage](https://codecov.io/gh/Slamdunk/php-symmetric-encryption/coverage.svg?branch=master)](https://codecov.io/gh/Slamdunk/php-symmetric-encryption?branch=master)
[![Type Coverage](https://shepherd.dev/github/Slamdunk/php-symmetric-encryption/coverage.svg)](https://shepherd.dev/github/Slamdunk/php-symmetric-encryption)
[![Infection MSI](https://badge.stryker-mutator.io/github.com/Slamdunk/php-symmetric-encryption/master)](https://dashboard.stryker-mutator.io/reports/github.com/Slamdunk/php-symmetric-encryption/master)

V1: encrypt strings with [`sodium_crypto_aead_xchacha20poly1305_ietf_encrypt`](https://www.php.net/manual/en/function.sodium-crypto-aead-xchacha20poly1305-ietf-encrypt.php) function.

## Installation

To install with composer run the following command:

```console
$ composer require slam/php-symmetric-encryption
```

## Usage

```php
use SlamSymmetricEncryption\V1Encryptor;

// Generate a key and save it somewhere
$key = V1Encryptor::generateKey();
var_dump($key); // string(44) "Hog2u9jtOzyt+mPyAJwp8v3dI6Uvp1T4FUKrAjizVGo="

// Use the key
$encryptor = new V1Encryptor($key);

$ciphertext = $encryptor->encrypt('foo');
var_dump($ciphertext); // string(59) "dznmjbqHnI_26crKpRYvp125K9N6ctqU.0kVCmoSRbG7HAKCIrnAz0RBELQ"

$plaintext = $encryptor->decrypt($ciphertext);
var_dump($plaintext); // string(3) "foo"
```
