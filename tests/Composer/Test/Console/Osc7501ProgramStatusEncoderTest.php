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

namespace Composer\Test\Console;

use Composer\Console\Osc7501ProgramStatusEncoder;
use Composer\Console\ProgramStatus;
use Composer\Test\TestCase;

class Osc7501ProgramStatusEncoderTest extends TestCase
{
    public function testEncodesRootRecordWithProgress(): void
    {
        $encoder = new Osc7501ProgramStatusEncoder();
        self::assertSame('7501', $encoder->getCommand());
        self::assertSame('state=working:app=composer:progress=0', $encoder->encode(ProgramStatus::working()->withProgress(0)));
        self::assertSame('state=working:app=composer:progress=100', $encoder->encode(ProgramStatus::working()->withProgress(100)));
        self::assertSame('state=done:app=composer', $encoder->encode(ProgramStatus::done()));
    }

    /** @dataProvider provideKinds */
    public function testEncodesSupportedKinds(string $kind): void
    {
        $status = ProgramStatus::blocked()->withProtocolOption('osc7501', 'kind', $kind);
        self::assertSame('state=blocked:app=composer:kind='.$kind, (new Osc7501ProgramStatusEncoder())->encode($status));
    }

    public static function provideKinds(): array
    {
        return [['permission'], ['question'], ['auth']];
    }

    /**
     * @dataProvider provideInvalidKinds
     * @param bool|int|float|string|array<mixed> $kind
     */
    public function testRejectsInvalidKindsWhenEncoding($kind): void
    {
        $status = ProgramStatus::blocked()->withProtocolOption('osc7501', 'kind', $kind);
        $this->expectException(\InvalidArgumentException::class);
        (new Osc7501ProgramStatusEncoder())->encode($status);
    }

    public static function provideInvalidKinds(): array
    {
        return [['foobar'], ['permission:msg=injected'], [false], [42], [1.5], [''], [[]], [['permission']]];
    }

    public function testRejectsKindForNonBlockedStateWhenEncoding(): void
    {
        $status = ProgramStatus::working()->withProtocolOption('osc7501', 'kind', 'auth');
        $this->expectException(\InvalidArgumentException::class);
        (new Osc7501ProgramStatusEncoder())->encode($status);
    }

    public function testIgnoresOtherProtocolOptionsAndAbsentKind(): void
    {
        $encoder = new Osc7501ProgramStatusEncoder();
        $status = ProgramStatus::blocked()->withProtocolOption('oscFuture', 'kind', ['nested' => [false]]);
        self::assertSame('state=blocked:app=composer', $encoder->encode($status));
        $status = $status->withProtocolOption('osc7501', 'kind', null);
        self::assertSame('state=blocked:app=composer', $encoder->encode($status));
    }

    public function testSanitizesAndEncodesMessage(): void
    {
        $status = ProgramStatus::blocked("<info>Approve?</info>\n\0\x7f\xc2\x85\033]2;title\007")->withProtocolOption(Osc7501ProgramStatusEncoder::PROTOCOL, 'kind', Osc7501ProgramStatusEncoder::KIND_PERMISSION);
        self::assertSame('state=blocked:app=composer:kind=permission:msg='.base64_encode('Approve?'), (new Osc7501ProgramStatusEncoder())->encode($status));
    }

    /**
     * @dataProvider messageLimitProvider
     */
    public function testMessageLimitPreservesUtf8(string $message, string $expected): void
    {
        $status = ProgramStatus::working($message);
        self::assertSame('state=working:app=composer:msg='.base64_encode($expected), (new Osc7501ProgramStatusEncoder())->encode($status));
        self::assertLessThan(4096, strlen((new Osc7501ProgramStatusEncoder())->encode($status)) + 9);
    }

    public static function messageLimitProvider(): array
    {
        return [
            [str_repeat('a', 2049), str_repeat('a', 2048)],
            [str_repeat('a', 2046).'éx', str_repeat('a', 2046).'é'],
            [str_repeat('a', 2047).'é', str_repeat('a', 2047)],
            [str_repeat('a', 2046).'€', str_repeat('a', 2046)],
            [str_repeat('a', 2045).'😀', str_repeat('a', 2045)],
        ];
    }
}
