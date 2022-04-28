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

namespace Composer\Repository\Vcs;

use Composer\Config;
use Composer\Cache;
use Composer\IO\IOInterface;
use Composer\Pcre\Preg;
use Composer\Util\ProcessExecutor;
use Composer\Util\Perforce;
use Composer\Util\Http\Response;

/**
 * @author Matt Whittom <Matt.Whittom@veteransunited.com>
 */
class PerforceDriver extends VcsDriver
{
    /** @var string */
    protected $depot;
    /** @var string */
    protected $branch;
    /** @var ?Perforce */
    protected $perforce = null;

    /**
     * @inheritDoc
     */
    public function initialize(): void
    {
        $this->depot = $this->repoConfig['depot'];
        $this->branch = '';
        if (!empty($this->repoConfig['branch'])) {
            $this->branch = $this->repoConfig['branch'];
        }

        $this->initPerforce($this->repoConfig);
        $this->perforce->p4Login();
        $this->perforce->checkStream();

        $this->perforce->writeP4ClientSpec();
        $this->perforce->connectClient();
    }

    /**
     * @param array<string, mixed> $repoConfig
     *
     * @return void
     */
    private function initPerforce(array $repoConfig): void
    {
        if (!empty($this->perforce)) {
            return;
        }

        if (!Cache::isUsable($this->config->get('cache-vcs-dir'))) {
            throw new \RuntimeException('PerforceDriver requires a usable cache directory, and it looks like you set it to be disabled');
        }

        $repoDir = $this->config->get('cache-vcs-dir') . '/' . $this->depot;
        $this->perforce = Perforce::create($repoConfig, $this->getUrl(), $repoDir, $this->process, $this->io);
    }

    /**
     * @inheritDoc
     */
    public function getFileContent(string $file, string $identifier): ?string
    {
        return $this->perforce->getFileContent($file, $identifier);
    }

    /**
     * @inheritDoc
     */
    public function getChangeDate(string $identifier): ?\DateTimeImmutable
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function getRootIdentifier(): string
    {
        return $this->branch;
    }

    /**
     * @inheritDoc
     */
    public function getBranches(): array
    {
        return $this->perforce->getBranches();
    }

    /**
     * @inheritDoc
     */
    public function getTags(): array
    {
        return $this->perforce->getTags();
    }

    /**
     * @inheritDoc
     */
    public function getDist(string $identifier): ?array
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function getSource(string $identifier): array
    {
        return array(
            'type' => 'perforce',
            'url' => $this->repoConfig['url'],
            'reference' => $identifier,
            'p4user' => $this->perforce->getUser(),
        );
    }

    /**
     * @inheritDoc
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * @inheritDoc
     */
    public function hasComposerFile(string $identifier): bool
    {
        $composerInfo = $this->perforce->getComposerInformation('//' . $this->depot . '/' . $identifier);

        return !empty($composerInfo);
    }

    /**
     * @inheritDoc
     */
    public function getContents(string $url): Response
    {
        throw new \BadMethodCallException('Not implemented/used in PerforceDriver');
    }

    /**
     * @inheritDoc
     */
    public static function supports(IOInterface $io, Config $config, string $url, bool $deep = false): bool
    {
        if ($deep || Preg::isMatch('#\b(perforce|p4)\b#i', $url)) {
            return Perforce::checkServerExists($url, new ProcessExecutor($io));
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function cleanup(): void
    {
        $this->perforce->cleanupClientSpec();
        $this->perforce = null;
    }

    /**
     * @return string
     */
    public function getDepot(): string
    {
        return $this->depot;
    }

    /**
     * @return string
     */
    public function getBranch(): string
    {
        return $this->branch;
    }
}
