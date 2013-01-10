<?php
namespace Gaufrette;

use Gaufrette\Adapter\ListKeysAware;
use Gaufrette\FileFactory;

use Gaufrette\File as AbstractFile;

/**
 * A filesystem is used to store and retrieve files
 *
 * @author Antoine Hérault <antoine.herault@gmail.com>
 * @author Leszek Prabucki <leszek.prabucki@gmail.com>
 */
class Filesystem
{
    protected $adapter;

    /**
     * Constructor
     *
     * @param Adapter $adapter A configured Adapter instance
     */
    public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * Returns the adapter
     *
     * @return Adapter
     */
    public function getAdapter()
    {
        return $this->adapter;
    }

    /**
     * Indicates whether the file matching the specified key exists.
     *
     * @param string $key
     *
     * @return boolean TRUE if the file exists, FALSE otherwise
     */
    public function has($key)
    {
        return $this->adapter->exists($key);
    }    
    
    /**
     * Renames a file
     *
     * @param string $sourceKey
     * @param string $targetKey
     *
     * @return boolean                  TRUE if the rename was successful
     * @throws Exception\FileNotFound   when sourceKey does not exist
     * @throws Exception\UnexpectedFile when targetKey exists
     * @throws \RuntimeException        when cannot rename
     */
    public function rename($sourceKey, $targetKey)
    {
        $this->assertHasFile($sourceKey);

        if ($this->exists($targetKey)) {
            throw new Exception\UnexpectedFile($targetKey);
        }

        if (! $this->adapter->rename($sourceKey, $targetKey)) {
            throw new \RuntimeException(sprintf('Could not rename the "%s" key to "%s".', $sourceKey, $targetKey));
        }

        return true;
    }

    /**
     * Returns the file matching the specified key
     *
     * @param string  $key    Key of the file
     *
     * @throws Gaufrette\Exception\FileNotFound
     * @return File
     */
    public function get($key)
    {
        $this->assertHasFile($key);

        return $this->adapter->get($key);
    }

    /**
     * Writes the given content into the file
     *
     * @param string  $key       Key of the file
     * @param string  $content   Content to write in the file
     * @param boolean $overwrite Whether to overwrite the file if exists
     * @param array   $metadata  Optional metadata for adapters supporting it
     *
     * @return integer The number of bytes that were written into the file
     */
    public function write($key, $content, $overwrite = false, $metadata = null)
    {
        if (!is_bool($overwrite)) {
            throw new \InvalidArgumentException(sprintf('Param overwrite must be boolean.'));            
        }
        if (!$overwrite && $this->exists($key)) {
            throw new \InvalidArgumentException(sprintf('The key "%s" already exists and can not be overwritten.', $key));
        }

        $numBytes = $this->adapter->write($key, $content, $metadata);

        if (false === $numBytes) {
            throw new \RuntimeException(sprintf('Could not write the "%s" key content.', $key));
        }

        return $numBytes;
    }

    /**
     * Writes a complete file into storage
     *
     * @param Gaufrette\File file
     *
     * @return Gaufrette\File file
     */    
    public function writeFile(AbstractFile $file, $overwrite = false)
    {
        $key = $file->getKey();
        if (!is_bool($overwrite)) {
            throw new \InvalidArgumentException(sprintf('Param overwrite must be boolean.'));            
        }
        if (! isset($key) || strlen($key."") < 1) {
            throw new \InvalidArgumentException(sprintf('Key is not set for file. Cannot write file.'));
        }
        if (strlen($file->getContent()) < 1) {
            throw new \InvalidArgumentException(sprintf('Content is not for file "%s". Cannot write file.'), $key);
        }
        if (!$overwrite && $this->has($key)) {
            throw new \RuntimeException(sprintf('The key "%s" already exists and can not be overwritten.', $key));
        }        
        if ($this->has($key)) {
            $this->delete($key);
        }
        
        return $this->adapter->writeFile($file);        
    }
    
    /**
     * Reads the content from the file
     *
     * @param  string                 $key Key of the file
     * @throws Exception\FileNotFound when file does not exist
     * @throws \RuntimeException      when cannot read file
     *
     * @return string
     */
    public function read($key)
    {
        $this->assertHasFile($key);

        $content = $this->adapter->read($key);

        if (false === $content) {
            throw new \RuntimeException(sprintf('Could not read the "%s" key content.', $key));
        }

        return $content;
    }

    /**
     * Deletes the file matching the specified key
     *
     * @param string $key
     *
     * @return boolean
     */
    public function delete($key)
    {
        $this->assertHasFile($key);

        if ($this->adapter->delete($key)) {
            return true;
        }

        throw new \RuntimeException(sprintf('Could not remove the "%s" key.', $key));
    }

    /**
     * Returns an array of all keys matching the specified pattern
     *
     * @return array
     */
    public function keys()
    {
        return $this->adapter->keys();
    }

    /**
     * Returns an array of all items (files and directories) matching the specified pattern
     *
     * @param  string $pattern
     * @return array
     */
    public function listKeys($pattern = '')
    {
        if ($this->adapter instanceof ListKeysAware) {
            return $this->adapter->listKeys($pattern);
        }

        $dirs = array();
        $keys = array();

        foreach ($this->keys() as $key) {
            if (empty($pattern) || false !== strpos($key, $pattern)) {
                if ($this->adapter->isDirectory($key)) {
                    $dirs[] = $key;
                } else {
                    $keys[] = $key;
                }
            }
        }

        return array(
            'keys' => $keys,
            'dirs' => $dirs
        );
    }

    /**
     * Returns the last modified time of the specified file
     *
     * @param string $key
     *
     * @return integer An UNIX like timestamp
     */
    public function mtime($key)
    {
        $this->assertHasFile($key);

        return $this->adapter->mtime($key);
    }

    /**
     * Returns the checksum of the specified file's content
     *
     * @param string $key
     *
     * @return string A MD5 hash
     */
    public function checksum($key)
    {
        $this->assertHasFile($key);

        if ($this->adapter instanceof Adapter\ChecksumCalculator) {
            return $this->adapter->checksum($key);
        }

        return Util\Checksum::fromContent($this->read($key));
    }

    /**
     * {@inheritDoc}
     */
    public function createStream($key)
    {
        if ($this->adapter instanceof Adapter\StreamFactory) {
            return $this->adapter->createStream($key);
        }

        return new Stream\InMemoryBuffer($this, $key);
    }

    /**
     * {@inheritDoc}
     */
    public function createFile($key)
    {
        if ($this->adapter instanceof FileFactory) {
            return $this->adapter->createFile($key, $this);
        }

        return new File($key, $this);
    }

    /**
     * @param $key
     * @throws Gaufrette\Exception\FileNotFound
     */
    private function assertHasFile($key)
    {
        if (! $this->has($key)) {
            throw new Exception\FileNotFound($key);
        }
    }
}
