<?php











namespace Composer\Util;






class ComposerMirror
{
    public static function processUrl($mirrorUrl, $packageName, $version, $reference, $type)
    {
        $reference = preg_match('{^([a-f0-9]*|%reference%)$}', $reference) ? $reference : md5($reference);
        $version = strpos($version, '/') === false ? $version : md5($version);

        return str_replace(
            array('%package%', '%version%', '%reference%', '%type%'),
            array($packageName, $version, $reference, $type),
            $mirrorUrl
        );
    }
}
