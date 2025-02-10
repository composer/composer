<?php declare(strict_types=1);

namespace Composer\Util;

use Composer\Pcre\Preg;

/**
 * @internal
 * @readonly
 */
final class ForgejoUrl
{
    public const URL_REGEX = '{^(?:(?:https?|git)://([^/]+)/|git@([^:]+):/?)([^/]+)/([^/]+?)(?:\.git|/)?$}';

    /** @var string */
    public $owner;
    /** @var string */
    public $repository;
    /** @var string */
    public $originUrl;
    /** @var string */
    public $apiUrl;

    private function __construct(
        string $owner,
        string $repository,
        string $originUrl,
        string $apiUrl
    ) {
        $this->owner = $owner;
        $this->repository = $repository;
        $this->originUrl = $originUrl;
        $this->apiUrl = $apiUrl;
    }

    public static function create(string $repoUrl): self
    {
        $url = self::tryFrom($repoUrl);
        if ($url !== null) {
            return $url;
        }

        throw new \InvalidArgumentException('This is not a valid Forgejo URL: ' . $repoUrl);
    }

    public static function tryFrom(?string $repoUrl): ?self
    {
        if ($repoUrl === null || ! Preg::isMatch(self::URL_REGEX, $repoUrl, $match)) {
            return null;
        }

        $originUrl = strtolower($match[1] ?? (string) $match[2]);
        $apiBase = $originUrl . '/api/v1';

        return new self(
            $match[3],
            $match[4],
            $originUrl,
            sprintf('https://%s/repos/%s/%s', $apiBase, $match[3], $match[4])
        );
    }

    public function generateSshUr(): string
    {
        return 'git@' . $this->originUrl . ':'.$this->owner.'/'.$this->repository.'.git';
    }
}
