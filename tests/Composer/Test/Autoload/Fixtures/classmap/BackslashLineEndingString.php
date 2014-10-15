<?php

namespace Foo;

class SlashedA {
    function foo() {
        return sprintf("foo\
                        bar");
    }
}

class SlashedB {
    function bar() {
        print "baz";
    }
}
