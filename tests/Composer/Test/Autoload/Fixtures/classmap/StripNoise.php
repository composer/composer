<?php

namespace Foo;

/**
 * class Fail { }
 */
class StripNoise
{
    public function test()
    {
        return <<<A
class Fail2
{

}
A
. <<<'TEST'
class Fail3
{

}
TEST;
    }

    public function test2()
    {
        $class = 'class Fail4 {}';
    }
}
