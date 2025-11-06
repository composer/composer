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

	/** @var array<string, true> Map of env/file combinations already warned about */
	protected static $envVariablesComplainedAbout = [];

	/** @param IOInterface|null $io */
	public function __construct(?IOInterface $io = null)
	{
		$this->io = $io;
	}

	/**
	 * @param array<string|int, mixed> $data
	 * @return array<string|int, mixed>
	 */
	public function apply(array $data, ?string $file = null): array
	{
		foreach ($data as $key => &$value) {
			if (is_array($value)) {
				$value = $this->apply($value, $file);
				continue;
			}

			if (is_string($value)) {
				$value = $this->replacePlaceholders($value, $file);
				continue;
			}
		}

		return $data;
	}

	/** @return string */
	private function replacePlaceholders(string $value, ?string $file = null): string
	{
		return Preg::replaceCallback(
			'/\$\{([^}]+)\}/',
			function (array $matches) use ($file): string {
				return $this->resolvePlaceholder($matches[1], $matches[0], $file);
			},
			$value
		);
	}

	/**
	 * @return string Resolves a single placeholder to its environment value part of funtion replacePlaceholders.
	 */
	private function resolvePlaceholder(string $name, string $placeholder, ?string $file = null): string
	{
		if ($name === '') {
			return $placeholder;
		}

		$env = Platform::getEnv($name);

		if ($env === false || $env === null || $env === '') {
			$context = $file !== null ? $file . ': ' : '';
			$key = ($file ?? '') . '|' . $placeholder;

			if (!isset(self::$envVariablesComplainedAbout[$key])) {
				if ($this->io !== null) {
					$this->io->writeError($context . '<warning>Environment variable ' . $name . ' is not defined, please update your .env file</warning>');
				}
				self::$envVariablesComplainedAbout[$key] = true;
			}

			return $placeholder;
		}

		return (string) $env;
	}
}
