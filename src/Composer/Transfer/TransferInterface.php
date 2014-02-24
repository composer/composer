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

namespace Composer\Transfer;

/**
 * @author Peter Aba <p.aba@mysportgroup.de>
 */
interface TransferInterface
{
    public function download($fileUrl, $options, $io, $progress, $userAgent);

    public function getHeaders();

    public function getErrorCode();
}