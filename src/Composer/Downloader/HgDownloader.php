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
use Composer\Util\ProcessExecutor;
use Composer\Util\Hg as HgUtils;

/**
 * @author Per Bernhardt <plb@webfactory.de>
 */
class HgDownloader extends VcsDownloader
{
    /**
     * @inheritDoc
     */
    protected function doDownload(PackageInterface $package, string $path, string $url, ?PackageInterface $prevPackage = null): PromiseInterface
    {
        if (null === HgUtils::getVersion($this->process)) {
            throw new \RuntimeException('hg was not found in your PATH, skipping source download');
        }

        return \React\Promise\resolve(null);
    }

    /**
     * @inheritDoc
     */
    protected function doInstall(PackageInterface $package, string $path, string $url): PromiseInterface
    {
        $hgUtils = new HgUtils($this->io, $this->config, $this->process);

        $cloneCommand = static function (string $url) use ($path): array {
            return ['hg', 'clone', '--', $url, $path];
        };

        $hgUtils->runCommand($cloneCommand, $url, $path);

        $command = ['hg', 'up', '--', $package->getSourceReference()];
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
        $hgUtils = new HgUtils($this->io, $this->config, $this->process);

        $ref = $target->getSourceReference();
        $this->io->writeError(" Updating to ".$target->getSourceReference());

        if (!$this->hasMetadataRepository($path)) {
            throw new \RuntimeException('The .hg directory is missing from '.$path.', see https://getcomposer.org/commit-deps for more information');
        }

        $command = static function ($url): array {
            return ['hg', 'pull', '--', $url];
        };
        $hgUtils->runCommand($command, $url, $path);

        $command = static function () use ($ref): array {
            return ['hg', 'up', '--', $ref];
        };
        $hgUtils->runCommand($command, $url, $path);

        return \React\Promise\resolve(null);
    }

    /**
     * @inheritDoc
     */
    public function getLocalChanges(PackageInterface $package, string $path): ?string
    {
        if (!is_dir($path.'/.hg')) {
            return null;
        }

        $this->process->execute(['hg', 'st'], $output, realpath($path));

        $output = trim($output);

        return strlen($output) > 0 ? $output : null;
    }

    /**
     * @inheritDoc
     */
    protected function getCommitLogs(string $fromReference, string $toReference, string $path): string
    {
        $command = ['hg', 'log', '-r', $fromReference.':'.$toReference, '--style', 'compact'];

        if (0 !== $this->process->execute($command, $output, realpath($path))) {
            throw new \RuntimeException('Failed to execute ' . implode(' ', $command) . "\n\n" . $this->process->getErrorOutput());
        }

        return $output;
    }

    /**
     * @inheritDoc
     */
    protected function hasMetadataRepository(string $path): bool
    {
        return is_dir($path . '/.hg');
    }
}
