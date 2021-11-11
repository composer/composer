<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Test\Question;

use Composer\Question\StrictConfirmationQuestion;
use Composer\Test\TestCase;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * based on Symfony\Component\Console\Tests\Helper\QuestionHelperTest
 *
 * @author Theo Tonge <theo@theotonge.co.uk>
 */
class StrictConfirmationQuestionTest extends TestCase
{
    /**
     * @return string[][]
     *
     * @phpstan-return list<array{non-empty-string}>
     */
    public function getAskConfirmationBadData()
    {
        return array(
            array('not correct'),
            array('no more'),
            array('yes please'),
            array('yellow'),
        );
    }

    /**
     * @dataProvider getAskConfirmationBadData
     *
     * @param string $answer
     */
    public function testAskConfirmationBadAnswer($answer)
    {
        list($input, $dialog) = $this->createInput($answer."\n");

        $this->setExpectedException('InvalidArgumentException', 'Please answer yes, y, no, or n.');

        $question = new StrictConfirmationQuestion('Do you like French fries?');
        $question->setMaxAttempts(1);
        $dialog->ask($input, $this->createOutputInterface(), $question);
    }

    /**
     * @dataProvider getAskConfirmationData
     *
     * @param string $question
     * @param bool   $expected
     * @param bool   $default
     */
    public function testAskConfirmation($question, $expected, $default = true)
    {
        list($input, $dialog) = $this->createInput($question."\n");

        $question = new StrictConfirmationQuestion('Do you like French fries?', $default);
        $this->assertEquals($expected, $dialog->ask($input, $this->createOutputInterface(), $question), 'confirmation question should '.($expected ? 'pass' : 'cancel'));
    }

    /**
     * @return mixed[][]
     *
     * @phpstan-return list<array{string, bool}>|list<array{string, bool, bool}>
     */
    public function getAskConfirmationData()
    {
        return array(
            array('', true),
            array('', false, false),
            array('y', true),
            array('yes', true),
            array('n', false),
            array('no', false),
        );
    }

    public function testAskConfirmationWithCustomTrueAndFalseAnswer()
    {
        $question = new StrictConfirmationQuestion('Do you like French fries?', false, '/^ja$/i', '/^nein$/i');

        list($input, $dialog) = $this->createInput("ja\n");
        $this->assertTrue($dialog->ask($input, $this->createOutputInterface(), $question));

        list($input, $dialog) = $this->createInput("nein\n");
        $this->assertFalse($dialog->ask($input, $this->createOutputInterface(), $question));
    }

    /**
     * @param string $input
     *
     * @return resource
     */
    protected function getInputStream($input)
    {
        $stream = fopen('php://memory', 'r+', false);
        $this->assertNotFalse($stream);

        fwrite($stream, $input);
        rewind($stream);

        return $stream;
    }

    /**
     * @return StreamOutput
     */
    protected function createOutputInterface()
    {
        return new StreamOutput(fopen('php://memory', 'r+', false));
    }

    /**
     * @param string $entry
     *
     * @return object[]
     *
     * @phpstan-return array{ArrayInput, QuestionHelper}
     */
    protected function createInput($entry)
    {
        $stream = $this->getInputStream($entry);
        $input = new ArrayInput(array('--no-interaction'));
        $dialog = new QuestionHelper();

        if (method_exists($dialog, 'setInputStream')) {
            $dialog->setInputStream($stream);
        }
        if ($input instanceof StreamableInputInterface) {
            $input->setStream($stream);
        }

        return array($input, $dialog);
    }
}
