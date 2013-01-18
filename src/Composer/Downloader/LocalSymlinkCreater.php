<?php

/*
 * This file is part of Composer.
 *
 * (c) René Patzer <rene.patzer@gamepay.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Downloader;

use Composer\Config;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Util\ProcessExecutor;
use Composer\IO\IOInterface;
use Composer\Util\Filesystem;

/**
 * downloader that only creates symlinks for files
 * 
 * This must be done by providing a repositories-section with the source-directory defined as a package
 * e.g.
 * "repositories": [
 *         {
 *         "type": "package",
 *         "package": {
 *                 "name": "vendor/mastering-bundle",
 *                 "version": "master",
 *                 "autoload": { "classmap": [ "classes-subfolder/" ] },
 *                 "source": {
 *                         "url": "/home/user/path/to/source/",
 *                         "type": "symlink",
 *                         "reference": "dev-master"
 *                 }
 *         }
 *         }
 * ]
 * 
 */
class LocalSymlinkCreater implements DownloaderInterface
{
    protected $io;
    protected $config;
    protected $process;
    protected $filesystem;

    public function __construct(IOInterface $io, Config $config, ProcessExecutor $process = null, Filesystem $fs = null)
    {
        $this->io = $io;
        $this->config = $config;
        $this->process = $process ?: new ProcessExecutor;
        $this->filesystem = $fs ?: new Filesystem;
    }

    /**
     * {@inheritDoc}
     */
    public function getInstallationSource()
    {
        return 'source';
    }

    /**
     * {@inheritDoc}
     */
    public function download(PackageInterface $package, $path)
    {
        $this->io->write("  - Installing <info>" . $package->getName() . "</info> (<comment>" . VersionParser::formatVersion($package) . "</comment>)");
        $this->io->write("  - <info>This may take a while</info>");
        
        // $path is the path the package should be installed to
        // e.g. ../project_dir/vendor/vendorname/packagename/vendorname/TheOtherPackageName
        $this->filesystem->removeDirectory($path);
        $this->doDownload($package, $path);
        $this->io->write('');
    }

    /**
     * {@inheritDoc}
     */
    public function update(PackageInterface $initial, PackageInterface $target, $path)
    {
        $name = $target->getName();
        $this->io->write("  - Updating <info>" . $name . "</info> (<comment>No version info available </comment>)");
        $this->io->write("  - <comment>This may take a while</comment>");
        
        // $path is the path the package should be installed to
        // e.g. ../project_dir/vendor/vendorname/packagename/vendorname/TheOtherPackageName
        // $this->filesystem->removeDirectory($path);
        $this->doDownload($target, $path);
        $this->io->write('');
        $this->io->write('');
    }

    /**
     * {@inheritDoc}
     */
    public function remove(PackageInterface $package, $path)
    {
        $this->io->write("  - Removing <info>" . $package->getName() . "</info> (<comment>" . $package->getPrettyVersion() . "</comment>)");
        $this->cleanChanges($path, false);
        if (!$this->filesystem->removeDirectory($path)) {
            throw new \RuntimeException('Could not completely delete '.$path.', aborting.');
        }
    }

    /**
     * Download progress information is not available for all VCS downloaders.
     * {@inheritDoc}
     */
    public function setOutputProgress($outputProgress)
    {
        return $this;
    }

    /**
     * Prompt the user to check if changes should be stashed/removed or the operation aborted
     *
     * @param string $path
     * @param bool   $update if true (update) the changes can be stashed and reapplied after an update,
     *                                  if false (remove) the changes should be assumed to be lost if the operation is not aborted
     * @throws \RuntimeException in case the operation must be aborted
     */
    protected function cleanChanges($path, $update)
    {
        // the default implementation just fails if there are any changes, override in child classes to provide stash-ability
        if (null !== $this->getLocalChanges($path)) {
            throw new \RuntimeException('Source directory ' . $path . ' has uncommitted changes.');
        }
    }

    /**
     * Guarantee that no changes have been made to the local copy
     *
     * @param  string            $path
     * @throws \RuntimeException in case the operation must be aborted or the patch does not apply cleanly
     */
    protected function reapplyChanges($path)
    {
    }

    /**
     * Downloads specific package into specific folder.
     *
     * @param PackageInterface $package package instance
     * @param string           $path    download path
     */
    protected function doDownload(PackageInterface $package, $path) {
        $this->filesystem->ensureDirectoryExists($path);

        $packages= $package->getRepository()->getPackages();
        /* @var $package PackageInterface */
        if (!$packages) {
            throw new \Exception($package->getName()." has no packages defined!");
        }
        $package= array_shift($packages);
        
        $destination= $this->getFileName($package, $path);
        $source= $package->getSourceUrl();
        
        // if we are on a *nix system, we can use 'cp'
        if (false === strpos(PHP_OS, 'WIN')) {
            // r= recursive
            // s= symlink
            // T= treat destination as the real destination, not a folder to put the source into
            exec('cp -rsT '.$source.' '.rtrim($destination, DIRECTORY_SEPARATOR));
        } else {
            $this->io->write("  > <info>[____]</info>\r", false);
            $this->io->write("  > <info>[</info>", false);
            $folders= $this->getFoldersToCreate($source);
            $this->io->write("<comment>X</comment>", false);
            $files= $this->getFilesToSymlink($source);
            $this->io->write("<comment>X</comment>", false);

            // Does not work: $this->symlinkFolders($destination, $folders);
            $this->createFolders($destination, $folders);
            $this->io->write("<comment>X</comment>", false);
            $this->symlinkFiles($destination, $files);
            $this->io->write("<comment>X</comment>", false);
            $this->io->write("<info>]</info>");
        }
    }
    
