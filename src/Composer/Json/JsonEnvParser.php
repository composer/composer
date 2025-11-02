<?php

declare(strict_types=1);

namespace Composer\Json;

use Composer\IO\IOInterface;
use Composer\Pcre\Preg;
use Composer\Util\Platform;

class JsonEnvParser
{
	/** @var IOInterface|null */
	protected ?IOInterface $io;

	protected static array $envVariablesComplainedAbout = [];

	public function __construct(?IOInterface $io = null)
	{
		$this->io = $io;
	}

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
