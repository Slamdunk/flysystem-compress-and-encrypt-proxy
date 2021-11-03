<?php

declare(strict_types=1);

namespace SlamFlysystemSingleEncryptedZipArchive;

use RuntimeException;

final class WeakPasswordException extends RuntimeException implements SingleEncryptedZipArchiveException
{
}
