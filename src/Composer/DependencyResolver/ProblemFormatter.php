<?php

namespace Composer\DependencyResolver;

class ProblemFormatter
{
	const COULD_NOT_BE_FOUND_OCCURRENCE = 'could not be found';
	const NO_MATCHING_PACKAGE_FOUND_OCCURENCE = 'no matching package found';

	const TYPO_ADVICE_LABEL = 'typo';
	const TYPO_ADVICE_STRING = "Potential causes:\n - A typo in the package name\n - The package is not available in a stable-enough version according to your minimum-stability setting\n   see <https://groups.google.com/d/topic/composer-dev/_g3ASeIFlrc/discussion> for more details.\n\nRead <http://getcomposer.org/doc/articles/troubleshooting.md> for further common problems.";

	protected $advices;

	public function __construct(array $installedMap)
	{
		$this->installedMap = $installedMap;
		$this->advices = array();
	}

	public function format(Problem $problem, $position)
	{
		$prettyString = $problem->getPrettyString($this->installedMap);
		$this->extractAdvices($prettyString);

		$text = "  Problem ".($position+1).$prettyString."\n";

		$formattedProblem = array("advices" => $this->advices, "text" => $text);

		return $formattedProblem;
	}

	protected function extractAdvices($prettyString)
	{
		 if (strpos($prettyString, self::COULD_NOT_BE_FOUND_OCCURRENCE) || strpos($prettyString, self::NO_MATCHING_PACKAGE_FOUND_OCCURENCE)) {
		 	$this->advices[self::TYPO_ADVICE_LABEL] = self::TYPO_ADVICE_STRING;
		 }
	}
}