<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Silex;

use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpFoundation\Request;

/**
 * GetResponseForExceptionEvent with additional setStringResponse method
 *
 * setStringResponse will convert strings to response objects.
 *
 * @author Igor Wiedler <igor@wiedler.ch>
 */
class GetResponseForErrorEvent extends GetResponseForExceptionEvent
{
    public function setStringResponse($response)
    {
        $converter = new StringResponseConverter();
        $this->setResponse($converter->convert($response));
    }
}
