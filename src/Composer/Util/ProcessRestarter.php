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

use Composer\XdebugHandler\XdebugHandler;
use function count;
use function extension_loaded;
use function file_get_contents;
use function file_put_contents;
use function ini_get;
use function preg_replace;
use const PHP_EOL;

// see https://github.com/composer/xdebug-handler/issues/174
final class ProcessRestarter extends XdebugHandler
{

    /** @var array<string> */
    private $extensions = [];

    protected function requiresRestart(bool $default): bool
    {
        // pcov has a perf impact similar to xdebug, so we want to disable it
        if (extension_loaded('pcov') && ini_get('pcov.enabled') === '1') {
            $this->extensions[] = 'pcov';
        }

        return $default || count($this->extensions) > 0;
    }

    protected function restart(array $command): void
    {
        if (count($this->extensions) > 0) {
            $content = file_get_contents((string) $this->tmpIni);

            if ($content !== false) {
                foreach ($this->extensions as $extension) {
                    $regex = '/^\s*((?:zend_)?extension\s*=.*' . $extension . '.*)$/mi';
                    $content = preg_replace($regex, ';$1', $content) . PHP_EOL;
                }
                file_put_contents((string) $this->tmpIni, $content);
            }
        }

        parent::restart($command);
    }

}
