<?php

declare(strict_types=1);

namespace Onion\Framework\Proxy;

use Laminas\Code\Generator\ClassGenerator;
use Laminas\Code\Generator\MethodGenerator;
use Laminas\Code\Generator\PropertyGenerator;
use Laminas\Code\Generator\TypeGenerator;
use Onion\Framework\Proxy\Interfaces\WriterInterface;
use Onion\Framework\Proxy\Writers\FileWriter;
use ReflectionClass;
use ReflectionMethod;

class LazyFactory
{
    public function __construct(
        private ?WriterInterface $writer,
        private string $namespacePrefix = '__Proxy',
    ) {
        $this->writer ??= new FileWriter();
    }

    public function generate(callable $initializer, string $class): object
    {
        $className = $this->namespacePrefix . '\\' . $class;

        if (!class_exists($className)) {
            $sourceReflection = new ReflectionClass($class);

            $constructor = new MethodGenerator('__construct');
            $constructor->setParameter('initializer', 'callable');
            $constructor->setVisibility(MethodGenerator::VISIBILITY_PUBLIC);
            $constructor->setBody('$this->initializer = $initializer;');

            $target = new ClassGenerator($className);
            $target->setNamespaceName($this->namespacePrefix . '\\' . $sourceReflection->getNamespaceName());
            $target->addPropertyFromGenerator(new PropertyGenerator('initializer', flags: PropertyGenerator::FLAG_PRIVATE));
            $target->addPropertyFromGenerator(new PropertyGenerator('instance', flags: PropertyGenerator::FLAG_PRIVATE));
            $target->addMethodFromGenerator($constructor);
            interface_exists($class) ? $target->setImplementedInterfaces([$class]) : $target->setExtendedClass($class);

            foreach ($sourceReflection->getMethods(ReflectionMethod::IS_PUBLIC) as $methodReflection) {
                $method = MethodGenerator::fromReflection($methodReflection);
                $method->setBody('
            if ($this->instance === null) {
                $this->instance = ($this->initializer)();
            }

            ' . ($method->getReturnType()?->equals(TypeGenerator::fromTypeString('void')) ? '' : 'return ') . '$this->instance->{__FUNCTION__}(...func_get_args());
            ');

                $target->addMethodFromGenerator($method);
            }

            $this->writer->save($target);
        }

        return new ($className)($initializer);
    }
}
