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

namespace Composer\Console\Input;

use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Completion\Suggestion;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Console\Input\InputArgument as BaseInputArgument;

/**
 * Backport suggested values definition from symfony/console 6.1+
 *
 * @author Jérôme Tamarelle <jerome@tamarelle.net>
 *
 * @internal
 *
 * TODO drop when PHP 8.1 / symfony 6.1+ can be required
 */
class InputArgument extends BaseInputArgument
{
    /**
     * @var list<string>|\Closure(CompletionInput,CompletionSuggestions):list<string|Suggestion>
     */
    private $suggestedValues;

    /**
     * @param string                              $name        The argument name
     * @param int|null                            $mode        The argument mode: self::REQUIRED or self::OPTIONAL
     * @param string                              $description A description text
     * @param string|bool|int|float|string[]|null $default     The default value (for self::OPTIONAL mode only)
     * @param list<string>|\Closure(CompletionInput,CompletionSuggestions):list<string|Suggestion> $suggestedValues The values used for input completion
     *
     * @throws InvalidArgumentException When argument mode is not valid
     */
    public function __construct(string $name, ?int $mode = null, string $description = '', $default = null, $suggestedValues = [])
    {
        parent::__construct($name, $mode, $description, $default);

        $this->suggestedValues = $suggestedValues;
    }

    /**
     * Adds suggestions to $suggestions for the current completion input.
     *
     * @see Command::complete()
     */
    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        $values = $this->suggestedValues;
        if ($values instanceof \Closure && !\is_array($values = $values($input, $suggestions))) { // @phpstan-ignore function.impossibleType
            throw new LogicException(sprintf('Closure for option "%s" must return an array. Got "%s".', $this->getName(), get_debug_type($values)));
        }
        if ([] !== $values) {
            $suggestions->suggestValues($values);
        }
    }
}
