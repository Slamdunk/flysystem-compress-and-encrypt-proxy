<?php

declare(strict_types=1);

namespace SlamFlysystemSingleEncryptedZipArchive;

use RuntimeException;

final class UnsupportedOperationException extends RuntimeException implements SingleEncryptedZipArchiveException
{
}
