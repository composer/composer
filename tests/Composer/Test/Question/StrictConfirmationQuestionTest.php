<?php declare(strict_types=1);

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
    public function getAskConfirmationBadData(): array
    {
        return [
            ['not correct'],
            ['no more'],
            ['yes please'],
            ['yellow'],
        ];
    }

    /**
     * @dataProvider getAskConfirmationBadData
     */
    public function testAskConfirmationBadAnswer(string $answer): void
    {
        [$input, $dialog] = $this->createInput($answer."\n");

        self::expectException('InvalidArgumentException');
        self::expectExceptionMessage('Please answer yes, y, no, or n.');

        $question = new StrictConfirmationQuestion('Do you like French fries?');
        $question->setMaxAttempts(1);
        $dialog->ask($input, $this->createOutputInterface(), $question);
    }

    /**
     * @dataProvider getAskConfirmationData
     */
    public function testAskConfirmation(string $question, bool $expected, bool $default = true): void
    {
        [$input, $dialog] = $this->createInput($question."\n");

        $question = new StrictConfirmationQuestion('Do you like French fries?', $default);
        $this->assertEquals($expected, $dialog->ask($input, $this->createOutputInterface(), $question), 'confirmation question should '.($expected ? 'pass' : 'cancel'));
    }

    /**
     * @return mixed[][]
     *
     * @phpstan-return list<array{string, bool}>|list<array{string, bool, bool}>
     */
    public function getAskConfirmationData(): array
    {
        return [
            ['', true],
            ['', false, false],
            ['y', true],
            ['yes', true],
            ['n', false],
            ['no', false],
        ];
    }

    public function testAskConfirmationWithCustomTrueAndFalseAnswer(): void
    {
        $question = new StrictConfirmationQuestion('Do you like French fries?', false, '/^ja$/i', '/^nein$/i');

        [$input, $dialog] = $this->createInput("ja\n");
        $this->assertTrue($dialog->ask($input, $this->createOutputInterface(), $question));

        [$input, $dialog] = $this->createInput("nein\n");
        $this->assertFalse($dialog->ask($input, $this->createOutputInterface(), $question));
    }

    /**
     * @return resource
     */
    protected function getInputStream(string $input)
    {
        $stream = fopen('php://memory', 'r+', false);
        $this->assertNotFalse($stream);

        fwrite($stream, $input);
        rewind($stream);

        return $stream;
    }

    protected function createOutputInterface(): StreamOutput
    {
        return new StreamOutput(fopen('php://memory', 'r+', false));
    }

    /**
     * @return object[]
     *
     * @phpstan-return array{ArrayInput, QuestionHelper}
     */
    protected function createInput(string $entry): array
    {
        $input = new ArrayInput(['--no-interaction']);
        $input->setStream($this->getInputStream($entry));

        $dialog = new QuestionHelper();

        return [$input, $dialog];
    }
}
