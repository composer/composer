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

/**
 * @author Till Klampaeckel <till@php.net>
 * @author Matthieu Moquet <matthieu@moquet.net>
 */
class GitArchiver extends VcsArchiver
{
    /**
     * {@inheritdoc}
     */
    public function archive($source, $target)
    {
        $format = $this->format ?: 'zip';
        $sourceRef = $this->sourceRef ?: 'HEAD';

        $command = sprintf(
            'git archive --format %s --output %s %s',
            $format,
            escapeshellarg($target),
            $sourceRef
        );

        $exitCode = $this->process->execute($command, $output, $source);

        if (0 !== $exitCode) {
            throw new \RuntimeException(
                sprintf('The command `%s` returned %s', $command, $exitCode)
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getSourceType()
    {
        return 'git';
    }

    /**
     * {@inheritdoc}
     */
    public function supports($format)
    {
        return in_array($format, array(
            'zip',
            'tar',
            'tgz',
        ));
    }
}