    /**
     * Returns a list of foldernames to create
     * @param string source
     * @return string[]
     */
    protected function getFoldersToCreate($source) {
        $foldersToCreate= array();
        
        $ph= popen('find -P '.$source.' -type d | grep -v "\\.git" | grep -v "\\.svn"', 'r');
	    do {
		    $line= trim(fgets($ph));
		    if ($line) {
			    $linkname= str_replace($source, '', $line);
			    $linkname= ltrim($linkname, DIRECTORY_SEPARATOR);
                if (!trim($linkname)) continue;
                $foldersToCreate[]= $linkname;
		    }
	    } while (!feof($ph));
	    fclose($ph);
        
        return array_unique($foldersToCreate);
    }
    
    /**
     * Returns a list of files to symlink
     * 
     * Like this:
     * array(
     *   'some/folder/file.php' => '/home/gamepay/repos/src/some/folder/file.php
     * );
     *
     * @param string source
     * @return string[]
     */
    protected function getFilesToSymlink($source) {
        $filesToCreate= array();
        
        // $ph= @popen('find -P '.$source.' -type f -o -type l | grep -v ".svn" | grep -v ".gitignore"', 'r');
        $ph= popen('find -P '.$source.' -type f -o -type l ', 'r');
        // $ph= popen('find -P '.$source.' -type f -o -type l', 'r');
	    do {
		    $linkSource= @trim(fgets($ph));
		    if ($linkSource) {
                // ignore .git-directories
                if (FALSE !== strpos($linkSource, DIRECTORY_SEPARATOR.'.git'.DIRECTORY_SEPARATOR)) continue;
                if (FALSE !== strpos($linkSource, DIRECTORY_SEPARATOR.'.svn'.DIRECTORY_SEPARATOR)) continue;
                if (FALSE !== strpos($linkSource, '.gitignore')) continue;
                
                
			    $linkname= str_replace($source, '', $linkSource);
			    $linkname= ltrim($linkname, DIRECTORY_SEPARATOR);
                
                $filesToCreate[$linkname]= $linkSource;
		    }
	    } while (!feof($ph));
	    fclose($ph);
        
        return $filesToCreate;
    }
    
    /**
     * Creates the folders in the given destinationDirectory
     * @param string destination
     * @param string[] folders 
     */
    protected function createFolders($destinationDirectory, Array $folders) {
        foreach ($folders as $folder) {
            mkdir($destinationDirectory.$folder);
        }
    }
    
    /**
     * Creates the folders in the given destinationDirectory
     * @param string destination
     * @param string[] folders 
     */
    protected function symlinkFolders($destinationDirectory, Array $folders) {
        foreach ($folders as $folder) {
            exec('ln -s "'.$folder.'" "'.$destinationDirectory.$folder.'" -f');
        }
    }
    
    /**
     * Creates the symlinks in the given destinationDirectory
     * @param string destination
     * @param string[] filesToSymlink
     */
    protected function symlinkFiles($destinationDirectory, Array $filesToSymlink) {
        foreach ($filesToSymlink as $linkname => $linksource) {
            // exec('ln -s "'.$linksource.'" "'.$destinationDirectory.$linkname.'" -f');
            symlink($destinationDirectory.$linkname, $linksource);
        }
    }

    /**
     * Updates specific package in specific folder from initial to target version.
     *
     * @param PackageInterface $initial initial package
     * @param PackageInterface $target  updated package
     * @param string           $path    download path
     */
    protected function doUpdate(PackageInterface $initial, PackageInterface $target, $path) {
        $this->remove($initial, $path);
        $this->download($target, $path);
    }

    /**
     * Checks for changes to the local copy
     *
     * @param  string      $path package directory
     * @return string|null changes or null
     */
    public function getLocalChanges($path) {
        return null;
    }

    /**
     * Fetches the commit logs between two commits
     *
     * @param  string $fromReference the source reference
     * @param  string $toReference   the target reference
     * @param  string $path          the package path
     * @return string
     */
    protected function getCommitLogs($fromReference, $toReference, $path) {
        throw new \Exception("Not implemented");
    }
    
    /**
     * Gets file name for specific package
     *
     * @param  PackageInterface $package package instance
     * @param  string           $path    download path
     * @return string           file name
     */
    protected function getFileName(PackageInterface $package, $path)
    {
        return $path.'/'.pathinfo(parse_url($package->getDistUrl(), PHP_URL_PATH), PATHINFO_BASENAME);
    }
}