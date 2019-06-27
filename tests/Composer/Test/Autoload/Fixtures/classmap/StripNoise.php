<?php

namespace Foo;

/**
 * class Fail { }
 */
class StripNoise
{
    public function test_heredoc()
    {
        return <<<HEREDOC
class FailHeredocBasic
{
}
HEREDOC . <<<  WHITESPACE
class FailHeredocWhitespace
{
}
WHITESPACE . <<<"DOUBLEQUOTES"
class FailHeredocDoubleQuotes
{
}
DOUBLEQUOTES . <<<	"DOUBLEQUOTESTABBED"
class FailHeredocDoubleQuotesTabbed
{
}
DOUBLEQUOTESTABBED . <<<HEREDOCPHP73
  class FailHeredocPHP73
  {
  }
  HEREDOCPHP73;
    }

    public function test_nowdoc()
    {
        return <<<'NOWDOC'
class FailNowdocBasic
{
}
NOWDOC . <<<  'WHITESPACE'
class FailNowdocWhitespace
{
}
WHITESPACE . <<<	'NOWDOCTABBED'
class FailNowdocTabbed
{
}
NOWDOCTABBED . <<<'NOWDOCPHP73'
  class FailNowdocPHP73
  {
  }
  NOWDOCPHP73;
    }

    public function test_followed_by_parentheses()
    {
        return array(<<<PARENTHESES
            class FailParentheses
            {
            }
            PARENTHESES);
    }

    public function test_followed_by_comma()
    {
        return array(1, 2, <<<COMMA
            class FailComma
            {
            }
            COMMA, 3, 4);
    }

    public function test_followed_by_period()
    {
        return <<<PERIOD
            class FailPeriod
            {
            }
            PERIOD.'?>';
    }

    public function test_simple_string()
    {
        return 'class FailSimpleString {}';
    }
}
