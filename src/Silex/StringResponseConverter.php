<?php

/*
 * This file is part of the Silex framework.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Silex;

use Symfony\Component\HttpFoundation\Response;

/**
 * Converts string responses to Response objects.
 *
 * @author Igor Wiedler <igor@wiedler.ch>
 */
class StringResponseConverter
{
    /**
     * Does the conversion
     *
     * @param  $response The response string
     *
     * @return A response object
     */
    public function convert($response)
    {
        if (!$response instanceof Response) {
            return new Response((string) $response);
        }

        return $response;
    }
}
