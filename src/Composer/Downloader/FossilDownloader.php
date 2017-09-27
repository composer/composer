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
use Composer\Util\ProcessExecutor;

/**
 * @author BohwaZ <http://bohwaz.net/>
 */
class FossilDownloader extends VcsDownloader
{
    /**
     * {@inheritDoc}
     */
    public function doDownload(PackageInterface $package, $path, $url)
    {
        // Ensure we are allowed to use this URL by config
        $this->config->prohibitUrlByConfig($url, $this->io);

        $ref = ProcessExecutor::escape($package->getSourceReference());
        $repoFile = $path . '.fossil';
        $this->io->writeError("Cloning ".$package->getSourceReference());

        $command = $this->getCloneCommand($url, $repoFile);
        if (0 !== $this->process->execute($command, $ignoredOutput)) {
            throw new \RuntimeException('Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput());
        }

        $command = sprintf('fossil open %s', ProcessExecutor::escape($repoFile));
        if (0 !== $this->process->execute($command, $ignoredOutput, realpath($path))) {
            throw new \RuntimeException('Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput());
        }

        $command = sprintf('fossil update %s', $ref);
        if (0 !== $this->process->execute($command, $ignoredOutput, realpath($path))) {
            throw new \RuntimeException('Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function doUpdate(PackageInterface $initial, PackageInterface $target, $path, $url)
    {
        // Ensure we are allowed to use this URL by config
        $this->config->prohibitUrlByConfig($url, $this->io);

        $ref = ProcessExecutor::escape($target->getSourceReference());
        $this->io->writeError(" Updating to ".$target->getSourceReference());

        if (!$this->hasMetadataRepository($path)) {
            throw new \RuntimeException('The .fslckout file is missing from '.$path.', see https://getcomposer.org/commit-deps for more information');
        }

        $realpath = realpath($path);

        $command = $this->getPullCommand($url);
        if (0 !== $this->process->execute($command, $ignoredOutput, $realpath)) {
            throw new \RuntimeException('Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput());
        }

        $command = "fossil update {$ref}";
        if (0 !== $this->process->execute($command, $ignoredOutput, $realpath)) {
            throw new \RuntimeException('Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getLocalChanges(PackageInterface $package, $path)
    {
        if (!$this->hasMetadataRepository($path)) {
            return null;
        }

        $this->process->execute('fossil changes', $output, realpath($path));

        return trim($output) ?: null;
    }

    /**
     * {@inheritDoc}
     */
    protected function getCommitLogs($fromReference, $toReference, $path)
    {
        $command = sprintf('fossil timeline -t ci -W 0 -n 0 before %s', $toReference);

        if (0 !== $this->process->execute($command, $output, realpath($path))) {
            throw new \RuntimeException('Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput());
        }

        $log = '';
        $match = '/\d\d:\d\d:\d\d\s+\[' . $toReference . '\]/';

        foreach ($this->process->splitLines($output) as $line) {
            if (preg_match($match, $line)) {
                break;
            }
            $log .= $line;
        }

        return $log;
    }

    /**
     * {@inheritDoc}
     */
    protected function hasMetadataRepository($path)
    {
        return is_file($path . '/.fslckout') || is_file($path . '/_FOSSIL_');
    }

    /**
     * Find username and password for HTTP authentication, if they are defined.
     * The returned string is in the format expected by the fossil executable.
     */
    protected function getHttpBasicAuth($url)
    {
        $parsed = parse_url($url);

        // Only for http(s) URLs.
        if (!isset($parsed['scheme']) || ($parsed['scheme'] != 'http' && $parsed['scheme'] != 'https') || empty($parsed['host'])) {
            return '';
        }

        $httpBasicConfig = $this->config->get('http-basic');
        if (isset($httpBasicConfig[$parsed['host']])) {
            $creds = $httpBasicConfig[$parsed['host']];
            // We don't allow empty username or password. Both must be defined.
            if (!empty($creds['username']) && !empty($creds['password'])) {
                return sprintf('-B %s', ProcessExecutor::escape($creds['username'] .':'. $creds['password']));
            }
        }

        return '';
    }

    /**
     * To clone or pull from a remote fossil repo via HTTP URL, we might have to go through two authentications:
     *
     *   1. HTTP Basic authentication, if accessing the HTTP URL is so protected by the web server.
     *   2. Fossil authentication, if accessing the remote repo is not allowed for anonymous users.
     *
     * Fossil considers the username and password specified in the HTTP URL to be the credentials for Fossil authentication,
     * and provides a separate parameter for HTTP Basic authentication.
     *
     * As best practice, users should probably keep all their credentials in auth.json. The composer.json should
     * only have the URL to the remote repo as if it was accessible to the public without any credentials.
     *
     * This method manipulates the repo URL and adds fossil repository credentials to it.
     *
     * It is expected that credentials specific for each repository would be located in auth.json in the following structure:
     *
     * {
     *   "fossil-auth": {
     *     "host.com/path/to/repo": {
     *       "username": "my-user",
     *       "password": "my-pass"
     *     }
     *   }
     * }
     */
    protected function getUrlWithAuth($url)
    {
        $parsed = parse_url($url);

        // Only for http(s) URLs.
        if (!isset($parsed['scheme']) || ($parsed['scheme'] != 'http' && $parsed['scheme'] != 'https') || empty($parsed['host'])) {
            return $url;
        }

        $remoteRepo = $parsed['host'] . $parsed['path'];
        $authentications = $this->config->get('fossil-auth');

        if (isset($authentications[$remoteRepo])) {
            $creds = $authentications[$remoteRepo];

            // We don't care if username or password were provided in the URL.
            $url = sprintf(
                '%s://%s:%s@%s%s%s%s%s',
                $parsed['scheme'],
                $creds['username'],
                $creds['password'],
                $parsed['host'],
                isset($parsed['port']) ? ':'.$parsed['port'] : '',
                $parsed['path'],
                isset($parsed['query']) ? '?'.$parsed['query'] : '',
                isset($parsed['fragment']) ? '#'.$parsed['fragment'] : ''
            );
        }

        return $url;
    }

    /**
     * Returns the clone command.
     */
    protected function getCloneCommand($url, $repoFile)
    {
        return sprintf(
            'fossil clone --once %s %s %s',
            $this->getHttpBasicAuth($url),
            ProcessExecutor::escape($this->getUrlWithAuth($url)),
            ProcessExecutor::escape($repoFile)
        );
    }

    /**
     * Returns the pull command.
     */
    protected function getPullCommand($url)
    {
        return sprintf(
            'fossil pull %s --once %s',
            ProcessExecutor::escape($this->getUrlWithAuth($url)),
            $this->getHttpBasicAuth($url)
        );
    }
}
