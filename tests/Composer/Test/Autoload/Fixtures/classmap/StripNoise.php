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
WHITESPACE . <<<  MARKERINTEXT
    In PHP < 7.3, the docblock marker could occur in the text as long as it did not occur at the very start of the line.
     MARKERINTEXTwithtrail
     MARKERINTEXT_
class FailHeredocMarkerInText
{
}
But, what are you blind McFly, it's there. How else do you explain that wreck out there? Doc, Doc. Oh, no. You're alive. Bullet proof vest, how did you know, I never got a chance to tell you. About all that talk about screwing up future events, the space time continuum. Okay, alright, I'll prove it to you.
  .    MARKERINTEXT
class FailHeredocMarkerInText2
{
}
 Look at my driver's license, expires 1987. Look at my birthday, for crying out load I haven't even been born yet. And, look at this picture, my brother, my sister, and me. Look at the sweatshirt, Doc, class of 1984. Why do you keep following me around? Hey beat it, spook, this don't concern you.
MARKERINTEXT . <<<"DOUBLEQUOTES"
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

    public function test_unicode_heredoc()
    {
        return array(1, 2, <<<öéçив必
            class FailUnicode
            {
            }
            öéçив必, 3, 4);
    }

    public function test_wrapped_in_curly_brackets()
    {
        return ${<<<FOO
            class FailCurlyBrackets
            {
            }
            FOO};
    }

    public function test_wrapped_in_angle_brackets()
    {
        return [<<<FOO
            class FailAngleBrackets
            {
            }
            FOO];
    }
}

// Issue #10067.
abstract class First {
    public function heredocDuplicateMarker(): void {
        echo <<<DUPLICATE_MARKER

        DUPLICATE_MARKER;
    }
}

abstract class Second extends First {
    public function heredocDuplicateMarker(): void {
        echo <<<DUPLICATE_MARKER

        DUPLICATE_MARKER;
    }
}

abstract class Third extends First {
    public function heredocMarkersOnlyWhitespaceBetween(): void {
        echo <<<DUPLICATE_MARKER
DUPLICATE_MARKER;
    }
}
