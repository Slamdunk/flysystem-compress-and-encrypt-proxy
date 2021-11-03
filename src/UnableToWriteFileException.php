<?php

declare(strict_types=1);

namespace SlamFlysystemEncryptedZipProxy;

use RuntimeException;

final class UnableToWriteFileException extends RuntimeException implements EncryptedZipProxyException
{
}
