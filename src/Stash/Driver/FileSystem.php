<?php

/*
 * This file is part of the Stash package.
 *
 * (c) Robert Hafner <tedivm@tedivm.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Stash\Driver;

use Stash;
use Stash\Driver\FileSystem\NativeEncoder;
use Stash\Driver\FileSystem\EncoderInterface;
use Stash\Utilities;
use Stash\Exception\LogicException;
use Stash\Exception\RuntimeException;
use Stash\Interfaces\DriverInterface;

/**
 * StashFileSystem stores cache objects in the filesystem as native php, making the process of retrieving stored data
 * as performance intensive as including a file. Since the data is stored as php this module can see performance
 * benefits from php opcode caches like APC and xcache.
 *
 * @package Stash
 * @author  Robert Hafner <tedivm@tedivm.com>
 */
class FileSystem implements DriverInterface
{
    /**
     * This is the path to the file which will be used to store the cached item. It is based off of the key.
     *
     * @var string
     */
    protected $path;

    /**
     * This is the array passed from the main Cache class, which needs to be saved
     *
     * @var array
     */
    protected $data;

    /**
     * This function stores the path information generated by the makePath function so that it does not have to be
     * calculated each time the driver is called. This only stores path information, it does not store the data to be
     * cached.
     *
     * @var array
     */
    protected $memStore = array();

    /**
     * The limit of keys to store in memory.
     *
     * @var int
     */
    protected $memStoreLimit;

    /**
     * This is the base path for the cache items to be saved in. This defaults to a directory in the tmp directory (as
     * defined by the configuration) called 'stash_', which it will create if needed.
     *
     * @var string
     */
    protected $cachePath;

    /**
     * Permissions to use for new files.
     *
     * @var
     */
    protected $filePermissions;

    /**
     * Permissions to use for new directories.
     *
     * @var
     */
    protected $dirPermissions;

    /**
     * The level of directories each key will have. This is used to reduce the number of files or directories
     * in a single directory to get past various filesystem limits.
     *
     * @var
     */
    protected $directorySplit;

    /**
     * The hashing algorithm used to normalize keys into filesystem safe values. The only reason this gets changed is
     * to lower the path length for windows systems.
     *
     * @var
     */
    protected $keyHashFunction;

    /**
     * Is this driver disabled.
     *
     * @var bool
     */
    protected $disabled = false;

    /**
     * Default values for selections the user does not make.
     *
     * @var array
     */
    protected $defaultOptions = array('filePermissions' => 0660,
                                      'dirPermissions' => 0770,
                                      'dirSplit' => 2,
                                      'memKeyLimit' => 20,
                                      'keyHashFunction' => 'md5'
    );

    /**
     * @var \Stash\Driver\FileSystem\EncoderInterface
     */
    protected $encoder;

    /**
     * Requests a list of options.
     *
     * @param  array                             $options
     * @throws \Stash\Exception\RuntimeException
     */
    public function setOptions(array $options = array())
    {
        $options = array_merge($this->defaultOptions, $options);

        $this->cachePath = isset($options['path']) ? $options['path'] : Utilities::getBaseDirectory($this);
        $this->cachePath = rtrim($this->cachePath, '\\/') . DIRECTORY_SEPARATOR;

        $this->filePermissions = $options['filePermissions'];
        $this->dirPermissions = $options['dirPermissions'];

        if (!is_numeric($options['dirSplit']) || $options['dirSplit'] < 1) {
            $options['dirSplit'] = 1;
        }

        $this->directorySplit = (int) $options['dirSplit'];

        if (!is_numeric($options['memKeyLimit']) || $options['memKeyLimit'] < 1) {
            $options['memKeyLimit'] = 0;
        }

        if (is_callable($options['keyHashFunction'])) {
            $this->keyHashFunction = $options['keyHashFunction'];
        } else {
            throw new RuntimeException('Key Hash Function is not callable');
        }

        $this->memStoreLimit = (int) $options['memKeyLimit'];

        if (isset($options['encoder'])) {
            $encoder = $options['encoder'];
            if (is_object($encoder)) {
                if (!($encoder instanceof EncoderInterface)) {
                    throw new RuntimeException('Encoder object must implement EncoderInterface');
                }
                $this->encoder = new $encoder;
            } else {
                $encoderClass = 'Stash\Driver\FileSystem\\' . $encoder . 'Encoder';
                if (class_exists($encoder)) {
                    $this->encoder = new $encoder();
                } elseif (class_exists($encoderClass)) {
                    $this->encoder = new $encoderClass();
                } else {
                    throw new RuntimeException('Invalid Encoder: ' . $encoder);
                }
            }
        }

        Utilities::checkFileSystemPermissions($this->cachePath, $this->dirPermissions);
    }

    /**
     * {@inheritdoc}
     */
    public function __destruct()
    {
    }

    /**
     * Converts a key array into a key string.
     *
     * @param  array  $key
     * @return string
     */
    protected function makeKeyString($key)
    {
        $keyString = '';
        foreach ($key as $group) {
            $keyString .= $group . '/';
        }

        return $keyString;
    }

    /**
     * This function retrieves the data from the file. If the file does not exist, or is currently being written to, it
     * will return false. If the file is already being written to, this instance of the driver gets disabled so as not
     * to have a bunch of writes get queued up when a cache item fails to hit.
     *
     * {@inheritdoc}
     *
     * @return bool
     */
    public function getData($key)
    {
        return $this->getEncoder()->deserialize($this->makePath($key));
    }

