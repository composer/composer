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

namespace Composer\Question\Test;

use Composer\Question\StrictConfirmationQuestion;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * based on Symfony\Component\Console\Tests\Helper\QuestionHelperTest
 *
 * @author Theo Tonge <theo@theotonge.co.uk>
 */
class StrictConfirmationQuestionTest extends TestCase
{
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
     * @expectedException InvalidArgumentException
     * @expectedExceptionMessage Please answer yes, y, no, or n.
     * @dataProvider             getAskConfirmationBadData
     */
    public function testAskConfirmationBadAnswer($answer)
    {
        $dialog = new QuestionHelper();
        $dialog->setInputStream($this->getInputStream($answer."\n"));
        $question = new StrictConfirmationQuestion('Do you like French fries?');
        $question->setMaxAttempts(1);
        $dialog->ask($this->createInputInterfaceMock(), $this->createOutputInterface(), $question);
    }

    /**
     * @dataProvider getAskConfirmationData
     */
    public function testAskConfirmation($question, $expected, $default = true)
    {
        $dialog = new QuestionHelper();

        $dialog->setInputStream($this->getInputStream($question."\n"));
        $question = new StrictConfirmationQuestion('Do you like French fries?', $default);
        $this->assertEquals($expected, $dialog->ask($this->createInputInterfaceMock(), $this->createOutputInterface(), $question), 'confirmation question should '.($expected ? 'pass' : 'cancel'));
    }

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
        $dialog = new QuestionHelper();

        $question = new StrictConfirmationQuestion('Do you like French fries?', false, '/^ja$/i', '/^nein$/i');
        $dialog->setInputStream($this->getInputStream("ja\n"));
        $this->assertTrue($dialog->ask($this->createInputInterfaceMock(), $this->createOutputInterface(), $question));
        $dialog->setInputStream($this->getInputStream("nein\n"));
        $this->assertFalse($dialog->ask($this->createInputInterfaceMock(), $this->createOutputInterface(), $question));
    }

    protected function getInputStream($input)
    {
        $stream = fopen('php://memory', 'r+', false);
        fwrite($stream, $input);
        rewind($stream);

        return $stream;
    }

    protected function createOutputInterface()
    {
        return new StreamOutput(fopen('php://memory', 'r+', false));
    }

    protected function createInputInterfaceMock($interactive = true)
    {
        $mock = $this->getMockBuilder('Symfony\Component\Console\Input\InputInterface')->getMock();
        $mock->expects($this->any())
            ->method('isInteractive')
            ->will($this->returnValue($interactive));

        return $mock;
    }
}
