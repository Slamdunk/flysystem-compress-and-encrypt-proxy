<?php

declare(strict_types=1);

namespace SlamFlysystemSingleEncryptedZipArchive;

use RuntimeException;

final class UnableToWriteToDirectoryException extends RuntimeException implements SingleEncryptedZipArchiveException
{
}
