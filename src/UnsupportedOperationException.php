<?php

declare(strict_types=1);

namespace SlamFlysystemEncryptedZipProxy;

use RuntimeException;

final class UnsupportedOperationException extends RuntimeException implements EncryptedZipProxyException
{
}
