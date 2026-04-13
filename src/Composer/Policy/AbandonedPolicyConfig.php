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

namespace Composer\Policy;

/**
 * @internal
 * @final
 * @readonly
 */
class AbandonedPolicyConfig extends ListPolicyConfig
{
    public const NAME = 'abandoned';

    public function __construct(
        bool $block,
        string $audit,
        array $ignore
    ) {
        parent::__construct(
            self::NAME,
            $block,
            $audit,
            $ignore,
            true
        );
    }

    public function withBlockingDisabled()
    {
        return new static(
            false,
            $this->audit,
            $this->ignore
        );
    }
}
