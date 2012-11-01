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

namespace Composer\Util\Archive;

/**
 * Archiver is an extractor and compressor combination
 * 
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 */
interface ArchiverInterface extends ExtractorInterface, CompressorInterface {}