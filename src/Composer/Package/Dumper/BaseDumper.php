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
        $name = preg_replace('[^a-z0-9_-]', '-', $package->getUniqueName());
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