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

namespace Composer\Compiler;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\IO\IOInterface;

/**
 * The Compiler class compiles a project into a phar
 *
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Robert Schönthal <seroscho@googlemail.com>
 */
abstract class Compiler
{
    protected $basePath;
    protected $archiveType;
    protected $stubFile;
    protected $version;
    protected $io;

    /**
     * fetches a list of project files
     *
     * @return Finder
     */
    abstract protected function getProjectFiles();

    /**
     * fetches a list of vendor files
     *
     * @return Finder
     */
    abstract protected function getVendorFiles();

    /**
     * adds the binary to bet executed
     */
    abstract protected function addBinary($phar);

    /**
     * @param $basePath the base path of the project
     * @param $stub the stub file to be set in phar file
     */
    public function __construct($basePath, $stub)
    {
        $this->basePath = $basePath;
        $this->stubFile = $stub;
    }

    /**
     * Compiles a project into a single phar file
     *
     * @throws \RuntimeException
     * @param string $pharFile The full path to the file to create
     */
    public function compile($pharFile)
    {
        if (file_exists($pharFile)) {
            unlink($pharFile);
        }

        $this->version = $this->getVersion();

        $phar = $this->createPhar($pharFile);

        if ($this->archiveType) {
            $this->createArchive($phar, $pharFile);
        }

        unset($phar);
    }

    /**
     * creates the phar file
     *
     * @param string $pharFile
     * @return \Phar
     */
    private function createPhar($pharFile)
    {
        $phar = new \Phar($pharFile, 0, $pharFile);
        //$phar->setSignatureAlgorithm(\Phar::SHA1);

        $phar->startBuffering();

        //add all project files
        foreach ($this->getProjectFiles() as $file) {
            $this->addFile($phar, $file, false);
        }

        // add all vendors
        foreach ($this->getVendorFiles() as $file) {
            $this->addFile($phar, $file);
        }

        // add autoload files
        foreach ($this->getAutoloadFiles() as $file) {
            $this->addFile($phar, $file);
        }

        //add the primary executable
        $this->addBinary($phar);

        // Stubs
        $phar->setStub($this->getStub());

        // add Licence File
        $this->addLicence($phar);

        $phar->stopBuffering();

        // disabled for interoperability with systems without gzip ext
        // $phar->compressFiles(\Phar::GZ);

        return $phar;
    }

    /**
     * reads the version from vcs if no version is present
     *
     * @return string
     * @throws \RuntimeException
     */
    protected function getVersion()
    {
        if ($this->version) {
            return $this->version;
        }

        if (is_readable($this->basePath . DIRECTORY_SEPARATOR . '.git')) {
            $process = new Process('git log --pretty="%h" -n1 HEAD', __DIR__);
            if ($process->run() != 0) {
                throw new \RuntimeException('Can\'t run git log. You must ensure to run compile from composer git repository clone and that git binary is available.');
            }
            return trim($process->getOutput());

            $process = new Process('git describe --tags HEAD');
            if ($process->run() == 0) {
                return trim($process->getOutput());
            }
        }
        //TODO other vcs systems
    }

    /**
     * creates an archive out of the phar file
     *
     * @param \Phar  $phar
     * @param string $pharFile
     */
    private function createArchive(\Phar $phar, $pharFile)
    {
        switch (strtolower($this->archiveType))
        {
            case 'zip' :
                @unlink($this->basePath . DIRECTORY_SEPARATOR . str_replace('.phar', '.zip', $pharFile));
                $phar->convertToExecutable(\Phar::ZIP);
                break;
            case 'tar' :
                @unlink($this->basePath . DIRECTORY_SEPARATOR . str_replace('.phar', '.tar', $pharFile));
                $phar->convertToExecutable(\Phar::TAR);
                break;
        }
    }

