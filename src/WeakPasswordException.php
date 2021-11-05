<?php

declare(strict_types=1);

namespace SlamCompressAndEncryptProxy;

use RuntimeException;

final class WeakPasswordException extends RuntimeException implements EncryptedZipProxyException
{
}
