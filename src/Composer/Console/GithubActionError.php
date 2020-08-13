<?php

namespace Composer\Console;

final class GithubActionError {
    public static function forException(\Exception $e, $file = null, $line = null) {
        if (getenv('GITHUB_ACTIONS')) {
            // newlines need to be encoded
            // see https://github.com/actions/starter-workflows/issues/68#issuecomment-581479448
            $message = str_replace("\n", '%0A', $e->getMessage());

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
