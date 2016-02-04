<?php
/**
 * Created by PhpStorm.
 * User: bogdans
 * Date: 2/4/16
 * Time: 1:16 AM
 */

namespace Composer\Downloader;

/**
 * Exception thrown when missing .git|.svn|.hg folders, which contain VSC metadata
 *
 * @author Bogdans Ozerkins <b.ozerkins@gmail.com>
 */
class VcsMissingMetadataException extends \RuntimeException
{
    /**
     * Construct the exception. Note: The message is NOT binary safe.
     * @link http://php.net/manual/en/exception.construct.php
     * @param string $message [optional] The Exception message to throw.
     * @param int $code [optional] The Exception code.
     * @param \Exception $previous [optional] The previous exception used for the exception chaining. Since 5.3.0
     * @since 5.1.0
     */
    public function __construct($message = '', $code = 0, \Exception $previous = null)
    {
        parent::__construct("Missing VSC metadata exception: \n".$message, $code, $previous);
    }
}