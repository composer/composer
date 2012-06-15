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
     * Working directory.
     * @var string
     */
    protected $temp;

    /**
     * @param mixed $path
     *
     * @return $this
     * @throws \InvalidArgumentException
     */
    public function __construct($path = null)
    {
        if (!empty($path)) {
            if (!is_writable($path)) {
                throw new \InvalidArgumentException("Not authorized to write to '{$path}'");
            }
            $this->path = $path;
            $this->temp = sys_get_temp_dir();
        }
    }

    /**
     * @param \Composer\Package\PackageInterface $package
     *
     * @return string
     * @throws \InvalidArgumentException When unknown 'format' is encountered.
     */
    public function getFilename(PackageInterface $package)
    {
        switch ($this->format) {
        case 'tarball':
            $ext = 'tar';
            break;
        case 'zip':
            $ext = 'zip';
            break;
        default:
            throw new \InvalidArgumentException("Format '{$this->format}' is not supported.");
        }

        $fileName = sprintf('%s-%s.%s',
            $package->getPrettyName(),
            $package->getVersion(),
            $ext
        );
        return $fileName;
    }
}