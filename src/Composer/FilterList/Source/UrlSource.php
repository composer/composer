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

namespace Composer\FilterList\Source;

/**
 * @internal
 * @readonly
 * @final
 */
class UrlSource
{
    /** @var string */
    public $name;
    /** @var string */
    public $url;

    public function __construct(
        string $name,
        string $url
    ) {
        $this->name = $name;
        $this->url = $url;
    }
}
