<?php

declare(strict_types=1);

namespace Yiisoft\Log\Target\File;

use InvalidArgumentException;
use RuntimeException;
use Yiisoft\Files\FileHelper;

use function chmod;
use function copy;
use function extension_loaded;
use function fclose;
use function feof;
use function file_exists;
use function filesize;
use function flock;
use function fread;
use function ftruncate;
use function is_file;
use function rename;
use function sprintf;
use function substr;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const LOCK_EX;
use const LOCK_SH;
use const LOCK_UN;

/**
 * FileRotator takes care of rotating files.
 *
 * If the size of the file exceeds {@see FileRotator::$maxFileSize} (in kilo-bytes),
 * a rotation will be performed, which renames the current file by suffixing the file name with '.1'.
 *
 * All existing files are moved backwards by one place, i.e., '.2' to '.3', '.1' to '.2', and so on. If compression
 * is enabled {@see FileRotator::$compressRotatedFiles}, the rotated files will be compressed into the '.gz' format.
 * The property {@see FileRotator::$maxFiles} specifies how many history files to keep.
 */
final class FileRotator implements FileRotatorInterface
{
    /**
     * The extension of the compressed rotated files.
     */
    private const COMPRESS_EXTENSION = '.gz';

    /**
     * @var int The maximum file size, in kilo-bytes. Defaults to 10240, meaning 10MB.
     */
    private int $maxFileSize;

    /**
     * @var int The number of files used for rotation. Defaults to 5.
     */
    private int $maxFiles;

    /**
     * @var int|null The permission to be set for newly created files.
     * This value will be used by PHP chmod() function. No umask will be applied.
     * If not set, the permission will be determined by the current environment.
     */
    private ?int $fileMode;

    /**
     * @var bool|null Whether to rotate files by copy and truncate in contrast to rotation by
     * renaming files. Defaults to `true` to be more compatible with log tailers and is windows
     * systems which do not play well with rename on open files. Rotation by renaming however is
     * a bit faster.
     *
     * The problem with windows systems where the [rename()](http://www.php.net/manual/en/function.rename.php)
     * function does not work with files that are opened by some process is described in a
     * [comment by Martin Pelletier](http://www.php.net/manual/en/function.rename.php#102274) in
     * the PHP documentation. By setting rotateByCopy to `true` you can work
     * around this problem.
     */
    private ?bool $rotateByCopy;

    /**
     * @var bool Whether or not to compress rotated files with gzip. Defaults to `false`.
     *
     * If compression is enabled, the rotated files will be compressed into the '.gz' format.
     */
    private bool $compressRotatedFiles;

    /**
     * @param int $maxFileSize The maximum file size, in kilo-bytes. Defaults to 10240, meaning 10MB.
     * @param int $maxFiles The number of files used for rotation. Defaults to 5.
     * @param int|null $fileMode The permission to be set for newly created files.
     * @param bool|null $rotateByCopy Whether to rotate files by copying and truncating or renaming them.
     * @param bool $compressRotatedFiles Whether or not to compress rotated files with gzip.
     */
    public function __construct(
        int $maxFileSize = 10240,
        int $maxFiles = 5,
        int $fileMode = null,
        bool $rotateByCopy = null,
        bool $compressRotatedFiles = false
    ) {
        $this->checkCannotBeLowerThanOne($maxFileSize, '$maxFileSize');
        $this->checkCannotBeLowerThanOne($maxFiles, '$maxFiles');

        $this->maxFileSize = $maxFileSize;
        $this->maxFiles = $maxFiles;
        $this->fileMode = $fileMode;
        $this->rotateByCopy = $rotateByCopy ?? $this->isRunningOnWindows();

        if ($compressRotatedFiles && !extension_loaded('zlib')) {
            throw new RuntimeException(sprintf(
                'The %s requires the PHP extension "ext-zlib" to compress rotated files.',
                self::class,
            ));
        }

        $this->compressRotatedFiles = $compressRotatedFiles;
    }

