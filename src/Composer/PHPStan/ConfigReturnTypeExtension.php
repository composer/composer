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

namespace Composer\PHPStan;

use Composer\Config;
use Composer\Json\JsonFile;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\IntegerRangeType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\UnionType;

final class ConfigReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    /** @var array<string, \PHPStan\Type\Type> */
    private $properties = [];

    public function __construct()
    {
        $schema = JsonFile::parseJson((string) file_get_contents(__DIR__.'/../../../res/composer-schema.json'));
        /**
         * @var string $prop
         */
        foreach ($schema['properties']['config']['properties'] as $prop => $conf) {
            $type = $this->parseType($conf, $prop);

            $this->properties[$prop] = $type;
        }
    }

    public function getClass(): string
    {
        return Config::class;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return strtolower($methodReflection->getName()) === 'get';
    }

    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): Type
    {
        $args = $methodCall->getArgs();

        if (count($args) < 1) {
            return ParametersAcceptorSelector::selectSingle($methodReflection->getVariants())->getReturnType();
        }

        $keyType = $scope->getType($args[0]->value);
        if (count($keyType->getConstantStrings()) > 0) {
            foreach ($keyType->getConstantStrings() as $constantString) {
                if (isset($this->properties[$constantString->getValue()])) {
                    return $this->properties[$constantString->getValue()];
                }
            }
        }

        return ParametersAcceptorSelector::selectSingle($methodReflection->getVariants())->getReturnType();
    }

    /**
     * @param array<mixed> $def
     */
    private function parseType(array $def, string $path): Type
    {
        if (isset($def['type'])) {
            $types = [];
            foreach ((array) $def['type'] as $type) {
                switch ($type) {
                    case 'integer':
                        if (in_array($path, ['process-timeout', 'cache-ttl', 'cache-files-ttl', 'cache-files-maxsize'], true)) {
                            $types[] = IntegerRangeType::createAllGreaterThanOrEqualTo(0);
                        } else {
                            $types[] = new IntegerType();
                        }
                        break;

                    case 'string':
                        if ($path === 'cache-files-maxsize') {
                            // passthru, skip as it is always converted to int
                        } elseif ($path === 'discard-changes') {
                            $types[] = new ConstantStringType('stash');
                        } elseif ($path === 'use-parent-dir') {
                            $types[] = new ConstantStringType('prompt');
                        } elseif ($path === 'store-auths') {
                            $types[] = new ConstantStringType('prompt');
                        } elseif ($path === 'platform-check') {
                            $types[] = new ConstantStringType('php-only');
                        } elseif ($path === 'github-protocols') {
                            $types[] = new UnionType([new ConstantStringType('git'), new ConstantStringType('https'), new ConstantStringType('ssh'), new ConstantStringType('http')]);
                        } elseif (str_starts_with($path, 'preferred-install')) {
                            $types[] = new UnionType([new ConstantStringType('source'), new ConstantStringType('dist'), new ConstantStringType('auto')]);
                        } else {
                            $types[] = new StringType();
                        }
                        break;

                    case 'boolean':
                        if ($path === 'platform.additionalProperties') {
                            $types[] = new ConstantBooleanType(false);
                        } else {
                            $types[] = new BooleanType();
                        }
                        break;

                    case 'object':
                        $addlPropType = null;
                        if (isset($def['additionalProperties'])) {
                            $addlPropType = $this->parseType($def['additionalProperties'], $path.'.additionalProperties');
                        }

                        if (isset($def['properties'])) {
                            $keyNames = [];
                            $valTypes = [];
                            $optionalKeys = [];
                            $propIndex = 0;
                            foreach ($def['properties'] as $propName => $propdef) {
                                $keyNames[] = new ConstantStringType($propName);
                                $valType = $this->parseType($propdef, $path.'.'.$propName);
                                if (!isset($def['required']) || !in_array($propName, $def['required'], true)) {
                                    $valType = TypeCombinator::addNull($valType);
                                    $optionalKeys[] = $propIndex;
                                }
                                $valTypes[] = $valType;
                                $propIndex++;
                            }

                            if ($addlPropType !== null) {
                                $types[] = new ArrayType(TypeCombinator::union(new StringType(), ...$keyNames), TypeCombinator::union($addlPropType, ...$valTypes));
                            } else {
                                $types[] = new ConstantArrayType($keyNames, $valTypes, [0], $optionalKeys);
                            }
                        } else {
                            $types[] = new ArrayType(new StringType(), $addlPropType ?? new MixedType());
                        }
                        break;

                    case 'array':
                        if (isset($def['items'])) {
                            $valType = $this->parseType($def['items'], $path.'.items');
                        } else {
                            $valType = new MixedType();
                        }

                        $types[] = new ArrayType(new IntegerType(), $valType);
                        break;

                    default:
                        $types[] = new MixedType();
                }
            }

            $type = TypeCombinator::union(...$types);
        } elseif (isset($def['enum'])) {
            $type = TypeCombinator::union(...array_map(static function (string $value): ConstantStringType {
                return new ConstantStringType($value);
            }, $def['enum']));
        } else {
            $type = new MixedType();
        }

        // allow-plugins defaults to null until July 1st 2022 for some BC hackery, but after that it is not nullable anymore
        if ($path === 'allow-plugins' && time() < strtotime('2022-07-01')) {
            $type = TypeCombinator::addNull($type);
        }

        // default null props
        if (in_array($path, ['autoloader-suffix', 'gitlab-protocol'], true)) {
            $type = TypeCombinator::addNull($type);
        }

        return $type;
    }
}
