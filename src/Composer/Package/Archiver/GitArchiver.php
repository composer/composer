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
class GitArchiver implements ArchiverInterface
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
        // Since git-archive no longer works with a commit ID in git 1.7.10,
        // use by default the HEAD reference instead of the commit sha1
        if (null === $sourceRef || preg_match('/^[0-9a-f]{40}$/i', $sourceRef)) {
            $sourceRef = 'HEAD';
        }

        $command = sprintf(
            'git archive --format %s --output %s %s',
            $format,
            escapeshellarg($target),
            $sourceRef
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
        return 'git' === $sourceType && in_array($format, array('zip', 'tar', 'tgz'));
    }
}