<?php

declare(strict_types=1);

namespace SlamFlysystemEncryptedZipProxyTest;

use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;
use SlamFlysystemEncryptedZipProxy\EncryptedZipProxyAdapter;
use SlamFlysystemEncryptedZipProxy\WeakPasswordException;

/**
 * @covers \SlamFlysystemEncryptedZipProxy\EncryptedZipProxyAdapter
 * @covers \SlamFlysystemEncryptedZipProxy\EncryptorStreamFilter
 *
 * @internal
 */
final class EncryptedZipProxyAdapterTest extends FilesystemAdapterTestCase
{
    private ?EncryptedZipProxyAdapter $customAdapter = null;
    private string $remoteMock;

    protected function setUp(): void
    {
        $testToken = (int) getenv('TEST_TOKEN');
        $this->remoteMock = __DIR__.'/assets/'.$testToken.'_remote-mock';
        reset_function_mocks();
        delete_directory($this->remoteMock);
    }

    protected function tearDown(): void
    {
        reset_function_mocks();
        delete_directory($this->remoteMock);
    }

    public function adapter(): EncryptedZipProxyAdapter
    {
        if (null === $this->customAdapter) {
            $this->customAdapter = new EncryptedZipProxyAdapter(
                new LocalFilesystemAdapter($this->remoteMock),
                EncryptedZipProxyAdapter::generateKey()
            );
        }

        return $this->customAdapter;
    }

    /**
     * @test
     */
    public function accept_long_enough_password_only(): void
    {
        $this->expectException(WeakPasswordException::class);

        new EncryptedZipProxyAdapter(
            $this->createMock(FilesystemAdapter::class),
            base64_encode(random_bytes(8))
        );
    }

    /**
     * @test
     */
    public function generated_keys_differ(): void
    {
        static::assertNotSame(
            EncryptedZipProxyAdapter::generateKey(),
            EncryptedZipProxyAdapter::generateKey()
        );
    }

    /**
     * @test
     */
    public function generate_long_enough_key(): void
    {
        static::assertGreaterThan(43, \strlen(EncryptedZipProxyAdapter::generateKey()));
    }

    /**
     * @test
     */
    public function fetching_file_size(): void
    {
        $adapter = $this->adapter();
        $this->givenWeHaveAnExistingFile('path.txt', 'contents');

        $attributes = $adapter->fileSize('path.txt');
        static::assertInstanceOf(FileAttributes::class, $attributes);
        static::assertSame(57, $attributes->fileSize());
    }

    /**
     * @test
     */
    public function fetching_unknown_mime_type_of_a_file(): void
    {
        static::markTestSkipped('It\'s up to the developer choosing if relying or not to this functionality');
    }

    /**
     * @test
     */
    public function fetching_the_mime_type_of_an_svg_file(): void
    {
        static::markTestSkipped('It\'s up to the developer choosing if relying or not to this functionality');
    }

    /**
     * @test
     */
    public function deleting_a_directory(): void
    {
        $remoteMock = $this->createMock(FilesystemAdapter::class);
        $adapter = new EncryptedZipProxyAdapter(
            $remoteMock,
            EncryptedZipProxyAdapter::generateKey()
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

        static::assertFileDoesNotExist($this->remoteMock.'/file.txt');
        static::assertStringNotEqualsFile(
            $this->remoteMock.'/file.txt'.EncryptedZipProxyAdapter::REMOTE_FILE_EXTENSION,
            $contents
        );
    }

    /**
     * @test
     */
    public function reading_multiple_files(): void
    {
        $adapter = $this->adapter();
        $this->givenWeHaveAnExistingFile('path1.txt', '123');
        // $this->givenWeHaveAnExistingFile('path2.txt', '456');

        static::assertSame('123', $adapter->read('path1.txt'));
        // static::assertSame('456', $adapter->read('path2.txt'));
    }

    protected static function createFilesystemAdapter(): FilesystemAdapter
    {
        throw new RuntimeException('Only non-static adapter creation allowed');
    }
}
