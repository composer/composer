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

namespace Composer\Console;

/**
 * Encodes a root record for the OSC 7501 Program Status Protocol.
 *
 * @see https://www.superlogical.com/rex/docs/build/program-status
 */
final class Osc7501ProgramStatusEncoder extends AbstractOscProgramStatusEncoder
{
    public const PROTOCOL = 'osc7501';
    public const KIND_PERMISSION = 'permission';
    public const KIND_QUESTION = 'question';
    public const KIND_AUTH = 'auth';

    private const VALID_KINDS = [self::KIND_PERMISSION, self::KIND_QUESTION, self::KIND_AUTH];

    public function getCommand(): string
    {
        return '7501';
    }

    public function encode(ProgramStatus $status): string
    {
        $kind = $this->getKind($status);
        $payload = 'state='.$status->getState().':app=composer';
        if ($status->getProgress() !== null) {
            $payload .= ':progress='.$status->getProgress();
        }
        if ($kind !== null) {
            $payload .= ':kind='.$kind;
        }
        if ($status->getMessage() !== null) {
            $payload .= ':msg='.base64_encode($this->sanitizeMessage($status->getMessage(), 2048));
        }

        return $payload;
    }

    private function getKind(ProgramStatus $status): ?string
    {
        $kind = $status->getProtocolOption(self::PROTOCOL, 'kind');
        if ($kind === null) {
            return null;
        }
        if ($status->getState() !== ProgramStatus::STATE_BLOCKED || !is_string($kind) || !in_array($kind, self::VALID_KINDS, true)) {
            throw new \InvalidArgumentException('An OSC 7501 kind must be permission, question, or auth on a blocked program.');
        }

        return $kind;
    }
}
