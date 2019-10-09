<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Cache\Adapter;

use Symfony\Component\Cache\Marshaller\DefaultMarshaller;
use Symfony\Component\Cache\Marshaller\MarshallerInterface;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\Cache\Traits\FilesystemTrait;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Stores tag id <> cache id relationship as a symlink, and lookup on invalidation calls.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 * @author André Rømcke <andre.romcke+symfony@gmail.com>
 *
 * @experimental in 4.3
 */
class FilesystemTagAwareAdapter extends AbstractTagAwareAdapter implements PruneableInterface
{
    use FilesystemTrait {
        doSave as doSaveCache;
        doDelete as doDeleteCache;
    }

    /**
     * Folder used for tag symlinks.
     */
    private const TAG_FOLDER = 'tags';

    /**
     * @var Filesystem|null
     */
    private $fs;

    public function __construct(string $namespace = '', int $defaultLifetime = 0, string $directory = null, MarshallerInterface $marshaller = null)
    {
        $this->marshaller = $marshaller ?? new DefaultMarshaller();
        parent::__construct('', $defaultLifetime);
        $this->init($namespace, $directory);
    }

    /**
     * {@inheritdoc}
     */
    protected function doSave(array $values, ?int $lifetime, array $addTagData = [], array $removeTagData = []): array
    {
        $failed = $this->doSaveCache($values, $lifetime);

        $fs = $this->getFilesystem();
        // Add Tags as symlinks
        foreach ($addTagData as $tagId => $ids) {
            $tagFolder = $this->getTagFolder($tagId);
            foreach ($ids as $id) {
                if ($failed && \in_array($id, $failed, true)) {
                    continue;
                }

                $file = $this->getFile($id);
                $fs->symlink($file, $this->getFile($id, true, $tagFolder));
            }
        }

        // Unlink removed Tags
        $files = [];
        foreach ($removeTagData as $tagId => $ids) {
            $tagFolder = $this->getTagFolder($tagId);
            foreach ($ids as $id) {
                if ($failed && \in_array($id, $failed, true)) {
                    continue;
                }

                $files[] = $this->getFile($id, false, $tagFolder);
            }
        }
        $fs->remove($files);

        return $failed;
    }

    /**
     * {@inheritdoc}
     */
    protected function doDelete(array $ids, array $tagData = []): bool
    {
        $ok = $this->doDeleteCache($ids);

        // Remove tags
        $files = [];
        $fs = $this->getFilesystem();
        foreach ($tagData as $tagId => $idMap) {
            $tagFolder = $this->getTagFolder($tagId);
            foreach ($idMap as $id) {
                $files[] = $this->getFile($id, false, $tagFolder);
            }
        }
        $fs->remove($files);

        return $ok;
    }

    /**
     * {@inheritdoc}
     */
    protected function doInvalidate(array $tagIds): bool
    {
        foreach ($tagIds as $tagId) {
            if (!file_exists($tagsFolder = $this->getTagFolder($tagId))) {
                continue;
            }

            set_error_handler(static function () {});

            try {
                if (rename($tagsFolder, $renamed = substr_replace($tagsFolder, bin2hex(random_bytes(4)), -1))) {
                    $tagsFolder = $renamed.\DIRECTORY_SEPARATOR;
                } else {
                    $renamed = null;
                }

                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tagsFolder, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME)) as $itemLink) {
                    unlink(realpath($itemLink) ?: $itemLink);
                    unlink($itemLink);
                }

                if (null === $renamed) {
                    continue;
                }

                $chars = '+-ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

                for ($i = 0; $i < 38; ++$i) {
                    for ($j = 0; $j < 38; ++$j) {
                        rmdir($tagsFolder.$chars[$i].\DIRECTORY_SEPARATOR.$chars[$j]);
                    }
                    rmdir($tagsFolder.$chars[$i]);
                }
                rmdir($renamed);
            } finally {
                restore_error_handler();
            }
        }

        return true;
    }

    private function getFilesystem(): Filesystem
    {
        return $this->fs ?? $this->fs = new Filesystem();
    }

    private function getTagFolder(string $tagId): string
    {
        return $this->getFile($tagId, false, $this->directory.self::TAG_FOLDER.\DIRECTORY_SEPARATOR).\DIRECTORY_SEPARATOR;
    }
}
