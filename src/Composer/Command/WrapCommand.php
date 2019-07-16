<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Command;

use Composer\Util\Filesystem;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class WrapCommand extends BaseCommand
{
    protected function configure()
    {
        $this
            ->setName('wrap')
            ->setDescription('Create local ./composer wrapper')
            ->setHelp(
                <<<EOT
Nearly every README for a non-ancient PHP project begins with composer install. Some of them explain how to install composer itself, some of them don't and rely on developer's experience, web search engine of developer's choice, or implicitly on a globally installed composer. Well, there's nothing wrong with that, really, but I think we can do better.

Here is a couple of assumptions:

* composer is meant to be up to date;
* composer is born to be installed locally (to be upgraded easily at will, for example).

So, if there was a script that checked if composer was there, installed it if it wasn't, upgraded if outdated, and finally proxied parameters as is to it as if we referred to a composer itself, all the issues outined above would have been solved...

Read more at https://github.com/kamazee/composer-wrapper
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $cwd = getcwd();

        $this->safeCopy(__DIR__. '/../Wrapper/ComposerWrapper.php', $cwd . '/composer');
    }

    /**
     * Copy file using stream_copy_to_stream to work around https://bugs.php.net/bug.php?id=6463
     *
     * @param string $source
     * @param string $target
     */
    protected function safeCopy($source, $target)
    {
        $source = fopen($source, 'r');
        $target = fopen($target, 'w+');

        stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);
    }
}
