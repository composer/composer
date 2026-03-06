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

namespace Composer\FilterList;

/**
 * @readonly
 * @internal
 * @final
 */
class DontFilterPackage
{
    /** @var string */
    public $packageName;
    /** @var string */
    public $constraint;
    /** @var string|null */
    public $reason;
    /** @var 'all'|'block'|'audit' */
    public $apply;

    /**
     * @param 'all'|'block'|'audit' $apply
     */
    public function __construct(
        string $packageName,
        string $constraint = '*',
        ?string $reason = null,
        string $apply = 'all'
    ) {
        $this->packageName = $packageName;
        $this->constraint = $constraint;
        $this->reason = $reason;
        $this->apply = $apply;
    }

    /**
     * @param array<mixed>|string|DontFilterPackage $config
     */
    public static function fromConfig($config): self
    {
        if ($config instanceof self) {
            return $config;
        }

        if (\is_string($config)) {
            return new self($config);
        }

        if (\is_array($config) && \count($config) === 1 && !isset($config['package'])) {
            return new self(\key($config), (string) \array_pop($config));
        }

        return new self($config['package'] ?? '', $config['constraint'] ?? '*', $config['reason'] ?? null, $config['apply'] ?? 'all');
    }
}
