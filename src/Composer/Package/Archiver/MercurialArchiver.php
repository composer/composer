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
class MercurialArchiver extends VcsArchiver
{
    /**
     * {@inheritdoc}
     */
    public function archive($source, $target)
    {
        $format = $this->format ?: 'zip';
        $sourceRef = $this->sourceRef ?: 'default';

        $command = sprintf(
            'hg archive --rev %s --type %s %s',
            $sourceRef,
            $format,
            escapeshellarg($target)
        );

        $this->process->execute($command, $output, $source);
    }

    /**
     * {@inheritdoc}
     */
    public function getSourceType()
    {
        return 'hg';
    }

    /**
     * {@inheritdoc}
     */
    public function supports($format)
    {
        return in_array($format, array(
            'tar',
            'tbz2',
            'tgz',
            'uzip',
            'zip',
        ));
    }
}