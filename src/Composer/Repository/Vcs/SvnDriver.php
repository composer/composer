<?php

namespace Composer\Repository\Vcs;

use Composer\Json\JsonFile;
use Composer\Util\ProcessExecutor;
use Composer\IO\IOInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class SvnDriver extends VcsDriver implements VcsDriverInterface
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
        parent::__construct($this->baseUrl = rtrim($url, '/'), $io, $process);

        if (false !== ($pos = strrpos($url, '/trunk'))) {
            $this->baseUrl = substr($url, 0, $pos);
        }

        $this->detectSvnAuth();
    }

    /**
     * Execute an SVN command and try to fix up the process with credentials
     * if necessary. The command is 'fixed up' with {@link self::getSvnCommand()}.
     *
     * @param string $command The svn command to run.
     * @param string $url     The SVN URL.
     *
     * @return string
     * @uses   self::getSvnCommand()
     * @uses   parent::$process
     * @see    ProcessExecutor::execute()
     */
    public function execute($command, $url)
    {
        $svnCommand = $this->getSvnCommand($command, $url);

        $status = $this->process->execute(
            $svnCommand,
            $output
        );

        // this could be any failure
        if ($status > 0 && $this->io->isInteractive()) {

            // the error is not auth-related
            if (strpos($output, 'authorization failed:') === false) {
                return $output;
            }

            // no authorization has been detected so far
            if (!$this->useAuth) {
                $this->io->write("The Subversion server ({$this->baseUrl}) requested credentials:");

                $this->svnUsername = $this->io->ask("Username");
                $this->svnPassword = $this->io->askAndHideAnswer("Password");
                $this->useAuth     = true;

                $pleaseCache = $this->io->askConfirmation("Should Subversion cache these credentials?", false);
                if ($pleaseCache === true) {
                    $this->useCache = true;
                }

                // restart the process
                $output = $this->execute($command, $url);
            } else {
                $this->io->write("Authorization failed: {$svnCommand}");
            }
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
            preg_match('{^(.+?)(@\d+)?$}', $identifier, $match);
            if (!empty($match[2])) {
                $identifier = $match[1];
                $rev = $match[2];
            } else {
                $rev = '';
            }

            $output = $this->execute('svn cat', $this->baseUrl . $identifier . 'composer.json' . $rev);

            if (!trim($output)) {
                throw new \UnexpectedValueException(
                    'Failed to retrieve composer information for identifier ' . $identifier . ' in ' . $this->getUrl()
                );
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
            $output     = $this->execute('svn ls', $this->baseUrl . '/tags');
            $this->tags = array();
            foreach ($this->process->splitLines($output) as $tag) {
                if ($tag) {
                    $this->tags[rtrim($tag, '/')] = '/tags/'.$tag;
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
            $output         = $this->execute('svn ls --verbose', $this->baseUrl . '/');
            $this->branches = array();
            foreach ($this->process->splitLines($output) as $line) {
                preg_match('{^\s*(\S+).*?(\S+)\s*$}', $line, $match);
                if ($match[2] === 'trunk/') {
                    $this->branches['trunk'] = '/trunk/@'.$match[1];
                    break;
                }
            }
            unset($output);

            $output = $this->execute('svn ls --verbose', $this->baseUrl . '/branches');

            foreach ($this->process->splitLines(trim($output)) as $line) {
                preg_match('{^\s*(\S+).*?(\S+)\s*$}', $line, $match);
                if ($match[2] === './') {
                    continue;
                }
                $this->branches[rtrim($match[2], '/')] = '/branches/'.$match[2].'@'.$match[1];
            }
        }

        return $this->branches;
    }

    /**
     * Return the no-auth-cache switch.
     *
     * @return string
     */
    public function getSvnAuthCache()
    {
        if (!$this->useCache) {
            return '--no-auth-cache ';
        }
        return '';
    }

    /**
     * A method to create the svn commands run.
     *
     * @string $cmd  Usually 'svn ls' or something like that.
     * @string $url  Repo URL.
     * @string $pipe Optional pipe for the output.
     *
     * @return string
     */
    public function getSvnCommand($cmd, $url, $pipe = null)
    {
        $cmd = sprintf('%s %s%s %s',
            $cmd,
            $this->getSvnInteractiveSetting(),
            $this->getSvnCredentialString(),
            escapeshellarg($url)
        );
        if ($pipe !== null) {
            $cmd .= ' ' . $pipe;
        }
        return $cmd;
    }

    /**
     * Return the credential string for the svn command.
     *
     * Adds --no-auth-cache when credentials are present.
     *
     * @return string
     * @uses   self::$useAuth
     */
    public function getSvnCredentialString()
    {
        if ($this->useAuth !== true) {
            return '';
        }
        $str = ' %s--username %s --password %s ';
        return sprintf(
            $str,
            $this->getSvnAuthCache(),
            escapeshellarg($this->svnUsername),
            escapeshellarg($this->svnPassword)
        );
    }

    /**
     * Always run commands 'non-interactive':
     *
     * It's easier to spot potential issues (e.g. auth-failure) because
     * non-interactive svn fails fast and does not wait for user input.
     *
     * @return string
     */
    public function getSvnInteractiveSetting()
    {
        return '--non-interactive ';
    }

    /**
     * {@inheritDoc}
     */
    public function hasComposerFile($identifier)
    {
        try {
            $this->getComposerInformation($identifier);
            return true;
        } catch (\Exception $e) {
        }

        return false;
    }

    /**
     * {@inheritDoc}
     */
    public static function supports($url, $deep = false)
    {
        if (preg_match('#(^svn://|//svn\.)#i', $url)) {
            return true;
        }

        if (!$deep) {
            return false;
        }

        $processExecutor = new ProcessExecutor();

        $exit = $processExecutor->execute(
            $this->getSvnCommand('svn info', $url, '2>/dev/null'),
            $ignoredOutput
        );
        return $exit === 0;
    }

    /**
     * This is quick and dirty - thoughts?
     *
     * @return void
     * @uses   parent::$baseUrl
     * @uses   self::$useAuth, self::$svnUsername, self::$svnPassword
     * @see    self::__construct()
     */
    protected function detectSvnAuth()
    {
        $uri = parse_url($this->baseUrl);
        if (empty($uri['user'])) {
            return;
        }

        $this->svnUsername = $uri['user'];

        if (!empty($uri['pass'])) {
            $this->svnPassword = $uri['pass'];
        }

        $this->useAuth = true;
    }
}
