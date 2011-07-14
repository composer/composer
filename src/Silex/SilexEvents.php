<?php

/*
 * This file is part of the Symfony framework.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Silex;

/**
 * @author Igor Wiedler <igor@wiedler.ch>
 */
final class SilexEvents
{
    const BEFORE = 'silex.before';
    const AFTER  = 'silex.after';
    const ERROR  = 'silex.error';
}
