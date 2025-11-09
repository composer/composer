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

	/** @var bool Whether we already warned about unreadable dotenv file */
	protected static $unreadableEnvWarned = false;

	/** @var null|string Whether we already warned about unreadable dotenv file */
	protected static $dotEnvPath = null;

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
		if (!is_readable(self::$dotEnvPath)) {
			if ($this->io instanceof IOInterface && self::$unreadableEnvWarned === false) {
				$this->io->warning('Dotenv file ' . self::$dotEnvPath . ' is not readable.');
				self::$unreadableEnvWarned = true;
			}

			return $placeholder;
		}

		if ($name === '') {
			return $placeholder;
		}

		$env = Platform::getEnv($name);

		if ($env === false || $env === null || $env === '') {
			$env = $this->getEnvValue($name);
		}

		if ($env === null || $env === '') {
			$key = ($file ?? '') . '|' . $placeholder;

			if (!isset(self::$envVariablesComplainedAbout[$key])) {
				if ($this->io instanceof IOInterface) {
					$this->io->writeError('<warning>Environment variable \'' . $name . '\'' . ($file === null ? '' : ' (found in ' . $file . ')') . ' is not defined, please update your .env file (' . (self::getDotEnvPath() ?? 'not found') . '). </warning>');
				}
				self::$envVariablesComplainedAbout[$key] = true;
			}

			return $placeholder;
		}

		return (string) $env;
	}

	private function getEnvValue(string $name): ?string
	{
		if (self::getDotEnvPath() === null) {
			return null;
		}

		$content = file_get_contents(self::getDotEnvPath());
		if ($content === false) {
			return null;
		}

		$pattern = '/^\s*' . preg_quote($name, '/') . '\s*=\s*(.*)$/m';
		if (Preg::match($pattern, $content, $matches) === 1) {
			return $matches[1];
		}

		return null;
	}

	/**
	 * @param string $path
	 * @param IOInterface|null $io
	 * @return void
	 */
	public static function setDotEnvPath(string $path, ?IOInterface $io): void
	{
		self::$dotEnvPath = $path;
	}

	/**
	 * @return string
	 */
	public static function getDotEnvPath(): ?string
	{
		return self::$dotEnvPath;
	}
}
