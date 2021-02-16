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

namespace Composer\Util\Http;

class CurlResponse extends Response
{
    private $curlInfo;

    public function __construct(array $request, $code, array $headers, $body, array $curlInfo)
    {
        parent::__construct($request, $code, $headers, $body);
        $this->curlInfo = $curlInfo;
    }

    /**
     * @return array
     */
    public function getCurlInfo()
    {
        return $this->curlInfo;
    }
}
