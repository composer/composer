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

namespace Composer\Test\Command;

use Composer\Command\InitCommand;
use Composer\Test\TestCase;

class InitCommandTest extends TestCase
{
    public function testParseValidAuthorString(): void
    {
        $command = new InitCommand;
        $author = $this->callParseAuthorString($command, 'John Smith <john@example.com>');
        $this->assertEquals('John Smith', $author['name']);
        $this->assertEquals('john@example.com', $author['email']);
    }

    public function testParseValidAuthorStringWithoutEmail(): void
    {
        $command = new InitCommand;
        $author = $this->callParseAuthorString($command, 'John Smith');
        $this->assertEquals('John Smith', $author['name']);
        $this->assertNull($author['email']);
    }

    public function testParseValidUtf8AuthorString(): void
    {
        $command = new InitCommand;
        $author = $this->callParseAuthorString($command, 'Matti Meikäläinen <matti@example.com>');
        $this->assertEquals('Matti Meikäläinen', $author['name']);
        $this->assertEquals('matti@example.com', $author['email']);
    }

    public function testParseValidUtf8AuthorStringWithNonSpacingMarks(): void
    {
        // \xCC\x88 is UTF-8 for U+0308 diaeresis (umlaut) combining mark
        $utf8_expected = "Matti Meika\xCC\x88la\xCC\x88inen";
        $command = new InitCommand;
        $author = $this->callParseAuthorString($command, $utf8_expected." <matti@example.com>");
        $this->assertEquals($utf8_expected, $author['name']);
        $this->assertEquals('matti@example.com', $author['email']);
    }

    public function testParseNumericAuthorString(): void
    {
        $command = new InitCommand;
        $author = $this->callParseAuthorString($command, 'h4x0r <h4x@example.com>');
        $this->assertEquals('h4x0r', $author['name']);
        $this->assertEquals('h4x@example.com', $author['email']);
    }

    /**
     * Test scenario for issue #5631
     * @link https://github.com/composer/composer/issues/5631 Issue #5631
     */
    public function testParseValidAlias1AuthorString(): void
    {
        $command = new InitCommand;
        $author = $this->callParseAuthorString(
            $command,
            'Johnathon "Johnny" Smith <john@example.com>'
        );
        $this->assertEquals('Johnathon "Johnny" Smith', $author['name']);
        $this->assertEquals('john@example.com', $author['email']);
    }

    /**
     * Test scenario for issue #5631
     * @link https://github.com/composer/composer/issues/5631 Issue #5631
     */
    public function testParseValidAlias2AuthorString(): void
    {
        $command = new InitCommand;
        $author = $this->callParseAuthorString(
            $command,
            'Johnathon (Johnny) Smith <john@example.com>'
        );
        $this->assertEquals('Johnathon (Johnny) Smith', $author['name']);
        $this->assertEquals('john@example.com', $author['email']);
    }

    public function testParseEmptyAuthorString(): void
    {
        $command = new InitCommand;
        self::expectException('InvalidArgumentException');
        $this->callParseAuthorString($command, '');
    }

    public function testParseAuthorStringWithInvalidEmail(): void
    {
        $command = new InitCommand;
        self::expectException('InvalidArgumentException');
        $this->callParseAuthorString($command, 'John Smith <john>');
    }

    public function testNamespaceFromValidPackageName(): void
    {
        $command = new InitCommand;
        $namespace = $command->namespaceFromPackageName('new_projects.acme-extra/package-name');
        $this->assertEquals('NewProjectsAcmeExtra\PackageName', $namespace);
    }

    public function testNamespaceFromInvalidPackageName(): void
    {
        $command = new InitCommand;
        $namespace = $command->namespaceFromPackageName('invalid-package-name');
        $this->assertNull($namespace);
    }

    public function testNamespaceFromMissingPackageName(): void
    {
        $command = new InitCommand;
        $namespace = $command->namespaceFromPackageName('');
        $this->assertNull($namespace);
    }

    public function testFormatAuthors(): void
    {
        $authorWithEmail = 'John Smith <john@example.com>';
        $authorWithoutEmail = 'John Smith';
        $command = new DummyInitCommand;
        $authors = $command->formatAuthors($authorWithEmail);
        $this->assertEquals(['name' => 'John Smith', 'email' => 'john@example.com'], $authors[0]);
        $authors = $command->formatAuthors($authorWithoutEmail);
        $this->assertEquals(['name' => 'John Smith'], $authors[0]);
    }

    public function testGetGitConfig(): void
    {
        $command = new DummyInitCommand;
        $gitConfig = $command->getGitConfig();
        $this->assertArrayHasKey('user.name', $gitConfig);
        $this->assertArrayHasKey('user.email', $gitConfig);
        $this->assertArrayHasKey('remote.origin.url', $gitConfig);
    }

    public function testAddVendorIgnore(): void
    {
        $command = new DummyInitCommand;
        $ignoreFile = self::getUniqueTmpDirectory().'/ignore';
        $command->addVendorIgnore($ignoreFile);
        $this->assertFileExists($ignoreFile);
        if (file_exists($ignoreFile)) {
            $content = (string) file_get_contents($ignoreFile);
            $this->assertStringContainsString('/vendor/', $content);
        }
    }

    public function testHasVendorIgnore(): void
    {
        $command = new DummyInitCommand;
        $ignoreFile = self::getUniqueTmpDirectory().'/ignore';
        $this->assertFalse($command->hasVendorIgnore($ignoreFile));
        $command->addVendorIgnore($ignoreFile);
        $this->assertTrue($command->hasVendorIgnore($ignoreFile));
    }

    /**
     * @return array{name: string, email: string|null}
     */
    private function callParseAuthorString(InitCommand $command, string $string): array
    {
        $reflMethod = new \ReflectionMethod($command, 'parseAuthorString');
        $reflMethod->setAccessible(true);

        return $reflMethod->invoke($command, $string);
    }
}

class DummyInitCommand extends InitCommand
{
    public function formatAuthors(string $author): array
    {
        return parent::formatAuthors($author);
    }

    public function getGitConfig(): array
    {
        return parent::getGitConfig();
    }

    public function addVendorIgnore(string $ignoreFile, string $vendor = '/vendor/'): void
    {
        parent::addVendorIgnore($ignoreFile, $vendor);
    }

    public function hasVendorIgnore(string $ignoreFile, string $vendor = 'vendor'): bool
    {
        return parent::hasVendorIgnore($ignoreFile, $vendor);

    }
}
