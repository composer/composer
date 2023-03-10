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

namespace Composer\Package;

/**
 * Package containing additional metadata that is not used by the solver
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class CompletePackage extends Package implements CompletePackageInterface
{
    /** @var mixed[] */
    protected $repositories = [];
    /** @var string[] */
    protected $license = [];
    /** @var string[] */
    protected $keywords = [];
    /** @var array<array{name?: string, homepage?: string, email?: string, role?: string}> */
    protected $authors = [];
    /** @var ?string */
    protected $description = null;
    /** @var ?string */
    protected $homepage = null;
    /** @var array<string, string[]> Map of script name to array of handlers */
    protected $scripts = [];
    /** @var array{issues?: string, forum?: string, wiki?: string, source?: string, email?: string, irc?: string, docs?: string, rss?: string, chat?: string, security?: string} */
    protected $support = [];
    /** @var array<array{url?: string, type?: string}> */
    protected $funding = [];
    /** @var bool|string */
    protected $abandoned = false;
    /** @var ?string */
    protected $archiveName = null;
    /** @var string[] */
    protected $archiveExcludes = [];

    /**
     * @inheritDoc
     */
    public function setScripts(array $scripts): void
    {
        $this->scripts = $scripts;
    }

    /**
     * @inheritDoc
     */
    public function getScripts(): array
    {
        return $this->scripts;
    }

    /**
     * @inheritDoc
     */
    public function setRepositories(array $repositories): void
    {
        $this->repositories = $repositories;
    }

    /**
     * @inheritDoc
     */
    public function getRepositories(): array
    {
        return $this->repositories;
    }

    /**
     * @inheritDoc
     */
    public function setLicense(array $license): void
    {
        $this->license = $license;
    }

    /**
     * @inheritDoc
     */
    public function getLicense(): array
    {
        return $this->license;
    }

    /**
     * @inheritDoc
     */
    public function setKeywords(array $keywords): void
    {
        $this->keywords = $keywords;
    }

    /**
     * @inheritDoc
     */
    public function getKeywords(): array
    {
        return $this->keywords;
    }

    /**
     * @inheritDoc
     */
    public function setAuthors(array $authors): void
    {
        $this->authors = $authors;
    }

    /**
     * @inheritDoc
     */
    public function getAuthors(): array
    {
        return $this->authors;
    }

    /**
     * @inheritDoc
     */
    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * @inheritDoc
     */
    public function setHomepage(?string $homepage): void
    {
        $this->homepage = $homepage;
    }

    /**
     * @inheritDoc
     */
    public function getHomepage(): ?string
    {
        return $this->homepage;
    }

    /**
     * @inheritDoc
     */
    public function setSupport(array $support): void
    {
        $this->support = $support;
    }

    /**
     * @inheritDoc
     */
    public function getSupport(): array
    {
        return $this->support;
    }

    /**
     * @inheritDoc
     */
    public function setFunding(array $funding): void
    {
        $this->funding = $funding;
    }

    /**
     * @inheritDoc
     */
    public function getFunding(): array
    {
        return $this->funding;
    }

    /**
     * @inheritDoc
     */
    public function isAbandoned(): bool
    {
        return (bool) $this->abandoned;
    }

    /**
     * @inheritDoc
     */
    public function setAbandoned($abandoned): void
    {
        $this->abandoned = $abandoned;
    }

    /**
     * @inheritDoc
     */
    public function getReplacementPackage(): ?string
    {
        return \is_string($this->abandoned) ? $this->abandoned : null;
    }

    /**
     * @inheritDoc
     */
    public function setArchiveName(?string $name): void
    {
        $this->archiveName = $name;
    }

    /**
     * @inheritDoc
     */
    public function getArchiveName(): ?string
    {
        return $this->archiveName;
    }

    /**
     * @inheritDoc
     */
    public function setArchiveExcludes(array $excludes): void
    {
        $this->archiveExcludes = $excludes;
    }

    /**
     * @inheritDoc
     */
    public function getArchiveExcludes(): array
    {
        return $this->archiveExcludes;
    }
}
