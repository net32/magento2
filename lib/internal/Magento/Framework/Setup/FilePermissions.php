<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\Setup;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Backup\Filesystem\Iterator\Filter;
use Magento\Framework\Filesystem\Filter\ExcludeFilter;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\OsInfo;

class FilePermissions
{
    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var DirectoryList
     */
    protected $directoryList;

    /**
     * @var File
     */
    protected $driverFile;

    /**
     * List of required writable directories for installation
     *
     * @var array
     */
    protected $installationWritableDirectories = [];

    /**
     * List of recommended non-writable directories for application
     *
     * @var array
     */
    protected $applicationNonWritableDirectories = [];

    /**
     * List of current writable directories for installation
     *
     * @var array
     */
    protected $installationCurrentWritableDirectories = [];

    /**
     * List of current non-writable directories for application
     *
     * @var array
     */
    protected $applicationCurrentNonWritableDirectories = [];

    /**
     * List of non-writable paths in a specified directory
     *
     * @var array
     */
    protected $nonWritablePathsInDirectories = [];

    /**
     * @var \Magento\Framework\OsInfo
     */
    protected $osInfo;

    /**
     * @param Filesystem $filesystem
     * @param DirectoryList $directoryList
     * @param File $driverFile
     * @param OsInfo $osInfo
     */
    public function __construct(
        Filesystem $filesystem,
        DirectoryList $directoryList,
        File $driverFile,
        OsInfo $osInfo
    ) {
        $this->filesystem = $filesystem;
        $this->directoryList = $directoryList;
        $this->driverFile = $driverFile;
        $this->osInfo = $osInfo;
    }

    /**
     * Retrieve list of required writable directories for installation
     *
     * @return array
     */
    public function getInstallationWritableDirectories()
    {
        if (!$this->installationWritableDirectories) {
            $data = [
                DirectoryList::CONFIG,
                DirectoryList::VAR_DIR,
                DirectoryList::MEDIA,
                DirectoryList::STATIC_VIEW,
            ];
            foreach ($data as $code) {
                $this->installationWritableDirectories[$code] = $this->directoryList->getPath($code);
            }
        }
        return array_values($this->installationWritableDirectories);
    }

    /**
     * Retrieve list of recommended non-writable directories for application
     *
     * @return array
     */
    public function getApplicationNonWritableDirectories()
    {
        if (!$this->applicationNonWritableDirectories) {
            $data = [
                DirectoryList::CONFIG,
            ];
            foreach ($data as $code) {
                $this->applicationNonWritableDirectories[$code] = $this->directoryList->getPath($code);
            }
        }
        return array_values($this->applicationNonWritableDirectories);
    }

    /**
     * Retrieve list of currently writable directories for installation
     *
     * @return array
     */
    public function getInstallationCurrentWritableDirectories()
    {
        if (!$this->installationCurrentWritableDirectories) {
            foreach ($this->installationWritableDirectories as $code => $path) {
                if ($this->isWritable($code)) {
                    if ($this->checkRecursiveDirectories($path)) {
                        $this->installationCurrentWritableDirectories[] = $path;
                    }
                } else {
                    $this->nonWritablePathsInDirectories[$path] = [$path];
                }
            }
        }
        return $this->installationCurrentWritableDirectories;
    }

    /**
     * Check all sub-directories and files except for var/generation and var/di
     *
     * @param string $directory
     * @return bool
     */
    private function checkRecursiveDirectories($directory)
    {
        $directoryIterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        $noWritableFilesFolders = [
            $this->directoryList->getPath(DirectoryList::GENERATION) . '/',
            $this->directoryList->getPath(DirectoryList::DI) . '/',
        ];

        $directoryIterator = new Filter($directoryIterator, $noWritableFilesFolders);

        $directoryIterator = new ExcludeFilter(
            $directoryIterator,
            [
                $this->directoryList->getPath(DirectoryList::SESSION) . '/',
            ]
        );

        $foundNonWritable = false;

        try {
            foreach ($directoryIterator as $subDirectory) {
                if (!$subDirectory->isWritable() && !$subDirectory->isLink()) {
                    $this->nonWritablePathsInDirectories[$directory][] = $subDirectory;
                    $foundNonWritable = true;
                }
            }
        } catch (\UnexpectedValueException $e) {
            return false;
        }
        return !$foundNonWritable;
    }

