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
. <<<  AB
class Fail3
{

}
AB
. <<<'TEST'
class Fail4
{

}
TEST
. <<< 'ANOTHER'
class Fail5
{

}
ANOTHER
. <<<	'ONEMORE'
class Fail6
{

}
ONEMORE;
    }

    public function test2()
    {
        $class = 'class Fail4 {}';
    }
}
