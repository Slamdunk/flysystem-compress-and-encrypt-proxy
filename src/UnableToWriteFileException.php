<?php

declare(strict_types=1);

namespace SlamFlysystemSingleEncryptedZipArchive;

use RuntimeException;

final class UnableToWriteFileException extends RuntimeException implements SingleEncryptedZipArchiveException
{
}
