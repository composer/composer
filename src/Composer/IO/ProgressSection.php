<?php

namespace Composer\IO;

class ProgressSection
{
    public function __construct($description, $max)
    {
    }

    public function advance()
    {
        echo '.';
    }
}
