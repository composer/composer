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

namespace Composer\Package\Archiver;

/**
 * An exclude filter which processes composer's own exclude rules
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class ComposerExcludeFilter extends BaseExcludeFilter
{
    /**
     * @param string $sourcePath Directory containing sources to be filtered
     * @param string[] $excludeRules An array of exclude rules from composer.json
     */
    public function __construct(string $sourcePath, array $excludeRules)
    {
        parent::__construct($sourcePath);
        $this->excludePatterns = $this->generatePatterns($excludeRules);
    }
}