    /**
     * Retrieve list of currently non-writable directories for application
     *
     * @return array
     */
    public function getApplicationCurrentNonWritableDirectories()
    {
        if (!$this->applicationCurrentNonWritableDirectories) {
            foreach ($this->applicationNonWritableDirectories as $code => $path) {
                if ($this->isNonWritable($code)) {
                    $this->applicationCurrentNonWritableDirectories[] = $path;
                }
            }
        }
        return $this->applicationCurrentNonWritableDirectories;
    }

    /**
     * Checks if directory is writable by given directory code
     *
     * @param string $code
     * @return bool
     */
    protected function isWritable($code)
    {
        $directory = $this->filesystem->getDirectoryWrite($code);
        return $this->isReadableDirectory($directory) && $directory->isWritable();
    }

    /**
     * Checks if directory is non-writable by given directory code
     *
     * @param string $code
     * @return bool
     */
    protected function isNonWritable($code)
    {
        $directory = $this->filesystem->getDirectoryWrite($code);
        return $this->isReadableDirectory($directory) && !$directory->isWritable();
    }

    /**
     * Checks if var/generation/* has read and execute permissions
     *
     * @return bool
     */
    public function checkDirectoryPermissionForCLIUser()
    {
        $varGenerationDir = $this->directoryList->getPath(DirectoryList::GENERATION);
        $dirs = $this->driverFile->readDirectory($varGenerationDir);
        array_unshift($dirs, $varGenerationDir);

        foreach ($dirs as $dir) {
            if (!$this->directoryPermissionForCLIUserValid($dir)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Checks if directory exists and is readable
     *
     * @param \Magento\Framework\Filesystem\Directory\WriteInterface $directory
     * @return bool
     */
    protected function isReadableDirectory($directory)
    {
        if (!$directory->isExist() || !$directory->isDirectory() || !$directory->isReadable()) {
            return false;
        }
        return true;
    }

    /**
     * Checks writable paths for installation
     *
     * @return array
     */
    public function getMissingWritablePathsForInstallation()
    {
        $required = $this->getInstallationWritableDirectories();
        $current = $this->getInstallationCurrentWritableDirectories();
        $missingPaths = [];
        foreach (array_diff($required, $current) as $missingPath) {
            if (isset($this->nonWritablePathsInDirectories[$missingPath])) {
                $missingPaths = array_merge(
                    $missingPaths,
                    $this->nonWritablePathsInDirectories[$missingPath]
                );
            }
        }
        return $missingPaths;
    }

    /**
     * Checks writable directories for installation
     *
     * @deprecated Use getMissingWritablePathsForInstallation() to get all missing writable paths required for install
     * @return array
     */
    public function getMissingWritableDirectoriesForInstallation()
    {
        $required = $this->getInstallationWritableDirectories();
        $current = $this->getInstallationCurrentWritableDirectories();
        return array_diff($required, $current);
    }

    /**
     * Checks non-writable directories for application
     *
     * @return array
     */
    public function getUnnecessaryWritableDirectoriesForApplication()
    {
        $required = $this->getApplicationNonWritableDirectories();
        $current = $this->getApplicationCurrentNonWritableDirectories();
        return array_diff($required, $current);
    }

    /**
     * Checks if directory has permissions needed for CLI user (valid directory, readable, and executable.)
     * Ignores executable permission for Windows.
     *
     * @param string $dir
     * @return bool
     */
    private function directoryPermissionForCLIUserValid($dir)
    {
        return (is_dir($dir) && is_readable($dir) && (is_executable($dir) || $this->osInfo->isWindows()));
    }
}