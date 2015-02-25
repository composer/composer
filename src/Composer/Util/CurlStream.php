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

namespace Composer\Util;

/**
 * http/https streamWrapper by ext-curl
 * @author Hiraku Nakano <hiraku@tojiru.net>
 * @see http://php.net/manual/en/class.streamwrapper.php
 */
class CurlStream
{
    /** @type resource $context */
    public $context;

    /** @type resource<url>[] $cache */
    private static $cache = array();

    /** @type resource<curl> $ch */
    private $ch;

    /** @type int $p */
    private $p = 0;

    /** @type string[] $header */
    private static $header = array();

    /** @type string $body */
    private $body;

    /** @type int $length */
    private $length;

    static function getLastHeaders()
    {
        return self::$header;
    }

    static function clearLastHeaders()
    {
        self::$header = array();
    }

    function stream_close()
    {
        // do nothing
    }

    /**
     * @return bool
     */
    function stream_eof()
    {
        return $this->p > $this->length;
    }

    /**
     * @param string $path
     * @param string $mode
     * @param int $options
     * @param string &$opend_path
     * @return bool
     */
    function stream_open($path, $mode, $options, &$opened_path)
    {
        $parsed = parse_url($path);
        $origin = "$parsed[scheme]://";
        if (isset($parsed['user'])) {
            $origin .= $parsed['user'];
            if (isset($parsed['pass'])) {
                $origin .= ":$parsed[pass]";
            }
            $origin .= '@';
        }
        $origin .= $parsed['host'];
        if (isset($parsed['port'])) {
            $origin .= ":$parsed[port]";
        }

        if (isset(self::$cache[$origin])) {
            $ch = self::$cache[$origin];
        } else {
            $ch = self::$cache[$origin] = curl_init();
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => 'gzip',
                CURLOPT_HEADER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 20,
            ));
        }
        curl_setopt($ch, CURLOPT_URL, $path);

        $params = stream_context_get_params($this->context);
        $context = $params['options'];
        if (isset($context['http']['method']) && $context['http']['method'] === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        } else {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }
        if (isset($context['http']['header'])) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $context['http']['header']);
        }
        if (isset($context['http']['content'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $context['http']['content']);
        }

        if (isset($params['notification'])) {
            $callbackGet = $params['notification'];
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION,
                function($ch, $downbytesMax, $downbytes, $upbytesMax, $upbytes)
                use($callbackGet)
                {
                    static $bytesMaxSended = false;
                    if ($downbytes) {
                        $code = $bytesMaxSended ?
                            STREAM_NOTIFY_PROGRESS :
                            STREAM_NOTIFY_FILE_SIZE_IS;
                        call_user_func(
                            $callbackGet,
                            $code, //notificationCode
                            STREAM_NOTIFY_SEVERIRY_INFO, //severity
                            '', //message
                            0, //messageCode
                            $downbytes, //bytesTransferred
                            $downbytesMax //bytesMax
                        );
                    }
                    return 0;
                }
            );
        } else {
            curl_setopt($ch, CURLOPT_NOPROGRESS, true);
        }

        $result = curl_exec($ch);
        $errno = curl_errno($ch);
        if (CURLE_OK !== $errno) {
            throw new RuntimeException(curl_error($ch), $errno);
        }

        $info = curl_getinfo($ch);
        $header = substr($result, 0, $info['header_size']);
        self::$header = explode("\r\n", rtrim($header));
        $this->body = substr($result, $info['header_size']);
        $this->length = strlen($this->body);

        $this->ch = $ch;
        return true;
    }

    /**
     * @param int $count
     * @return string
     */
    function stream_read($count)
    {
        $p = $this->p;
        $this->p += $count;
        return substr($this->body, $p, $count);
    }

    /**
     * @return array
     */
    function stream_stat()
    {
        return array();
    }
}

