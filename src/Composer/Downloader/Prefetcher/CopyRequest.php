<?php
namespace Composer\Downloader\Prefetcher;

use Composer\Util;
use Composer\IO;
use Composer\Config;

class CopyRequest
{
    protected $scheme;
    protected $user;
    protected $pass;
    protected $host;
    protected $port;
    protected $path;
    protected $query = array();

    /** @var [string => string] */
    protected $headers = array();

    /** @var string */
    protected $destination;

    /** @var resource<stream<plainfile>> */
    protected $fp;

    protected $success = false;

    private static $defaultCurlOptions = array(
        CURLOPT_HTTPGET => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 20,
        CURLOPT_ENCODING => '',
    );

    protected $githubDomains = array();
    protected $gitlabDomains = array();

    /**
     * @param string $url
     * @param string $destination
     * @param bool $useRedirector
     * @param IO\IOInterface $io
     * @param Config $config
     */
    public function __construct($url, $destination, $useRedirector, IO\IOInterface $io, Config $config)
    {
        $this->setURL($url);
        $this->setDestination($destination);
        $this->githubDomains = $config->get('github-domains');
        $this->gitlabDomains = $config->get('gitlab-domains');
        $this->setupAuthentication($io, $useRedirector);
    }

    public function __destruct()
    {
        if ($this->fp) {
            fclose($this->fp);
        }

        if (!$this->success) {
            if (file_exists($this->destination)) {
                unlink($this->destination);
            }
        }
    }

    /**
     * @return string
     */
    public function getURL()
    {
        $url = self::ifOr($this->scheme, '', '://');
        if ($this->user) {
            $user = $this->user;
            $user .= self::ifOr($this->pass, ':');
            $url .= "$user@";
        }
        $url .= self::ifOr($this->host);
        $url .= self::ifOr($this->port, ':');
        $url .= self::ifOr($this->path);
        $url .= self::ifOr(http_build_query($this->query), '?');
        return $url;
    }

    /**
     * @return string user/pass/access_token masked url
     */
    public function getMaskedURL()
    {
        $url = self::ifOr($this->scheme, '', '://');
        $url .= self::ifOr($this->host);
        $url .= self::ifOr($this->port, ':');
        $url .= self::ifOr($this->path);
        return $url;
    }

    private static function ifOr($str, $pre = '', $post = '')
    {
        if ($str) {
            return "$pre$str$post";
        }
        return '';
    }

    /**
     * @param string $url
     */
    public function setURL($url)
    {
        $struct = parse_url($url);
        foreach ($struct as $key => $val) {
            if ($key === 'query') {
                parse_str($val, $this->query);
            } else {
                $this->$key = $val;
            }
        }
    }

    public function addParam($key, $val)
    {
        $this->query[$key] = $val;
    }

    public function addHeader($key, $val)
    {
        $this->headers[strtolower($key)] = $val;
    }

    public function makeSuccess()
    {
        $this->success = true;
    }

    /**
     * @return array
     */
    public function getCurlOptions()
    {
        $headers = array();
        foreach ($this->headers as $key => $val) {
            $headers[] = strtr(ucwords(strtr($key, '-', ' ')), ' ', '-') . ': ' . $val;
        }

        $url = $this->getURL();

        $curlOpts = array(
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => Util\StreamContextFactory::generateUserAgent(),
            CURLOPT_FILE => $this->fp,
            //CURLOPT_VERBOSE => true, //for debug
        );
        $curlOpts += self::$defaultCurlOptions;

        if ($ciphers = $this->nssCiphers()) {
            $curlOpts[CURLOPT_SSL_CIPHER_LIST] = $ciphers;
        }
        if ($proxy = $this->getProxy($url)) {
            $curlOpts[CURLOPT_PROXY] = $proxy;
        }

        return $curlOpts;
    }

