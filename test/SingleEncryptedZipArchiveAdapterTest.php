<?php

declare(strict_types=1);

namespace SlamFlysystemSingleEncryptedZipArchiveTest;

use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\Config;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use SlamFlysystemSingleEncryptedZipArchive\SingleEncryptedZipArchiveAdapter;
use SlamFlysystemSingleEncryptedZipArchive\UnableToWriteToDirectoryException;
use ZipArchive;

/**
 * @covers \SlamFlysystemSingleEncryptedZipArchive\SingleEncryptedZipArchiveAdapter
 *
 * @internal
 */
final class SingleEncryptedZipArchiveAdapterTest extends FilesystemAdapterTestCase
{
    private const REMOTE_MOCK = __DIR__.'/assets/remote-mock';
    private const LOCAL_WORKDIR = __DIR__.'/assets/local-workdir';

    private static string $zipPassword;

    protected function setUp(): void
    {
        reset_function_mocks();
        delete_directory(static::REMOTE_MOCK);
        delete_directory(static::LOCAL_WORKDIR);

        mkdir(static::LOCAL_WORKDIR, 0700, true);
    }

    protected function tearDown(): void
    {
        reset_function_mocks();
        delete_directory(static::REMOTE_MOCK);
        delete_directory(static::LOCAL_WORKDIR);
    }

    /**
     * @test
     */
    public function local_directory_must_be_writable(): void
    {
        chmod(static::LOCAL_WORKDIR, 0500);

        $this->expectException(UnableToWriteToDirectoryException::class);

        new SingleEncryptedZipArchiveAdapter(
            $this->createMock(FilesystemAdapter::class),
            SingleEncryptedZipArchiveAdapter::generateKey(),
            self::LOCAL_WORKDIR
        );
    }

    /**
     * @test
     */
    public function generated_keys_differ(): void
    {
        static::assertNotSame(
            SingleEncryptedZipArchiveAdapter::generateKey(),
            SingleEncryptedZipArchiveAdapter::generateKey()
        );
    }

    /**
     * @test
     */
    public function generate_long_enough_key(): void
    {
        static::assertGreaterThan(43, \strlen(SingleEncryptedZipArchiveAdapter::generateKey()));
    }

    /**
     * @test
     */
    public function fetching_unknown_mime_type_of_a_file(): void
    {
        static::markTestIncomplete();
    }

    /**
     * @test
     */
    public function deleting_a_directory(): void
    {
        $remoteMock = $this->createMock(FilesystemAdapter::class);
        $adapter = new SingleEncryptedZipArchiveAdapter(
            $remoteMock,
            SingleEncryptedZipArchiveAdapter::generateKey(),
            self::LOCAL_WORKDIR
        );

        $path = uniqid('path_');
        $remoteMock
            ->expects(static::once())
            ->method('deleteDirectory')
            ->with(static::identicalTo($path))
        ;

        $adapter->deleteDirectory($path);
    }

    /**
     * @test
     */
    public function writing_a_file_writes_a_password_secured_zip(): void
    {
        $adapter = $this->adapter();

        $contents = uniqid('contents_');
        $adapter->write('/file.txt', $contents, new Config());

        static::assertFileExists(static::REMOTE_MOCK.'/file.txt.zip');
        static::assertFileDoesNotExist(static::REMOTE_MOCK.'/file.txt');

        $zip = new ZipArchive();
        $zip->open(static::REMOTE_MOCK.'/file.txt.zip', ZipArchive::RDONLY | ZipArchive::CHECKCONS);

        static::assertSame(1, $zip->numFiles);
    }

    protected static function createFilesystemAdapter(): FilesystemAdapter
    {
        return new SingleEncryptedZipArchiveAdapter(
            new LocalFilesystemAdapter(self::REMOTE_MOCK),
            SingleEncryptedZipArchiveAdapter::generateKey(),
            self::LOCAL_WORKDIR
        );
    }
}
