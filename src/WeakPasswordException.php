<?php

declare(strict_types=1);

namespace SlamFlysystemEncryptedZipProxy;

use RuntimeException;

final class WeakPasswordException extends RuntimeException implements EncryptedZipProxyException
{
}
