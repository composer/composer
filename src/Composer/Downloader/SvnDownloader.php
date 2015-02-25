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

namespace Composer\Downloader;

use Composer\Package\PackageInterface;
use Composer\Util\Svn as SvnUtil;

/**
 * @author Ben Bieker <mail@ben-bieker.de>
 * @author Till Klampaeckel <till@php.net>
 */
class SvnDownloader extends VcsDownloader
{
    /**
     * {@inheritDoc}
     */
    public function doDownload(PackageInterface $package, $path, $url)
    {
        SvnUtil::cleanEnv();
        $ref = $package->getSourceReference();

        $this->io->writeError("    Checking out ".$package->getSourceReference());
        $this->execute($url, "svn co", sprintf("%s/%s", $url, $ref), null, $path);
    }

    /**
     * {@inheritDoc}
     */
    public function doUpdate(PackageInterface $initial, PackageInterface $target, $path, $url)
    {
        SvnUtil::cleanEnv();
        $ref = $target->getSourceReference();

        if (!is_dir($path.'/.svn')) {
            throw new \RuntimeException('The .svn directory is missing from '.$path.', see http://getcomposer.org/commit-deps for more information');
        }

        $flags = "";
        if (0 === $this->process->execute('svn --version', $output)) {
            if (preg_match('{(\d+(?:\.\d+)+)}', $output, $match) && version_compare($match[1], '1.7.0', '>=')) {
                $flags .= ' --ignore-ancestry';
            }
        }

        $this->io->writeError("    Checking out " . $ref);
        $this->execute($url, "svn switch" . $flags, sprintf("%s/%s", $url, $ref), $path);
    }

    /**
     * {@inheritDoc}
     */
    public function getLocalChanges(PackageInterface $package, $path)
    {
        if (!is_dir($path.'/.svn')) {
            return;
        }

        $this->process->execute('svn status --ignore-externals', $output, $path);

        return preg_match('{^ *[^X ] +}m', $output) ? $output : null;
    }

    /**
     * Execute an SVN command and try to fix up the process with credentials
     * if necessary.
     *
     * @param  string            $baseUrl Base URL of the repository
     * @param  string            $command SVN command to run
     * @param  string            $url     SVN url
     * @param  string            $cwd     Working directory
     * @param  string            $path    Target for a checkout
     * @throws \RuntimeException
     * @return string
     */
    protected function execute($baseUrl, $command, $url, $cwd = null, $path = null)
    {
        $util = new SvnUtil($baseUrl, $this->io, $this->config);
        try {
            return $util->execute($command, $url, $cwd, $path, $this->io->isVerbose());
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(
                'Package could not be downloaded, '.$e->getMessage()
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function cleanChanges(PackageInterface $package, $path, $update)
    {
        if (!$changes = $this->getLocalChanges($package, $path)) {
            return;
        }

        if (!$this->io->isInteractive()) {
            if (true === $this->config->get('discard-changes')) {
                return $this->discardChanges($path);
            }

            return parent::cleanChanges($package, $path, $update);
        }

        $changes = array_map(function ($elem) {
            return '    '.$elem;
        }, preg_split('{\s*\r?\n\s*}', $changes));
        $this->io->writeError('    <error>The package has modified files:</error>');
        $this->io->writeError(array_slice($changes, 0, 10));
        if (count($changes) > 10) {
            $this->io->writeError('    <info>'.count($changes) - 10 . ' more files modified, choose "v" to view the full list</info>');
        }

        while (true) {
            switch ($this->io->ask('    <info>Discard changes [y,n,v,?]?</info> ', '?')) {
                case 'y':
                    $this->discardChanges($path);
                    break 2;

                case 'n':
                    throw new \RuntimeException('Update aborted');

                case 'v':
                    $this->io->writeError($changes);
                    break;

                case '?':
                default:
                    $this->io->writeError(array(
                        '    y - discard changes and apply the '.($update ? 'update' : 'uninstall'),
                        '    n - abort the '.($update ? 'update' : 'uninstall').' and let you manually clean things up',
                        '    v - view modified files',
                        '    ? - print help',
                    ));
                    break;
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function getCommitLogs($fromReference, $toReference, $path)
    {
        if (preg_match('{.*@(\d+)$}', $fromReference) && preg_match('{.*@(\d+)$}', $toReference) ) {
            // strip paths from references and only keep the actual revision
            $fromRevision = preg_replace('{.*@(\d+)$}', '$1', $fromReference);
            $toRevision = preg_replace('{.*@(\d+)$}', '$1', $toReference);

            $command = sprintf('svn log -r%s:%s --incremental', $fromRevision, $toRevision);

            if (0 !== $this->process->execute($command, $output, $path)) {
                throw new \RuntimeException(
                    'Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput()
                );
            }
        } else {
            $output = "Could not retrieve changes between $fromReference and $toReference due to missing revision information";
        }

        return $output;
    }

    protected function discardChanges($path)
    {
        if (0 !== $this->process->execute('svn revert -R .', $output, $path)) {
            throw new \RuntimeException("Could not reset changes\n\n:".$this->process->getErrorOutput());
        }
    }
}
