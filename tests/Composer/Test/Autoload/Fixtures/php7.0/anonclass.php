<?php

declare(strict_types = 1);

namespace Dummy\Test;

use Dummy\Common\TestCase;

class AnonClassHolder extends TestCase
{
    protected function getTest(): ClassAvailability
    {
        return new class extends ClassAvailability
        {
        };
    }

    protected function getTest2(): ClassAvailability
    {
        return new class(2) extends ClassAvailability
        {
        };
    }

    protected function getTest3(): ClassAvailability
    {
        return new class(2, 3) extends ClassAvailability
        {
        };
    }

    protected function getTest4(): ClassAvailability
    {
        return new class(2, 3) {
        };
    }

    protected function getTest5(): ClassAvailability
    {
        return new class implements FooInterface {
        };
    }
}
