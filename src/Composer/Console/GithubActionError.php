<?php

namespace Composer\Console;

final class GithubActionError {
    /**
     * @param string $e
     * @param null|string $file
     * @param null|int $line
     */
    public static function emit($message, $file = null, $line = null) {
        if (getenv('GITHUB_ACTIONS')) {
            // newlines need to be encoded
            // see https://github.com/actions/starter-workflows/issues/68#issuecomment-581479448
            $message = str_replace("\n", '%0A', $message);

            if ($file && $line) {
                echo "::error file=". $file .",line=". $line ."::". $message;
            } elseif ($file) {
                echo "::error file=". $file ."::". $message;
            } else {
                echo "::error ::". $message;
            }
        }
    }
}
