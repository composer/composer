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
use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Console\Input\InputOption as BaseInputOption;

/**
 * Backport suggested values definition from symfony/console 6.1+
 *
 * @author Jérôme Tamarelle <jerome@tamarelle.net>
 *
 * @internal
 */
class InputOption extends BaseInputOption
{
    /**
     * @var string[]|\Closure
     */
    private $suggestedValues;

    /**
     * @inheritdoc
     *
     * @param string[]|\Closure(CompletionInput,CompletionSuggestions):list<string|Suggestion> $suggestedValues The values used for input completion
     */
    public function __construct(string $name, $shortcut = null, int $mode = null, string $description = '', $default = null, $suggestedValues = [])
    {
        parent::__construct($name, $shortcut, $mode, $description, $default, $suggestedValues);

        $this->suggestedValues = $suggestedValues;

        if ($suggestedValues && !$this->acceptValue()) {
            throw new LogicException('Cannot set suggested values if the option does not accept a value.');
        }
    }

    /**
     * Adds suggestions to $suggestions for the current completion input.
     *
     * @see Command::complete()
     */
    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        $values = $this->suggestedValues;
        if ($values instanceof \Closure && !\is_array($values = $values($input))) {
            throw new LogicException(sprintf('Closure for argument "%s" must return an array. Got "%s".', $this->getName(), get_debug_type($values)));
        }
        if ($values) {
            $suggestions->suggestValues($values);
        }
    }
}