    public function rotateFile(string $file): void
    {
        for ($i = $this->maxFiles; $i >= 0; --$i) {
            // `$i === 0` is the original file
            $rotateFile = $file . ($i === 0 ? '' : '.' . $i);
            $newFile = $file . '.' . ($i + 1);

            if ($i === $this->maxFiles) {
                $this->safeRemove($this->compressRotatedFiles ? $rotateFile . self::COMPRESS_EXTENSION : $rotateFile);
                continue;
            }

            if ($this->compressRotatedFiles && is_file($rotateFile . self::COMPRESS_EXTENSION)) {
                $this->rotate($rotateFile . self::COMPRESS_EXTENSION, $newFile . self::COMPRESS_EXTENSION);
                continue;
            }

            if (!is_file($rotateFile)) {
                continue;
            }

            $this->rotate($rotateFile, $newFile);
            $this->compress($newFile);

            if ($i === 0) {
                $this->clear($rotateFile);
            }
        }
    }

    public function shouldRotateFile(string $file): bool
    {
        return file_exists($file) && @filesize($file) > ($this->maxFileSize * 1024);
    }

    /***
     * Copies or renames rotated file into new file.
     *
     * @param string $rotateFile
     * @param string $newFile
     */
    private function rotate(string $rotateFile, string $newFile): void
    {
        if ($this->rotateByCopy !== true) {
            $this->safeRemove($newFile);
            rename($rotateFile, $newFile);
            return;
        }

        copy($rotateFile, $newFile);

        if ($this->fileMode !== null && (!$this->compressRotatedFiles || $this->isCompressed($newFile))) {
            chmod($newFile, $this->fileMode);
        }
    }

    /**
     * Compresses a file with gzip and renames it by appending `.gz` to the file.
     *
     * @param string $file
     */
    private function compress(string $file): void
    {
        if (!$this->compressRotatedFiles || $this->isCompressed($file)) {
            return;
        }

        $filePointer = FileHelper::openFile($file, 'rb');
        flock($filePointer, LOCK_SH);
        $gzFile = $file . self::COMPRESS_EXTENSION;
        $gzFilePointer = gzopen($gzFile, 'wb9');

        while (!feof($filePointer)) {
            gzwrite($gzFilePointer, fread($filePointer, 8192));
        }

        flock($filePointer, LOCK_UN);
        fclose($filePointer);
        gzclose($gzFilePointer);
        @unlink($file);

        if ($this->fileMode !== null) {
            chmod($gzFile, $this->fileMode);
        }
    }

    /***
     * Clears the file without closing any other process open handles.
     *
     * @param string $file
     */
    private function clear(string $file): void
    {
        $filePointer = FileHelper::openFile($file, 'ab');

        flock($filePointer, LOCK_EX);
        ftruncate($filePointer, 0);
        flock($filePointer, LOCK_UN);
        fclose($filePointer);
    }

    /**
     * Checks the existence of file and removes it.
     *
     * @param string $file
     */
    private function safeRemove(string $file): void
    {
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * Whether the file is compressed.
     *
     * @param string $file
     *
     * @return bool
     */
    private function isCompressed(string $file): bool
    {
        return substr($file, -3, 3) === self::COMPRESS_EXTENSION;
    }

    /**
     * Whether it works on Windows OS.
     *
     * @return bool
     */
    private function isRunningOnWindows(): bool
    {
        return DIRECTORY_SEPARATOR === '\\';
    }

    /**
     * Checks that the value cannot be lower than one.
     *
     * @param int $value The value to be checked.
     * @param string $argumentName The name of the argument to check.
     */
    private function checkCannotBeLowerThanOne(int $value, string $argumentName): void
    {
        if ($value < 1) {
            throw new InvalidArgumentException(sprintf(
                'The argument "%s" cannot be lower than 1.',
                $argumentName,
            ));
        }
    }
}