    /**
     * adds a file the the phar
     *
     * @param \Phar   $phar
     * @param string  $file
     * @param boolean $strip strip whitespaces?
     */
    private function addFile(\Phar $phar, $file, $strip = true)
    {
        $this->log($file);
        $path = str_replace(realpath($this->basePath) . DIRECTORY_SEPARATOR, '', $file->getRealPath());

        $content = file_get_contents($file);
        if ($strip && 'php' == pathinfo($file, PATHINFO_EXTENSION)) {
            $content = $this->stripWhitespace($content);
        } elseif ('LICENSE' === basename($file)) {
            $content = "\n" . $content . "\n";
        }

        $content = str_replace('@package_version@', $this->version, $content);

        $phar->addFromString($path, $content);
    }

    /**
     * Removes whitespace from a PHP source string while preserving line numbers.
     *
     * @param string $source A PHP string
     * @return string The PHP string with the whitespace removed
     */
    private function stripWhitespace($source)
    {
        if (!function_exists('token_get_all')) {
            return $source;
        }

        $output = '';
        foreach (token_get_all($source) as $token) {
            if (is_string($token)) {
                $output .= $token;
            } elseif (in_array($token[0], array(T_COMMENT, T_DOC_COMMENT))) {
                $output .= str_repeat("\n", substr_count($token[1], "\n"));
            } elseif (T_WHITESPACE === $token[0]) {
                // reduce wide spaces
                $whitespace = preg_replace('{[ \t]+}', ' ', $token[1]);
                // normalize newlines to \n
                $whitespace = preg_replace('{(?:\r\n|\r|\n)}', "\n", $whitespace);
                // trim leading spaces
                $whitespace = preg_replace('{\n +}', "\n", $whitespace);
                $output .= $whitespace;
            } else {
                $output .= $token[1];
            }
        }

        return $output;
    }

    /**
     * creates a finder with basic ignores
     *
     * @return \Symfony\Component\Finder\Finder
     */
    protected function getFinder()
    {
        return Finder::create()
            ->ignoreVCS(true)
            ->notName('composer.phar')
            ->exclude($this->getIgnores());
    }

    /**
     * reads ignores from vcs
     *
     * @return array
     */
    private function getIgnores()
    {
        //TODO other vcs ignores
        $ignores = explode("\n", file_get_contents($this->basePath . DIRECTORY_SEPARATOR . '.gitignore'));

        foreach ($ignores as $key => $ignore) {
            $ignore = trim($ignore);
            if (strpos($ignore, DIRECTORY_SEPARATOR) === 0) {
                $ignore = substr($ignore, 1);
            }

            if (!$ignore) {
                unset($ignores[$key]);
            }
        }

        return $ignores;
    }

    /**
     * returns the stub for this phar
     *
     * @return string
     * @throws \InvalidArgumentException
     */
    protected function getStub()
    {
        if (!is_readable($this->stubFile)) {
            throw new \InvalidArgumentException('unable to read stub file ' . $this->stubFile);
        }

        return file_get_contents($this->stubFile);
    }

    /**
     * add the licence file
     *
     * @param \Phar $phar
     */
    protected function addLicence(\Phar $phar)
    {
        if (is_readable($this->basePath . '/LICENSE')) {
            $this->addFile($phar, new \SplFileInfo($this->basePath . '/LICENSE'), false);
        }
    }

    /**
     * returns the composer autoloader files
     *
     * @return \Symfony\Component\Finder\Finder
     */
    protected function getAutoloadFiles()
    {
        return $this->getFinder()
            ->name('*.php')
            ->in($this->basePath . '/vendor/.composer');
    }

    /**
     * also creates a zip or tar along with the phar
     *
     * @param string $archive
     * @return Compiler
     */
    public function setArchiveType($archive)
    {
        $this->archiveType = $archive;

        return $this;
    }

    /**
     * manually set the version
     *
     * @param string $version
     * @return Compiler
     */
    public function setVersion($version)
    {
        $this->version = $version;

        return $this;
    }

    /**
     * sets console io
     *
     * @param \Composer\IO\IOInterface $io
     * @return Compiler
     */
    public function setIO(IOInterface $io)
    {
        $this->io = $io;

        return $this;
    }

    /**
     * logs a message to the output
     *
     * @param $message
     */
    private function log($message)
    {
        if ($this->io) {
            $this->io->write(str_replace($this->basePath, '', $message));
        }
    }
}