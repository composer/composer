<?php declare(strict_types=1);

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

use React\Promise\PromiseInterface;
use Composer\Package\PackageInterface;
use Composer\Pcre\Preg;
use Composer\Util\ProcessExecutor;

/**
 * @author BohwaZ <http://bohwaz.net/>
 */
class FossilDownloader extends VcsDownloader
{
    /**
     * @inheritDoc
     */
    protected function doDownload(PackageInterface $package, string $path, string $url, ?PackageInterface $prevPackage = null): PromiseInterface
    {
        return \React\Promise\resolve(null);
    }

    /**
     * @inheritDoc
     */
    protected function doInstall(PackageInterface $package, string $path, string $url): PromiseInterface
    {
        // Ensure we are allowed to use this URL by config
        $this->config->prohibitUrlByConfig($url, $this->io);

        $repoFile = $path . '.fossil';
        $this->io->writeError("Cloning ".$package->getSourceReference());
        $command = ['fossil', 'clone', '--', $url, $repoFile];
        if (0 !== $this->process->execute($command, $ignoredOutput)) {
            throw new \RuntimeException('Failed to execute ' . implode(' ', $command) . "\n\n" . $this->process->getErrorOutput());
        }
        $command = ['fossil', 'open', '--nested', '--', $repoFile];
        if (0 !== $this->process->execute($command, $ignoredOutput, realpath($path))) {
            throw new \RuntimeException('Failed to execute ' . implode(' ', $command) . "\n\n" . $this->process->getErrorOutput());
        }
        $command = ['fossil', 'update', '--', $package->getSourceReference()];
        if (0 !== $this->process->execute($command, $ignoredOutput, realpath($path))) {
            throw new \RuntimeException('Failed to execute ' . implode(' ', $command) . "\n\n" . $this->process->getErrorOutput());
        }

        return \React\Promise\resolve(null);
    }

    /**
     * @inheritDoc
     */
    protected function doUpdate(PackageInterface $initial, PackageInterface $target, string $path, string $url): PromiseInterface
    {
        // Ensure we are allowed to use this URL by config
        $this->config->prohibitUrlByConfig($url, $this->io);

        $this->io->writeError(" Updating to ".$target->getSourceReference());

        if (!$this->hasMetadataRepository($path)) {
            throw new \RuntimeException('The .fslckout file is missing from '.$path.', see https://getcomposer.org/commit-deps for more information');
        }

        if (0 !== $this->process->execute(['fossil', 'pull'], $ignoredOutput, realpath($path))) {
            throw new \RuntimeException("Failed to execute fossil pull\n\n" . $this->process->getErrorOutput());
        }

        if (0 !== $this->process->execute(['fossil', 'up', $target->getSourceReference()], $ignoredOutput, realpath($path))) {
            throw new \RuntimeException("Failed to execute fossil up\n\n" . $this->process->getErrorOutput());
        }

        return \React\Promise\resolve(null);
    }

    /**
     * @inheritDoc
     */
    public function getLocalChanges(PackageInterface $package, string $path): ?string
    {
        if (!$this->hasMetadataRepository($path)) {
            return null;
        }

        $this->process->execute(['fossil', 'changes'], $output, realpath($path));

        $output = trim($output);

        return strlen($output) > 0 ? $output : null;
    }

    /**
     * @inheritDoc
     */
    protected function getCommitLogs(string $fromReference, string $toReference, string $path): string
    {
        $command = ['fossil', 'timeline', '-t', 'ci', '-W', '0', '-n', '0', 'before', $toReference];

        if (0 !== $this->process->execute($command, $output, realpath($path))) {
            throw new \RuntimeException('Failed to execute ' . implode(' ', $command) . "\n\n" . $this->process->getErrorOutput());
        }

        $log = '';
        $match = '/\d\d:\d\d:\d\d\s+\[' . $toReference . '\]/';

        foreach ($this->process->splitLines($output) as $line) {
            if (Preg::isMatch($match, $line)) {
                break;
            }
            $log .= $line;
        }

        return $log;
    }

    /**
     * @inheritDoc
     */
    protected function hasMetadataRepository(string $path): bool
    {
        return is_file($path . '/.fslckout') || is_file($path . '/_FOSSIL_');
    }
}
