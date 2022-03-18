<?php

namespace Composer\PHPStan;

use Composer\Config;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

final class ConfigReturnTypeExtension implements DynamicMethodReturnTypeExtension {

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
        if ($keyType instanceof ConstantStringType) {
            if ($keyType->getValue() == 'allow-plugins') {
                return TypeCombinator::addNull(
                    new ArrayType(new StringType(), new BooleanType())
                );
            }
        }

        return ParametersAcceptorSelector::selectSingle($methodReflection->getVariants())->getReturnType();
    }
}
