<?php
/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Package\Dumper;

use Composer\Package\PackageInterface;
use Composer\Util\ProcessExecutor;
use Composer\Downloader\GitDownloader;
use Composer\IO\NullIO;

/**
 * @author Till Klampaeckel <till@php.net>
 */
class BaseDumper
{
    /**
     * Format: zip or tarball.
     * @var string
     */
    protected $format = '';

    /**
     * @var array
     */
    static $keys = array(
        'binaries' => 'bin',
        'scripts',
        'type',
        'extra',
        'installationSource' => 'installation-source',
        'license',
        'authors',
        'description',
        'homepage',
        'keywords',
        'autoload',
        'repositories',
        'includePaths' => 'include-path',
        'support',
    );

    /**
     * Path to where to dump the export to.
     * @var mixed|null
     */
    protected $path;

    /**
     * @var \Composer\Util\ProcessExecutor
     */
    protected $process;

    /**
     * Working directory.
     * @var string
     */
    protected $temp;

    /**
     * @param mixed                               $path
     * @param \Composer\Util\ProcessExecutor|null $process
     *
     * @return \Composer\Package\Dumper\BaseDumper
     * @throws \InvalidArgumentException
     */
    public function __construct($path = null, ProcessExecutor $process = null)
    {
        if (!empty($path)) {
            if (!is_writable($path)) {
                throw new \InvalidArgumentException("Not authorized to write to '{$path}'");
            }
            $this->path    = $path;
        }
        $this->process = ($process !== null)?$process:new ProcessExecutor;
        $this->temp    = sys_get_temp_dir();
    }

    /**
     * @param \Composer\Package\PackageInterface $package
     * @param string                             $extension
     *
     * @return string
     * @throws \InvalidArgumentException When unknown 'format' is encountered.
     */
    public function getFilename(PackageInterface $package, $extension)
    {
        $name = preg_replace('#[^a-z0-9_-]#', '-', $package->getUniqueName());
        $fileName = sprintf('%s.%s',
            $name,
            $extension
        );
        return $fileName;
    }

    /**
     * @param \Composer\Package\PackageInterface $package
     * @param string                             $workDir
     */
    protected function downloadGit(PackageInterface $package, $workDir)
    {
        $downloader = new GitDownloader(
            new NullIO(),
            $this->process
        );
        $downloader->download($package, $workDir);
    }

    protected function downloadHg(PackageInterface $package, $workDir)
    {
        throw new \DomainException("Not yet implemented.");
    }

    protected function downloadSvn(PackageInterface $package, $workDir)
    {
        throw new \DomainException("Not yet implemented.");
    }

    protected function getAndEnsureWorkDirectory(PackageInterface $package)
    {
        $workDir = sprintf('%s/%s/%s', $this->temp, $this->format, $package->getName());
        if (!file_exists($workDir)) {
            mkdir($workDir, 0777, true);
        }
        if (!file_exists($workDir)) {
            throw new \RuntimeException("Could not find '{$workDir}' directory.");
        }
        return $workDir;
    }

    /**
     * Package the given directory into an archive.
     *
     * The format is most likely \Phar::TAR or \Phar::ZIP.
     *
     * @param string $filename
     * @param string $workDir
     * @param int    $format
     *
     * @throws \RuntimeException
     */
    protected function package($filename, $workDir, $format)
    {
        try {
            $phar = new \PharData($filename, null, null, $format);
            $phar->buildFromDirectory($workDir);
        } catch (\UnexpectedValueException $e) {
            $message  = "Original PHAR exception: " . (string) $e;
            $message .= PHP_EOL . PHP_EOL;
            $message .= sprintf("Could not create archive '%s' from '%s'.", $filename, $workDir);
            throw new \RuntimeException($message);
        }
    }

    /**
     * @param string $fileName
     * @param string $sourceRef
     * @param string $workDir
     */
    protected function packageGit($fileName, $sourceRef, $workDir)
    {
        $command = sprintf(
            'git archive --format %s --output %s %s',
            $this->format,
            escapeshellarg(sprintf('%s/%s', $this->path, $fileName)),
            $sourceRef
        );
        $this->process->execute($command, $output, $workDir);
    }
}