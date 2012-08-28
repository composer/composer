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

namespace Composer\Package\Archiver;

use Composer\Util\ProcessExecutor;

/**
 * @author Till Klampaeckel <till@php.net>
 * @author Matthieu Moquet <matthieu@moquet.net>
 */
class MercurialArchiver implements ArchiverInterface
{
    protected $process;

    public function __construct($process = null)
    {
        $this->process = $process ?: new ProcessExecutor();
    }

    /**
     * {@inheritdoc}
     */
    public function archive($sources, $target, $format, $sourceRef = null)
    {
        if (null === $sourceRef) {
            $sourceRef = 'default';
        }

        $command = sprintf(
            'hg archive --rev %s --type %s %s',
            $sourceRef,
            $format,
            escapeshellarg($target)
        );

        $exitCode = $this->process->execute($command, $output, $sources);

        if (0 !== $exitCode) {
            throw new \RuntimeException(
                sprintf('Impossible to build the archive: `%s` returned %s', $command, $exitCode)
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function supports($format, $sourceType)
    {
        return 'hg' === $sourceType && in_array($format, array('tar', 'tbz2', 'tgz', 'uzip', 'zip'));
    }
}