    /**
     * @param IO\IOInterface $io
     * @param bool $useRedirector
     */
    private function setupAuthentication(IO\IOInterface $io, $useRedirector)
    {
        if (preg_match('/\.github\.com$/', $this->host)) {
            $authKey = 'github.com';
            if ($useRedirector) {
                if ($this->host === 'api.github.com' && preg_match('%^/repos(/[^/]+/[^/]+/)zipball(.+)$%', $this->path, $_)) {
                    $this->host = 'codeload.github.com';
                    $this->path = "$_[1]legacy.zip$_[2]";
                }
            }
        } else {
            $authKey = $this->host;
        }
        if (!$io->hasAuthentication($authKey)) {
            if ($this->user || $this->pass) {
                $io->setAuthentication($authKey, $this->user, $this->pass);
            } else {
                return;
            }
        }

        $auth = $io->getAuthentication($authKey);

        // is github
        if (in_array($authKey, $this->githubDomains) && 'x-oauth-basic' === $auth['password']) {
            $this->addParam('access_token', $auth['username']);
            $this->user = $this->pass = null;
            return;
        }
        // is gitlab
        if (in_array($authKey, $this->gitlabDomains) && 'oauth2' === $auth['password']) {
            $this->addHeader('authorization', "Bearer $auth[username]");
            $this->user = $this->pass = null;
            return;
        }
        // others, includes bitbucket
        $this->user = $auth['username'];
        $this->pass = $auth['password'];
    }

    private function getProxy($url)
    {
        if (isset($_SERVER['no_proxy'])) {
            $pattern = new Util\NoProxyPattern($_SERVER['no_proxy']);
            if ($pattern->test($url)) {
                return null;
            }
        }

        foreach (array('http', 'https') as $scheme) {
            if ($this->scheme === $scheme) {
                $label = $scheme . '_proxy';
                foreach (array($label, strtoupper($label)) as $l) {
                    if (isset($_SERVER[$l])) {
                        return $_SERVER[$l];
                    }
                }
            }
        }
        return null;
    }

    /**
     * enable ECC cipher suites in cURL/NSS
     */
    public static function nssCiphers()
    {
        static $cache;
        if (isset($cache)) {
            return $cache;
        }
        $ver = curl_version();
        if (preg_match('/^NSS.*Basic ECC$/', $ver['ssl_version'])) {
            return $cache = implode(',', self::$NSS_CIPHERS);
        }
        return $cache = false;
    }

    /**
     * @param string
     */
    public function setDestination($destination)
    {
        $this->destination = $destination;
        if (is_dir($destination)) {
            throw new FetchException(
                "The file could not be written to $destination. Directory exists."
            );
        }

        $this->createDir($destination);

        $this->fp = fopen($destination, 'wb');
        if (!$this->fp) {
            throw new FetchException(
                "The file could not be written to $destination."
            );
        }
    }

    protected function createDir($fileName)
    {
        $targetdir = dirname($fileName);
        if (!file_exists($targetdir)) {
            if (!mkdir($targetdir, 0766, true)) {
                throw new FetchException(
                    "The file could not be written to $fileName."
                );
            }
        }
    }

    private static $NSS_CIPHERS = array(
        'rsa_3des_sha',
        'rsa_des_sha',
        'rsa_null_md5',
        'rsa_null_sha',
        'rsa_rc2_40_md5',
        'rsa_rc4_128_md5',
        'rsa_rc4_128_sha',
        'rsa_rc4_40_md5',
        'fips_des_sha',
        'fips_3des_sha',
        'rsa_des_56_sha',
        'rsa_rc4_56_sha',
        'rsa_aes_128_sha',
        'rsa_aes_256_sha',
        'rsa_aes_128_gcm_sha_256',
        'dhe_rsa_aes_128_gcm_sha_256',
        'ecdh_ecdsa_null_sha',
        'ecdh_ecdsa_rc4_128_sha',
        'ecdh_ecdsa_3des_sha',
        'ecdh_ecdsa_aes_128_sha',
        'ecdh_ecdsa_aes_256_sha',
        'ecdhe_ecdsa_null_sha',
        'ecdhe_ecdsa_rc4_128_sha',
        'ecdhe_ecdsa_3des_sha',
        'ecdhe_ecdsa_aes_128_sha',
        'ecdhe_ecdsa_aes_256_sha',
        'ecdh_rsa_null_sha',
        'ecdh_rsa_128_sha',
        'ecdh_rsa_3des_sha',
        'ecdh_rsa_aes_128_sha',
        'ecdh_rsa_aes_256_sha',
        'echde_rsa_null',
        'ecdhe_rsa_rc4_128_sha',
        'ecdhe_rsa_3des_sha',
        'ecdhe_rsa_aes_128_sha',
        'ecdhe_rsa_aes_256_sha',
        'ecdhe_ecdsa_aes_128_gcm_sha_256',
        'ecdhe_rsa_aes_128_gcm_sha_256',
    );
}
