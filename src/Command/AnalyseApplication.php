<?php declare(strict_types = 1);

namespace PHPStan\Command;

use PhpParser\Node;
use PHPStan\Analyser\Analyser;
use PHPStan\Analyser\Scope;
use PHPStan\Cache\Cache;
use PHPStan\Command\ErrorFormatter\ErrorFormatter;
use PHPStan\Type\MixedType;

class AnalyseApplication
{

	/** @var \PHPStan\Analyser\Analyser */
	private $analyser;

	/** @var string */
	private $memoryLimitFile;

	/** @var \PHPStan\Cache\Cache */
	private $cache;

	public function __construct(
		Analyser $analyser,
		string $memoryLimitFile,
		Cache $cache
	)
	{
		$this->analyser = $analyser;
		$this->memoryLimitFile = $memoryLimitFile;
		$this->cache = $cache;
	}

	/**
	 * @param string[] $files
	 * @param bool $onlyFiles
	 * @param \PHPStan\Command\Output $stdOutput
	 * @param \PHPStan\Command\Output $errorOutput
	 * @param \PHPStan\Command\ErrorFormatter\ErrorFormatter $errorFormatter
	 * @param bool $defaultLevelUsed
	 * @param bool $debug
	 * @param string|null $projectConfigFile
	 * @return int Error code.
	 */
	public function analyse(
		array $files,
		bool $onlyFiles,
		Output $stdOutput,
		Output $errorOutput,
		ErrorFormatter $errorFormatter,
		bool $defaultLevelUsed,
		bool $debug,
		?string $projectConfigFile,
		bool $changed = false
	): int
	{
		$this->updateMemoryLimitFile();
		$errors = [];

		register_shutdown_function(function (): void {
			$error = error_get_last();
			if ($error === null) {
				return;
			}
			if ($error['type'] !== E_ERROR) {
				return;
			}

			if (strpos($error['message'], 'Allowed memory size') !== false) {
				return;
			}

			@unlink($this->memoryLimitFile);
		});

		if ($changed) {
			$timestampCacher = function (string $file): void {
				$timestamp = filemtime($file);
				if ($timestamp === false) {
					return;
				}
				$this->cache->save(sprintf('filemtime-%s', $file), $timestamp);
			};
		} else {
			$timestampCacher = static function (string $file): void {
			};
		}

		if (!$debug) {
			$progressStarted = false;
			$fileOrder = 0;
			$preFileCallback = null;
			$postFileCallback = function (string $file) use ($errorOutput, &$progressStarted, $files, &$fileOrder, $timestampCacher): void {
				if (!$progressStarted) {
					$errorOutput->getStyle()->progressStart(count($files));
					$progressStarted = true;
				}
				$errorOutput->getStyle()->progressAdvance();
				if ($fileOrder % 100 === 0) {
					$this->updateMemoryLimitFile();
				}
				$fileOrder++;

				$timestampCacher($file);
			};
		} else {
			$preFileCallback = static function (string $file) use ($stdOutput): void {
				$stdOutput->writeLineFormatted($file);
			};
			$postFileCallback = $timestampCacher;
		}

		$hasInferrablePropertyTypesFromConstructor = false;
		$errors = array_merge($errors, $this->analyser->analyse(
			$files,
			$onlyFiles,
			$preFileCallback,
			$postFileCallback,
			$debug,
			static function (Node $node, Scope $scope) use (&$hasInferrablePropertyTypesFromConstructor): void {
				if ($hasInferrablePropertyTypesFromConstructor) {
					return;
				}

				if (!$node instanceof Node\Stmt\PropertyProperty) {
					return;
				}

				if (!$scope->isInClass()) {
					return;
				}

				$classReflection = $scope->getClassReflection();
				if (!$classReflection->hasConstructor() || $classReflection->getConstructor()->getDeclaringClass()->getName() !== $classReflection->getName()) {
					return;
				}
				$propertyName = $node->name->toString();
				if (!$classReflection->hasNativeProperty($propertyName)) {
					return;
				}
				$propertyReflection = $classReflection->getNativeProperty($propertyName);
				if (!$propertyReflection->isPrivate()) {
					return;
				}
				$propertyType = $propertyReflection->getReadableType();
				if (!$propertyType instanceof MixedType || $propertyType->isExplicitMixed()) {
					return;
				}

				$hasInferrablePropertyTypesFromConstructor = true;
			}
		));

		if (isset($progressStarted) && $progressStarted) {
			$errorOutput->getStyle()->progressFinish();
		}

		$fileSpecificErrors = [];
		$notFileSpecificErrors = [];
		foreach ($errors as $error) {
			if (is_string($error)) {
				$notFileSpecificErrors[] = $error;
			} else {
				$fileSpecificErrors[] = $error;
			}
		}

		return $errorFormatter->formatErrors(
			new AnalysisResult(
				$fileSpecificErrors,
				$notFileSpecificErrors,
				$defaultLevelUsed,
				$hasInferrablePropertyTypesFromConstructor,
				$projectConfigFile
			),
			$stdOutput
		);
	}

	private function updateMemoryLimitFile(): void
	{
		$bytes = memory_get_peak_usage(true);
		$megabytes = ceil($bytes / 1024 / 1024);
		file_put_contents($this->memoryLimitFile, sprintf('%d MB', $megabytes));
	}

}
