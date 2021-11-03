<?php

declare(strict_types=1);

namespace SlamFlysystemEncryptedZipProxy;

use RuntimeException;

final class UnableToWriteToDirectoryException extends RuntimeException implements EncryptedZipProxyException
{
}
