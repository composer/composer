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

namespace Composer\Json;

/**
 * Format JSON output
 *
 * @author Justin Rainbow <justin.rainbow@gmail.com>
 */
class JsonFormatter
{
	private $indent = '    ';

	private $level = 1;

	/**
	 * Indents a flat JSON string to make it more human-readable
	 *
	 * Original code for this function can be found at:
	 *  http://recursive-design.com/blog/2008/03/11/format-json-with-php/
	 *
	 * @param string $json The original JSON string to process
	 * @return string Indented version of the original JSON string
	 */
	public function format($json)
	{
		if (!is_string($json)) {
			$json = json_encode($json);
		}

		$result = '';
		$pos = 0;
		$strLen = strlen($json);
		$indentStr = $this->indent;
		$newLine = "\n";
		$prevChar = '';
		$outOfQuotes = true;

		for ($i = 0; $i <= $strLen; $i++) {
			// Grab the next character in the string
			$char = substr($json, $i, 1);

			// Are we inside a quoted string?
			if ($char == '"' && $prevChar != '\\') {
				$outOfQuotes = !$outOfQuotes;
			} else if (($char == '}' || $char == ']') && $outOfQuotes) {
				// If this character is the end of an element,
				// output a new line and indent the next line
				$result .= $newLine;
				$pos --;
				for ($j=0; $j<$pos; $j++) {
					$result .= $indentStr;
				}
			}

			// Add the character to the result string
			$result .= $char;

			// If the last character was the beginning of an element,
			// output a new line and indent the next line
			if (($char == ',' || $char == '{' || $char == '[') && $outOfQuotes) {
				$result .= $newLine;

				if ($char == '{' || $char == '[') {
					$pos ++;
				}

				for ($j = 0; $j < $pos; $j++) {
					$result .= $indentStr;
				}
			}

			$prevChar = $char;
		}

		return $result;
	}
}