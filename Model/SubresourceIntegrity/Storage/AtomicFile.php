<?php

declare(strict_types=1);

namespace SeismicPixels\SriHashesAtomicStorage\Model\SubresourceIntegrity\Storage;

use Magento\Csp\Model\SubresourceIntegrity\StorageInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Psr\Log\LoggerInterface;

/**
 * Same contract as Magento CSP file storage for SRI hashes, with safer I/O:
 *
 * - save() writes the full JSON to a temp file in the same directory, then renames it over
 *   sri-hashes.json in a single step (atomic replace on Linux). That avoids torn/partial writes
 *   on the live path and avoids a brief “file missing” window from delete-then-rename.
 * - load() validates JSON; if invalid, logs, deletes the file, and returns null so checkout
 *   can recover by rebuilding hashes on the next request.
 */
class AtomicFile implements StorageInterface
{
    private const FILENAME = 'sri-hashes.json';

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly LoggerInterface $logger
    ) {
    }

    public function load(?string $context): ?string
    {
        try {
            $staticDir = $this->filesystem->getDirectoryRead(DirectoryList::STATIC_VIEW);
            $path = $this->resolveFilePath($context);

            if (!$staticDir->isFile($path)) {
                return null;
            }

            $raw = $staticDir->readFile($path);
            if ($raw === '' || $raw === null) {
                return null;
            }

            json_decode($raw, true);
            if (\json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->warning(
                    sprintf(
                        'SRI hashes file contained invalid JSON; removing so it can be rebuilt. Context: %s. Error: %s',
                        (string) $context,
                        json_last_error_msg()
                    )
                );
                $write = $this->filesystem->getDirectoryWrite(DirectoryList::STATIC_VIEW);
                $write->delete($path);

                return null;
            }

            return $raw;
        } catch (FileSystemException $exception) {
            $this->logger->critical($exception);

            return null;
        }
    }

    public function save(string $data, ?string $context): bool
    {
        try {
            $staticDir = $this->filesystem->getDirectoryWrite(DirectoryList::STATIC_VIEW);
            $relativePath = $this->resolveFilePath($context);
            $tmpRelative = $relativePath . '.tmp.' . bin2hex(random_bytes(8));

            $staticDir->writeFile($tmpRelative, $data);

            $driver = $staticDir->getDriver();
            $base = $staticDir->getAbsolutePath();
            $tmpAbs = $driver->getAbsolutePath($base, $tmpRelative);
            $targetAbs = $driver->getAbsolutePath($base, $relativePath);

            $driver->rename($tmpAbs, $targetAbs);

            return true;
        } catch (\Throwable $exception) {
            $this->logger->critical($exception);

            return false;
        }
    }

    public function remove(?string $context): bool
    {
        try {
            $staticDir = $this->filesystem->getDirectoryWrite(DirectoryList::STATIC_VIEW);

            return $staticDir->delete($this->resolveFilePath($context));
        } catch (FileSystemException $exception) {
            $this->logger->critical($exception);

            return false;
        }
    }

    private function resolveFilePath(?string $context): string
    {
        return ($context ? $context . DIRECTORY_SEPARATOR : '') . self::FILENAME;
    }
}
