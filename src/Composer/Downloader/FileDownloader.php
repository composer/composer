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

namespace Composer\Downloader;

use Composer\Package\PackageInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Base downloader for file packages
 *
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 */
abstract class FileDownloader implements DownloaderInterface
{
    protected $intput;
    protected $output;
    protected $bytesMax;

    /**
     * Constructor.
     *
     * @param InputInterface  $input  The Input instance
     * @param OutputInterface $output The Output instance
     */
    public function __construct(InputInterface $input, OutputInterface $output)
    {
        $this->intput = $input;
        $this->output = $output;
    }

    /**
     * {@inheritDoc}
     */
    public function getInstallationSource()
    {
        return 'dist';
    }

    /**
     * {@inheritDoc}
     */
    public function download(PackageInterface $package, $path)
    {
        // init the progress bar
        $this->bytesMax = 0;

        $url = $package->getDistUrl();
        $checksum = $package->getDistSha1Checksum();

        if (!is_dir($path)) {
            if (file_exists($path)) {
                throw new \UnexpectedValueException($path.' exists and is not a directory');
            }
            if (!mkdir($path, 0777, true)) {
                throw new \UnexpectedValueException($path.' does not exist and could not be created');
            }
        }

        $fileName = rtrim($path.'/'.md5(time().rand()).'.'.pathinfo($url, PATHINFO_EXTENSION), '.');

        $this->output->writeln("  - Package <comment>" . $package->getName() . "</comment> (<info>" . $package->getPrettyVersion() . "</info>)");

        if (!extension_loaded('openssl') && (0 === strpos($url, 'https:') || 0 === strpos($url, 'http://github.com'))) {
            // bypass https for github if openssl is disabled
            if (preg_match('{^https?://(github.com/[^/]+/[^/]+/(zip|tar)ball/[^/]+)$}i', $url, $match)) {
                $url = 'http://nodeload.'.$match[1];
            } else {
                throw new \RuntimeException('You must enable the openssl extension to download files via https');
            }
        }

        // Handle system proxy
        $ctx = stream_context_create();

        if (isset($_SERVER['HTTP_PROXY'])) {
            // http(s):// is not supported in proxy
            $proxy = str_replace(array('http://', 'https://'), array('tcp://', 'ssl://'), $_SERVER['HTTP_PROXY']);

            if (0 === strpos($proxy, 'ssl:') && !extension_loaded('openssl')) {
                throw new \RuntimeException('You must enable the openssl extension to use a proxy over https');
            }

            $ctx = stream_context_create(array(
                'http' => array(
                    'proxy'           => $proxy,
                    'request_fulluri' => true,
                ),
            ));
        }

        stream_context_set_params($ctx, array("notification" => array($this, 'callbackDownload')));

        copy($url, $fileName, $ctx);

        $this->output->overwrite('', 80);

        if (!file_exists($fileName)) {
            throw new \UnexpectedValueException($url.' could not be saved to '.$fileName.', make sure the'
                .' directory is writable and you have internet connectivity');
        }

        if ($checksum && hash_file('sha1', $fileName) !== $checksum) {
            throw new \UnexpectedValueException('The checksum verification of the archive failed (downloaded from '.$url.')');
        }

        $this->output->overwrite('    Unpacking archive');
        $this->extract($fileName, $path);


        $this->output->overwrite('    Cleaning up');
        unlink($fileName);

        // If we have only a one dir inside it suppose to be a package itself
        $contentDir = glob($path . '/*');
        if (1 === count($contentDir)) {
            $contentDir = $contentDir[0];
            foreach (array_merge(glob($contentDir . '/.*'), glob($contentDir . '/*')) as $file) {
                if (trim(basename($file), '.')) {
                    rename($file, $path . '/' . basename($file));
                }
            }
            rmdir($contentDir);
        }

        $this->output->overwrite('');
        $this->output->writeln('');
    }

    /**
     * {@inheritDoc}
     */
    public function update(PackageInterface $initial, PackageInterface $target, $path)
    {
        $fs = new Util\Filesystem();
        $fs->removeDirectory($path);
        $this->download($target, $path);
    }

    /**
     * {@inheritDoc}
     */
    public function remove(PackageInterface $package, $path)
    {
        $fs = new Util\Filesystem();
        $fs->removeDirectory($path);
    }

    /**
     * Download notification action.
     *
     * @param integer $notificationCode The notification code
     * @param integer $severity         The severity level
     * @param string  $message          The message
     * @param integer $messageCode      The message code
     * @param integer $bytesTransferred The loaded size
     * @param integer $bytesMax         The total size
     */
    protected function callbackDownload($notificationCode, $severity, $message, $messageCode, $bytesTransferred, $bytesMax)
    {
        switch ($notificationCode) {
            case STREAM_NOTIFY_AUTH_REQUIRED:
                break;

            case STREAM_NOTIFY_FILE_SIZE_IS:
                if ($this->bytesMax < $bytesMax) {
                    $this->bytesMax = $bytesMax;
                }

                break;

            case STREAM_NOTIFY_PROGRESS:
                if ($this->bytesMax > 0) {
                    $progression = 0;

                    if ($this->bytesMax > 0) {
                        $progression = ($bytesTransferred / $this->bytesMax * 100);
                    }

                    $levels = array(0, 5, 10, 15, 20, 25, 30, 35, 40, 35, 50, 55, 60,
                            65, 70, 75, 80, 85, 90, 95, 100);

                    $progression = round($progression, 0);

                    if (in_array($progression, $levels)) {
                        $this->output->overwrite("    Downloading: <comment>$progression%</comment>", 80);
                    }
                }

                break;

            case STREAM_NOTIFY_AUTH_RESULT:
                break;

            default:
                break;
        }
    }

    /**
     * Extract file to directory
     *
     * @param string $file Extracted file
     * @param string $path Directory
     *
     * @throws \UnexpectedValueException If can not extract downloaded file to path
     */
    protected abstract function extract($file, $path);
}
