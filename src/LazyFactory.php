<?php

declare(strict_types=1);

namespace Onion\Framework\Proxy;

use Closure;
use Nette\PhpGenerator\PhpFile;
use Onion\Framework\Proxy\Interfaces\WriterInterface;
use Onion\Framework\Proxy\Writers\FileWriter;
use ReflectionClass;
use ReflectionMethod;

class LazyFactory
{
    public function __construct(
        private string $namespacePrefix = '__Proxy',
        private ?WriterInterface $writer = null,
    ) {
        $this->writer ??= new FileWriter();
    }

    public function generate(callable $initializer, string $class): object
    {
        $sourceReflection = new ReflectionClass($class);
        $namespace = trim(
            $this->namespacePrefix . '\\' .
                $sourceReflection->getNamespaceName(),
            '\\'
        );
        $className = $namespace . '\\' .
            $sourceReflection->getName();

        if (!class_exists($className)) {
            $file = new PhpFile();
            $target = $file->setStrictTypes(true)
                ->addNamespace($namespace)
                ->addClass($sourceReflection->getName());

            $target->addMethod('__construct')
                ->setBody('$this->__initializer = \Closure::bind($initializer, $this);')
                ->addParameter('initializer')
                ->setType(Closure::class);

            $target->addProperty('__initializer')
                ->setType(Closure::class)
                ->setReadOnly(true)
                ->setVisibility('private');
            $target->addProperty('__instance')
                ->setVisibility('private')
                ->setReadOnly(true)
                ->setType($class);

            if (interface_exists($class)) {
                $target->setImplements([$class]);
            } else if (class_exists($class)) {
                $target->setExtends($class);
            }

            foreach ($sourceReflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $m = $target->addMethod($method->getName())
                    ->setReturnNullable($method->getReturnType()?->allowsNull() ?? false)
                    ->setReturnType($method->getReturnType()?->getName());

                $params = [];
                foreach ($method->getParameters() as $param) {
                    $m->addParameter($param->getName(), $param->isOptional() ? $param->getDefaultValue() : null)
                        ->setNullable($param->getType()?->allowsNull() ?? false)
                        ->setType($param->getType()?->getName());
                    $params[] = "\${$param->name}";
                }

                $m->setBody($this->getMethodBody(
                    $method->getName() . '(' . implode(', ', $params) . ')',
                    $method->getReturnType()?->getName() !== 'void',
                    true,
                ));
            }

            $code = (string) $file;
            eval($code);

            $this->writer?->save($className, $code);
        }

        return new ($className)($initializer);
    }

    private function getMethodBody(string $expr, bool $shouldReturn, bool $isMethodCall): string
    {
        $return = $shouldReturn ? 'return ' : '';
        $body = $isMethodCall ? "\$this->__instance->{$expr}" : $expr;

        return <<<BODY
            if (!isset(\$this->__instance)) {
            \$this->__instance = (\$this->__initializer)();
        }

        {$return}{$body};
        BODY;
    }
}
