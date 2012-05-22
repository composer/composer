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

namespace Composer\Command\Helper;

use Symfony\Component\Console\Helper\DialogHelper as BaseDialogHelper;

class DialogHelper extends BaseDialogHelper
{
    /**
     * Build text for asking a question. For example:
     *
     *  "Do you want to continue [yes]:"
     *
     * @param string $question The question you want to ask
     * @param mixed  $default  Default value to add to message, if false no default will be shown
     * @param string $sep      Separation char for between message and user input
     *
     * @return string
     */
    public function getQuestion($question, $default = null, $sep = ':')
    {
        return $default !== null ?
            sprintf('<info>%s</info> [<comment>%s</comment>]%s ', $question, $default, $sep) :
            sprintf('<info>%s</info>%s ', $question, $sep);
    }
}
