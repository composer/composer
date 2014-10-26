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

namespace Composer\IO;

use Composer\Config;
use Composer\Progress\NullProgress;
use Composer\Progress\ProgressInterface;

abstract class BaseIO implements IOInterface
{
    protected $authentications = [];
    protected $progress;

    /**
     * Constructor.
     *
     * @param ProgressInterface $progress
     */
    public function __construct(ProgressInterface $progress = null) {
        $this->progress = $progress !== null ? $progress : new NullProgress();
    }

    /**
     * {@inheritDoc}
     */
    public function getAuthentications()
    {
        return $this->authentications;
    }

    /**
     * {@inheritDoc}
     */
    public function hasAuthentication($repositoryName)
    {
        return isset($this->authentications[$repositoryName]);
    }

    /**
     * {@inheritDoc}
     */
    public function getAuthentication($repositoryName)
    {
        if (isset($this->authentications[$repositoryName])) {
            return $this->authentications[$repositoryName];
        }

        return ['username' => null, 'password' => null];
    }

    /**
     * {@inheritDoc}
     */
    public function setAuthentication($repositoryName, $username, $password = null)
    {
        $this->authentications[$repositoryName] = ['username' => $username, 'password' => $password];
    }

    /**
     * {@inheritDoc}
     */
    public function loadConfiguration(Config $config)
    {
        // reload oauth token from config if available
        if ($tokens = $config->get('github-oauth')) {
            foreach ($tokens as $domain => $token) {
                if (!preg_match('{^[a-z0-9]+$}', $token)) {
                    throw new \UnexpectedValueException('Your github oauth token for '.$domain.' contains invalid characters: "'.$token.'"');
                }
                $this->setAuthentication($domain, $token, 'x-oauth-basic');
            }
        }

        // reload http basic credentials from config if available
        if ($creds = $config->get('http-basic')) {
            foreach ($creds as $domain => $cred) {
                $this->setAuthentication($domain, $cred['username'], $cred['password']);
            }
        }
    }

    /**
     * Starts a new progress 'section'.
     *
     * @param $message
     *
     * @return void
     */

    public function startSection($message) {
        $this->progress->section($message);
    }

    /**
     * Sets the total steps.
     *
     * @param $total
     * @param $type
     *
     * @return void
     */

    public function totalProgress($total, $type = 'item') {
        $this->progress->total($total, $type);
    }

    /**
     * Stores progress information.
     *
     * @param $message
     *
     * @return void
     */

    public function writeProgress($message) {
        $this->progress->write($message);
    }

    /**
     * Makes the progress bar indeterminate
     *
     * @return void
     */

    public function indeterminateProgress() {
        $this->progress->indeterminate();
    }

    /**
     * Sends a notification to the client.
     *
     * @param string $message
     * @param string $status
     * @return void
     */

    public function notification($message, $status = 'success') {
        $this->progress->notification($message, $status);
    }

    /**
     * Asks that the client stops polling for new progress information.
     *
     * @return void
     */

    public function stopPolling() {
        $this->progress->stopPolling();
    }

    /**
     * Resets the progress information
     *
     * @return void
     */

    public function resetProgress() {
        $this->progress->reset();
    }
}
