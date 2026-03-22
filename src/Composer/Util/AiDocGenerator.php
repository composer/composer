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

namespace Composer\Util;

use Composer\DependencyResolver\LocalRepoTransaction;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\Installer\InstallationManager;
use Composer\Package\PackageInterface;

/**
 * Service for managing the COMPOSER_AI.md file.
 */
class AiDocGenerator
{
    const FILENAME = 'COMPOSER_AI.md';
    const VENDOR_INDEX_KEY = 'vendor_case_index';
    const DEFAULT_TEMPLATE = <<<'MD'
# Vendor instructions

## Vendor index contract
- Vendor instruction index MUST be stored as YAML under the `%1$s` key.
- Each entry has the structure `file_path: load_for`.
- `load_for` MUST describe when the instruction should be loaded (string or list of triggers).
- Agents MUST load only instructions required for the current task.
- Agents MUST prevent duplicate loads if an instruction is already in memory.

## Vendor index
```yaml
%1$s:
```
MD;

    /**
     * @var string[]
     */
    private $lines = [];
    /** @var InstallationManager */
    private $installationManager;
    /** @var Filesystem */
    private $fs;

    public function __construct(
        InstallationManager $installationManager
    )
    {
        $this->installationManager = $installationManager;
        $this->fs = new Filesystem();
    }

    public function updateIndex(LocalRepoTransaction $transaction): void
    {
        $file = $this->initializeFile();
        $this->readLines($file);
        $startIndexString = $this->getLineNumberByContains(self::VENDOR_INDEX_KEY . ':');

        if (null === $startIndexString) {
            throw new \RuntimeException('Vendor index not found');
        }

        foreach ($transaction->getOperations() as $operation) {
            if ($operation instanceof InstallOperation) {
                $line = $this->renderLine($operation->getPackage());
                if ('' !== $line){
                    $this->insertLineAfterNumber($startIndexString, $line);
                }
            } elseif ($operation instanceof UpdateOperation) {
                $package = $operation->getTargetPackage();
                $newDeclaration = $this->renderLine($package);
                if (null !== $this->getLineNumberByContains($newDeclaration)) {
                    continue;
                }

                $oldNumber = $this->getLineNumberByContains($package->getName());
                if (null !== $newDeclaration) {
                    null === $oldNumber ?
                        $this->insertLineAfterNumber($startIndexString, $newDeclaration) :
                        $this->updateLineByNumber($oldNumber, $newDeclaration);
                } elseif (null !== $oldNumber) {
                    $this->deleteLineByNumber($oldNumber);
                }
            } elseif ($operation instanceof UninstallOperation) {
                $line = $this->getLineNumberByContains($this->renderLine($operation->getPackage()));
                if (null !== $line) {
                    $this->deleteLineByNumber($line);
                }
            }
        }

        $this->writeContent($file, implode('', $this->lines));
    }

    private function renderLine(PackageInterface $package): string
    {
        $data = $package->getExtra()['ai-doc'] ?? [];
        if (!isset($data['path']) || !isset($data['load_for'])) {
            return '';
        }

        $installPath = $this->installationManager->getInstallPath($package);
        if (null === $installPath) {
            return '';
        }

        return sprintf(
            '    %s%s%s: %s%s',
            $this->fs->findShortestPath(Platform::getCwd(), $installPath),
            DIRECTORY_SEPARATOR,
            $data['path'],
            $data['load_for'],
            PHP_EOL
        );
    }

    private function initializeFile(): \SplFileObject
    {
        $filePath = Platform::getCwd() . '/' . self::FILENAME;
        if (!file_exists($filePath)) {
            if (false === file_put_contents($filePath, sprintf(self::DEFAULT_TEMPLATE, self::VENDOR_INDEX_KEY) . PHP_EOL)) {
                throw new \RuntimeException(sprintf('Failed to create file "%s".', $filePath));
            }
        }

        if (!is_file($filePath) || !is_readable($filePath) || !is_writable($filePath)) {
            throw new \RuntimeException(sprintf('File "%s" must be a readable and writable regular file.', $filePath));
        }

        try {
            return new \SplFileObject($filePath, 'c+');
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(sprintf('Failed to open file "%s".', $filePath), 0, $e);
        }
    }

    public function getLineNumberByContains(string $needle): ?int
    {
        if ('' === $needle) {
            return null;
        }

        foreach ($this->lines as $index => $line) {
            if (str_contains($line, $needle)) {
                return $index;
            }
        }

        return null;
    }

    public function insertLineAfterNumber(int $lineNumber, string $line): void
    {
        array_splice($this->lines, $lineNumber + 1, 0, $line);
    }

    public function updateLineByNumber(int $lineNumber, string $line): void
    {
        $this->lines[$lineNumber] = $line;
    }

    public function deleteLineByNumber(int $lineNumber): void
    {
        array_splice($this->lines, $lineNumber, 1);
    }

    private function writeContent(\SplFileObject $file, string $content): void
    {
        $file->rewind();
        $file->ftruncate(0);
        $written = $file->fwrite($content);
        if (false === $written) {
            throw new \RuntimeException('Failed to write updated vendor index.');
        }
        $file->fflush();
    }

    private function readLines(\SplFileObject $file): void
    {
        $file->rewind();
        $this->lines = [];
        while (!$file->eof()) {
            $this->lines[] = $file->fgets();
        }
    }
}
