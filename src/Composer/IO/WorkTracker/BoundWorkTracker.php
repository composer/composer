<?php

namespace Composer\IO\WorkTracker;

class BoundWorkTracker extends AbstractWorkTracker
{
    protected $max;

    public function __construct($title, $parent = null, $formatter, $max)
    {
        parent::__construct($title, $parent, $formatter);
        $this->max = $max;
    }

    public function getMax() 
    {
        return $this->max;
    }
    
}
