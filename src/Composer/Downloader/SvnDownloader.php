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

use Composer\Package\PackageInterface;
use Composer\Pcre\Preg;
use Composer\Util\Svn as SvnUtil;
use Composer\Repository\VcsRepository;
use React\Promise\PromiseInterface;

/**
 * @author Ben Bieker <mail@ben-bieker.de>
 * @author Till Klampaeckel <till@php.net>
 */
class SvnDownloader extends VcsDownloader
{
    /** @var bool */
    protected $cacheCredentials = true;

    /**
     * @inheritDoc
     */
    protected function doDownload(PackageInterface $package, string $path, string $url, ?PackageInterface $prevPackage = null): PromiseInterface
    {
        SvnUtil::cleanEnv();
        $util = new SvnUtil($url, $this->io, $this->config, $this->process);
        if (null === $util->binaryVersion()) {
            throw new \RuntimeException('svn was not found in your PATH, skipping source download');
        }

        return \React\Promise\resolve(null);
    }

    /**
     * @inheritDoc
     */
    protected function doInstall(PackageInterface $package, string $path, string $url): PromiseInterface
    {
        SvnUtil::cleanEnv();
        $ref = $package->getSourceReference();

        $repo = $package->getRepository();
        if ($repo instanceof VcsRepository) {
            $repoConfig = $repo->getRepoConfig();
            if (array_key_exists('svn-cache-credentials', $repoConfig)) {
                $this->cacheCredentials = (bool) $repoConfig['svn-cache-credentials'];
            }
        }

        $this->io->writeError(" Checking out ".$package->getSourceReference());
        $this->execute($package, $url, ['svn', 'co'], sprintf("%s/%s", $url, $ref), null, $path);

        return \React\Promise\resolve(null);
    }

    /**
     * @inheritDoc
     */
    protected function doUpdate(PackageInterface $initial, PackageInterface $target, string $path, string $url): PromiseInterface
    {
        SvnUtil::cleanEnv();
        $ref = $target->getSourceReference();

        if (!$this->hasMetadataRepository($path)) {
            throw new \RuntimeException('The .svn directory is missing from '.$path.', see https://getcomposer.org/commit-deps for more information');
        }

        $util = new SvnUtil($url, $this->io, $this->config, $this->process);
        $flags = [];
        if (version_compare($util->binaryVersion(), '1.7.0', '>=')) {
            $flags[] = '--ignore-ancestry';
        }

        $this->io->writeError(" Checking out " . $ref);
        $this->execute($target, $url, array_merge(['svn', 'switch'], $flags), sprintf("%s/%s", $url, $ref), $path);

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

        $this->process->execute(['svn', 'status', '--ignore-externals'], $output, $path);

        return Preg::isMatch('{^ *[^X ] +}m', $output) ? $output : null;
    }

    /**
     * Execute an SVN command and try to fix up the process with credentials
     * if necessary.
     *
     * @param  string            $baseUrl Base URL of the repository
     * @param  non-empty-list<string> $command SVN command to run
     * @param  string            $url     SVN url
     * @param  string            $cwd     Working directory
     * @param  string            $path    Target for a checkout
     * @throws \RuntimeException
     */
    protected function execute(PackageInterface $package, string $baseUrl, array $command, string $url, ?string $cwd = null, ?string $path = null): string
    {
        $util = new SvnUtil($baseUrl, $this->io, $this->config, $this->process);
        $util->setCacheCredentials($this->cacheCredentials);
        try {
            return $util->execute($command, $url, $cwd, $path, $this->io->isVerbose());
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(
                $package->getPrettyName().' could not be downloaded, '.$e->getMessage()
            );
        }
    }

    /**
     * @inheritDoc
     */
    protected function cleanChanges(PackageInterface $package, string $path, bool $update): PromiseInterface
    {
        if (null === ($changes = $this->getLocalChanges($package, $path))) {
            return \React\Promise\resolve(null);
        }

        if (!$this->io->isInteractive()) {
            if (true === $this->config->get('discard-changes')) {
                return $this->discardChanges($path);
            }

            return parent::cleanChanges($package, $path, $update);
        }

        $changes = array_map(static function ($elem): string {
            return '    '.$elem;
        }, Preg::split('{\s*\r?\n\s*}', $changes));
        $countChanges = count($changes);
        $this->io->writeError(sprintf('    <error>'.$package->getPrettyName().' has modified file%s:</error>', $countChanges === 1 ? '' : 's'));
        $this->io->writeError(array_slice($changes, 0, 10));
        if ($countChanges > 10) {
            $remainingChanges = $countChanges - 10;
            $this->io->writeError(
                sprintf(
                    '    <info>'.$remainingChanges.' more file%s modified, choose "v" to view the full list</info>',
                    $remainingChanges === 1 ? '' : 's'
                )
            );
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
                    $this->io->writeError([
                        '    y - discard changes and apply the '.($update ? 'update' : 'uninstall'),
                        '    n - abort the '.($update ? 'update' : 'uninstall').' and let you manually clean things up',
                        '    v - view modified files',
                        '    ? - print help',
                    ]);
                    break;
            }
        }

        return \React\Promise\resolve(null);
    }

    /**
     * @inheritDoc
     */
    protected function getCommitLogs(string $fromReference, string $toReference, string $path): string
    {
        if (Preg::isMatch('{@(\d+)$}', $fromReference) && Preg::isMatch('{@(\d+)$}', $toReference)) {
            // retrieve the svn base url from the checkout folder
            $command = ['svn', 'info', '--non-interactive', '--xml', '--', $path];
            if (0 !== $this->process->execute($command, $output, $path)) {
                throw new \RuntimeException(
                    'Failed to execute ' . implode(' ', $command) . "\n\n" . $this->process->getErrorOutput()
                );
            }

            $urlPattern = '#<url>(.*)</url>#';
            if (Preg::isMatchStrictGroups($urlPattern, $output, $matches)) {
                $baseUrl = $matches[1];
            } else {
                throw new \RuntimeException(
                    'Unable to determine svn url for path '. $path
                );
            }

            // strip paths from references and only keep the actual revision
            $fromRevision = Preg::replace('{.*@(\d+)$}', '$1', $fromReference);
            $toRevision = Preg::replace('{.*@(\d+)$}', '$1', $toReference);

            $command = ['svn', 'log', '-r', $fromRevision.':'.$toRevision, '--incremental'];

            $util = new SvnUtil($baseUrl, $this->io, $this->config, $this->process);
            $util->setCacheCredentials($this->cacheCredentials);
            try {
                return $util->executeLocal($command, $path, null, $this->io->isVerbose());
            } catch (\RuntimeException $e) {
                throw new \RuntimeException(
                    'Failed to execute ' . implode(' ', $command) . "\n\n".$e->getMessage()
                );
            }
        }

        return "Could not retrieve changes between $fromReference and $toReference due to missing revision information";
    }

    /**
     * @phpstan-return PromiseInterface<void|null>
     */
    protected function discardChanges(string $path): PromiseInterface
    {
        if (0 !== $this->process->execute(['svn', 'revert', '-R', '.'], $output, $path)) {
            throw new \RuntimeException("Could not reset changes\n\n:".$this->process->getErrorOutput());
        }

        return \React\Promise\resolve(null);
    }

    /**
     * @inheritDoc
     */
    protected function hasMetadataRepository(string $path): bool
    {
        return is_dir($path.'/.svn');
    }
}
