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

namespace Composer\Util;

/**
 * @internal
 * @readonly
 */
final class ForgejoRepositoryData
{
    /** @var string */
    public $htmlUrl;
    /** @var string */
    public $sshUrl;
    /** @var string */
    public $httpCloneUrl;
    /** @var bool */
    public $isPrivate;
    /** @var string */
    public $defaultBranch;
    /** @var bool */
    public $hasIssues;
    /** @var bool */
    public $isArchived;

    public function __construct(
        string $htmlUrl,
        string $httpCloneUrl,
        string $sshUrl,
        bool $isPrivate,
        string $defaultBranch,
        bool $hasIssues,
        bool $isArchived
    ) {
        $this->htmlUrl = $htmlUrl;
        $this->httpCloneUrl = $httpCloneUrl;
        $this->sshUrl = $sshUrl;
        $this->isPrivate = $isPrivate;
        $this->defaultBranch = $defaultBranch;
        $this->hasIssues = $hasIssues;
        $this->isArchived = $isArchived;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromRemoteData(array $data): self
    {
        return new self(
            $data['html_url'],
            $data['clone_url'],
            $data['ssh_url'],
            $data['private'],
            $data['default_branch'],
            $data['has_issues'],
            $data['archived']
        );
    }
}
