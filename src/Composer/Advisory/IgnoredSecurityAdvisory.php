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

namespace Composer\Advisory;


class IgnoredSecurityAdvisory extends SecurityAdvisory {

    /**
     * @var string|null
     */
    public $ignoreReason;

    public function specifyIgnoreReason(string $ignoreReason): void {
        $this->ignoreReason = $ignoreReason;
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        $data = parent::jsonSerialize();
        if ($this->ignoreReason === NULL) {
            unset($data['ignoreReason']);
        }

        return $data;
    }

}
