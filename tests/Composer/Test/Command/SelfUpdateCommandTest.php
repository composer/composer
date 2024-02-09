<?php declare(strict_types=1);

namespace Composer\Test\Command;

use Composer\Test\TestCase;

class SelfUpdateCommandTest extends TestCase
{
    public function testSuccessfulUpdate(): void
    {
        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'self-update']);

        $appTester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Upgrading to version', $appTester->getDisplay());
    }

    public function testUpdateToSpecificVersion(): void
    {
        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'self-update', 'version' => '2.4.0']);
        
        $appTester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Upgrading to version 2.4.0', $appTester->getDisplay());
    }

    public function testUpdateWithInvalidOptionThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The "invalid-option" argument does not exist.');

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'self-update', 'invalid-option' => true]);   
    }

    /**
     * @dataProvider channelOptions
     */
    public function testUpdateToDifferentChannel(string $option, string $expectedOutput): void
    {
        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'self-update', $option => true]);
        $appTester->assertCommandIsSuccessful();

        $this->assertStringContainsString('Upgrading to version', $appTester->getDisplay());
        $this->assertStringContainsString($expectedOutput, $appTester->getDisplay());
    }

    public function channelOptions(): array
    {
        return [
            ['--stable', 'stable channel'],
            ['--preview', 'preview channel'],
            ['--snapshot', 'snapshot channel'],
        ];
    }
}