    /**
     * This function takes the data and stores it to the path specified. If the directory leading up to the path does
     * not exist, it creates it.
     *
     * {@inheritdoc}
     */
    public function storeData($key, $data, $expiration)
    {
        $path = $this->makePath($key);

        // MAX_PATH is 260 - http://msdn.microsoft.com/en-us/library/aa365247(VS.85).aspx
        if (strlen($path) > 259 &&  stripos(PHP_OS, 'WIN') === 0) {
            throw new Stash\Exception\WindowsPathMaxLengthException();
        }

        if (!file_exists($path)) {
            if (!is_dir(dirname($path))) {
                if (!@mkdir(dirname($path), $this->dirPermissions, true)) {
                    return false;
                }
            }

            if (!(touch($path) && chmod($path, $this->filePermissions))) {
                return false;
            }
        }

        $storeString = $this->getEncoder()->serialize($this->makeKeyString($key), $data, $expiration);
        $result = file_put_contents($path, $storeString, LOCK_EX);

        // If opcache is switched on, it will try to cache the PHP data file
        // The new php opcode caching system only revalidates against the source files once every few seconds,
        // so some changes will not be caught.
        // This fix immediately invalidates that opcode cache after a file is written,
        // so that future includes are not using the stale opcode cached file.
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }

        return false !== $result;
    }

    /**
     * This function takes in an array of strings (the key) and uses them to create a path to save the cache item to.
     * It starts with the cachePath (or a new 'cache' directory in the config temp directory) and then uses each element
     * of the array as a directory (after putting the element through md5(), which was the most efficient way to make
     * sure it was filesystem safe). The last element of the array gets a php extension attached to it.
     *
     * @param  array                           $key Null arguments return the base directory.
     * @throws \Stash\Exception\LogicException
     * @return string
     */
    protected function makePath($key = null)
    {
        if (!isset($this->cachePath)) {
            throw new LogicException('Unable to load system without a base path.');
        }

        $basePath = $this->cachePath;

        if (!is_array($key) || count($key) == 0) {
            return $basePath;
        }

        // When I profiled this compared to the "implode" function, this was much faster. This is probably due to the
        // small size of the arrays and the overhead from function calls. This may seem like a ridiculous
        // micro-optimization, but I only did it after profiling the code with xdebug and noticing a legitimate
        // difference, most likely due to the number of times this function can get called in a scripts.
        // Please don't look at me like that.
        $memkey = '';
        foreach ($key as $group) {
            $memkey .= str_replace('#', ':', $group) . '#';
        }

        if (isset($this->memStore['keys'][$memkey])) {
            return $this->memStore['keys'][$memkey];
        } else {
            $path = $basePath;
            $key = Utilities::normalizeKeys($key, $this->keyHashFunction);

            foreach ($key as $value) {
                if (strpos($value, '@') === 0) {
                    $path .= substr($value, 1) . DIRECTORY_SEPARATOR;
                    continue;
                }

                $sLen = strlen($value);
                $len = floor($sLen / $this->directorySplit);
                for ($i = 0; $i < $this->directorySplit; $i++) {
                    $start = $len * $i;
                    if ($i == $this->directorySplit) {
                        $len = $sLen - $start;
                    }
                    $path .= substr($value, $start, $len) . DIRECTORY_SEPARATOR;
                }
            }

            $path = rtrim($path, DIRECTORY_SEPARATOR) . $this->getEncoder()->getExtension();
            $this->memStore['keys'][$memkey] = $path;

            // in most cases the key will be used almost immediately or not at all, so it doesn't need to grow too large
            if (count($this->memStore['keys']) > $this->memStoreLimit) {
                foreach (array_rand($this->memStore['keys'], ceil($this->memStoreLimit / 2) + 1) as $empty) {
                    unset($this->memStore['keys'][$empty]);
                }
            }

            return $path;
        }
    }

    /**
     * This function clears the data from a key. If a key points to both a directory and a file, both are erased. If
     * passed null, the entire cache directory is removed.
     *
     * {@inheritdoc}
     */
    public function clear($key = null)
    {
        $path = $this->makePath($key);
        if (is_file($path)) {
            $return = true;
            unlink($path);
        }

        $extension = $this->getEncoder()->getExtension();
        if (strpos($path, $extension) !== false) {
            $path = substr($path, 0, -(strlen($extension)));
        }

        if (is_dir($path)) {
            return Utilities::deleteRecursive($path, true);
        }

        return isset($return);
    }

    /**
     * Cleans out the cache directory by removing all stale cache files and empty directories.
     *
     * {@inheritdoc}
     */
    public function purge()
    {
        $startTime = time();
        $filePath = $this->makePath();

        $directoryIt = new \RecursiveDirectoryIterator($filePath);

        foreach (new \RecursiveIteratorIterator($directoryIt, \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $filename = $file->getPathname();
            if ($file->isDir()) {
                $dirFiles = scandir($file->getPathname());
                if ($dirFiles && count($dirFiles) == 2) {
                    $filename = rtrim($filename, '/.');

                    if (file_exists(($filename))) {
                        rmdir($filename);
                    }
                }
                unset($dirFiles);
                continue;
            }

            if (!file_exists($filename)) {
                continue;
            }

            $data = $this->getEncoder()->deserialize($filename);

            if (is_numeric($data['expiration']) && $data['expiration'] <= $startTime) {
                unlink($filename);
            }
        }
        unset($directoryIt);

        return true;
    }

    protected function getEncoder()
    {
        if (!isset($this->encoder)) {
            $this->encoder = new \Stash\Driver\FileSystem\NativeEncoder();
        }

        return $this->encoder;
    }

    /**
     * This function checks to see if it is possible to enable this driver. This returns true no matter what, since
     * there is typically a filesystem available somewhere.
     *
     * {@inheritdoc}
     * @return bool true
     */
    public static function isAvailable()
    {
        return true;
    }
}
