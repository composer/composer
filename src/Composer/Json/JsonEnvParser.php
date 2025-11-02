<?php

declare(strict_types=1);

namespace Composer\Json;

use Composer\IO\IOInterface;
use Composer\Pcre\Preg;
use Composer\Util\Platform;

class JsonEnvParser
{
	/** @var IOInterface|null */
	protected $io;

	/** @var array<string, true> List of env var keys we already warned about */
	protected static $envVariablesComplainedAbout = [];

	/** @param IOInterface|null $io */
	public function __construct(?IOInterface $io = null)
	{
		$this->io = $io;
	}

	/** @return array<string|int, mixed> Recursively applies environment variables from string like ${EXAMPLE_VALUE} will take EXAMPLE_VALUE from the $_ENV and replace it. */
	public function apply(array $data, ?string $file = null): array
	{
		$result = [];
		foreach ($data as $key => $value) {
			if (is_array($value)) {
				$result[$key] = $this->apply($value, $file);
				continue;
			}

			if (is_string($value)) {
				$result[$key] = $this->replacePlaceholders($value, $file);
				continue;
			}

			$result[$key] = $value;
		}

		return $result;
	}

	/** @return string Replaces placeholders within a string value as part of function apply. */
	private function replacePlaceholders(string $value, ?string $file = null): string
	{
		return Preg::replaceCallback(
			'/\$\{([^}]+)\}/',
			function (array $matches) use ($file): string {
				return $this->resolvePlaceholder($matches[1], $file);
			},
			$value
		);
	}

	/** @return string Resolves a single placeholder to its environment value part of funtion replacePlaceholders. */
	private function resolvePlaceholder(string $name, ?string $file = null): string
	{
		$env = Platform::getEnv($name);

		if (empty($env)) {
			$context = $file !== null ? $file . ': ' : '';

			if (!in_array($name, self::$envVariablesComplainedAbout)) {
				$this->io->warning($context . 'Environment variable ' . $name . ' is not defined, please update your .env file');
				self::$envVariablesComplainedAbout[] = $name;
			}

			return $name;
		}

		return (string) $env;
	}
}
