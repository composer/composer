<?php

namespace Composer\Repository\Vcs;

use Composer\Json\JsonFile;
use Composer\Util\ProcessExecutor;
use Composer\Util\Svn as SvnUtil;
use Composer\IO\IOInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class SvnDriver extends VcsDriver
{
    protected $baseUrl;
    protected $tags;
    protected $branches;
    protected $infoCache = array();

    /**
     * @var boolean $useAuth Contains credentials, or not?
     */
    protected $useAuth = false;

    /**
     * @var boolean $useCache To determine if we should cache the credentials
     *                        supplied by the user. By default: no cache.
     * @see self::getSvnAuthCache()
     */
    protected $useCache = false;

    /**
     * @var string $svnUsername
     */
    protected $svnUsername = '';

    /**
     * @var string $svnPassword
     */
    protected $svnPassword = '';

    /**
     * @var Composer\Util\Svn $util
     */
    protected $util;

    /**
     * __construct
     *
     * @param string          $url
     * @param IOInterface     $io
     * @param ProcessExecutor $process
     *
     * @return $this
     * @uses   self::detectSvnAuth()
     */
    public function __construct($url, IOInterface $io, ProcessExecutor $process = null)
    {
        $url = self::fixSvnUrl($url);
        parent::__construct($this->baseUrl = rtrim($url, '/'), $io, $process);

        if (false !== ($pos = strrpos($url, '/trunk'))) {
            $this->baseUrl = substr($url, 0, $pos);
        }
        $this->util    = new SvnUtil($this->baseUrl, $io);
        $this->useAuth = $this->util->hasAuth();
    }

    /**
     * Execute an SVN command and try to fix up the process with credentials
     * if necessary. The command is 'fixed up' with {@link self::getSvnCommand()}.
     *
     * @param string $command The svn command to run.
     * @param string $url     The SVN URL.
     *
     * @return string
     * @uses   Composer\Util\Svn::getCommand()
     * @uses   parent::$process
     * @see    ProcessExecutor::execute()
     */
    public function execute($command, $url)
    {
        $svnCommand = $this->util->getCommand($command, $url);

        $status = $this->process->execute(
            $svnCommand,
            $output
        );

        if ($status == 0) {
            return $output;
        }

        // this could be any failure, since SVN exits with 1 always
        if (!$this->io->isInteractive()) {
            return $output;
        }

        // the error is not auth-related
        if (strpos($output, 'authorization failed:') === false) {
            return $output;
        }

        // no authorization has been detected so far
        if (!$this->useAuth) {
            $this->useAuth = $this->util->doAuthDance()->hasAuth();

            // restart the process
            $output = $this->execute($command, $url);
        } else {
            $this->io->write("Authorization failed: {$svnCommand}");
        }
        return $output;
    }

    /**
     * {@inheritDoc}
     */
    public function initialize()
    {
        $this->getBranches();
        $this->getTags();
    }

    /**
     * {@inheritDoc}
     */
    public function getRootIdentifier()
    {
        return 'trunk';
    }

    /**
     * {@inheritDoc}
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * {@inheritDoc}
     */
    public function getSource($identifier)
    {
        return array('type' => 'svn', 'url' => $this->baseUrl, 'reference' => $identifier);
    }

    /**
     * {@inheritDoc}
     */
    public function getDist($identifier)
    {
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function getComposerInformation($identifier)
    {
        $identifier = '/' . trim($identifier, '/') . '/';
        if (!isset($this->infoCache[$identifier])) {
            preg_match('{^(.+?)(@\d+)?/$}', $identifier, $match);
            if (!empty($match[2])) {
                $identifier = $match[1];
                $rev = $match[2];
            } else {
                $rev = '';
            }

            $output = $this->execute('svn cat', $this->baseUrl . $identifier . 'composer.json' . $rev);
            if (!trim($output)) {
                return;
            }

            $composer = JsonFile::parseJson($output);

            if (!isset($composer['time'])) {
                $output = $this->execute('svn info', $this->baseUrl . $identifier . $rev);
                foreach ($this->process->splitLines($output) as $line) {
                    if ($line && preg_match('{^Last Changed Date: ([^(]+)}', $line, $match)) {
                        $date = new \DateTime($match[1]);
                        $composer['time'] = $date->format('Y-m-d H:i:s');
                        break;
                    }
                }
            }
            $this->infoCache[$identifier] = $composer;
        }

        return $this->infoCache[$identifier];
    }

    /**
     * {@inheritDoc}
     */
    public function getTags()
    {
        if (null === $this->tags) {
            $this->tags = array();

            $output = $this->execute('svn ls', $this->baseUrl . '/tags');
            if ($output) {
                foreach ($this->process->splitLines($output) as $tag) {
                    if ($tag) {
                        $this->tags[rtrim($tag, '/')] = '/tags/'.$tag;
                    }
                }
            }
        }

        return $this->tags;
    }

    /**
     * {@inheritDoc}
     */
    public function getBranches()
    {
        if (null === $this->branches) {
            $this->branches = array();

            $output = $this->execute('svn ls --verbose', $this->baseUrl . '/');
            if ($output) {
                foreach ($this->process->splitLines($output) as $line) {
                    $line = trim($line);
                    if ($line && preg_match('{^\s*(\S+).*?(\S+)\s*$}', $line, $match)) {
                        if (isset($match[1]) && isset($match[2]) && $match[2] === 'trunk/') {
                            $this->branches['trunk'] = '/trunk/@'.$match[1];
                            break;
                        }
                    }
                }
            }
            unset($output);

            $output = $this->execute('svn ls --verbose', $this->baseUrl . '/branches');
            if ($output) {
                foreach ($this->process->splitLines(trim($output)) as $line) {
                    $line = trim($line);
                    if ($line && preg_match('{^\s*(\S+).*?(\S+)\s*$}', $line, $match)) {
                        if (isset($match[1]) && isset($match[2]) && $match[2] !== './') {
                            $this->branches[rtrim($match[2], '/')] = '/branches/'.$match[2].'@'.$match[1];
                        }
                    }
                }
            }
        }

        return $this->branches;
    }

    /**
     * {@inheritDoc}
     */
    public static function supports($url, $deep = false)
    {
        $url = self::fixSvnUrl($url);
        if (preg_match('#((^svn://)|(^svn\+ssh://)|(^file:///)|(^http)|(svn\.))#i', $url)) {
            return true;
        }

        if (!$deep) {
            return false;
        }

        $processExecutor = new ProcessExecutor();

        $exit = $processExecutor->execute(
            "svn info --non-interactive {$url}",
            $ignoredOutput
        );

        if ($exit === 0) {
            // This is definitely a Subversion repository.
            return true;
        }
        if (preg_match('/authorization failed/i', $processExecutor->getErrorOutput())) {
            // This is likely a remote Subversion repository that requires
            // authentication. We will handle actual authentication later.
            return true;
        }
        return false;
    }

    /**
     * An absolute path (leading '/') is converted to a file:// url.
     *
     * @param string $url
     *
     * @return string
     */
    protected static function fixSvnUrl($url)
    {
        if (strpos($url, '/', 0) === 0) {
            $url = 'file://' . $url;
        }
        return $url;
    }
}
