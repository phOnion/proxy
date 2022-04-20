<?php

declare(strict_types=1);

namespace Onion\Framework\Proxy;

use Closure;
use Nette\PhpGenerator\PhpFile;
use Onion\Framework\Proxy\Interfaces\ProxyFactoryInterface;
use Onion\Framework\Proxy\Interfaces\WriterInterface;
use ReflectionClass;
use ReflectionMethod;
use Onion\Framework\Proxy\Interfaces\ProxyInterface;

class ScopeLocalizerFactory implements ProxyFactoryInterface
{
    public function __construct(
        private readonly string $namespacePrefix = '__Proxy',
        private readonly ?WriterInterface $writer = null,
    ) {
    }

    public function generate(string $class, Closure $initializer): object
    {
        $sourceReflection = new ReflectionClass($class);
        $name = substr($sourceReflection->getName(), strlen($sourceReflection->getNamespaceName()) + 1);
        $namespace = trim(
            $this->namespacePrefix . '\\' .
                $sourceReflection->getNamespaceName(),
            '\\'
        );
        $className = "{$namespace}\\{$name}";

        if (!class_exists($className)) {
            $file = new PhpFile();
            $ns = $file->setStrictTypes(true)
                ->addNamespace($namespace);

            $target = $ns->addClass($name);

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

            $target->addImplement(ProxyInterface::class);

            foreach ($sourceReflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isConstructor()) {
                    continue;
                }

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
                    $method->getReturnType()?->getName() !== 'void'
                ));
            }

            eval((string) $ns);

            $this->writer?->save($className, (string) $file);
        }

        return new ($className)($initializer);
    }

    private function getMethodBody(string $expr, bool $shouldReturn, bool $onInstance = true): string
    {
        $return = $shouldReturn ? 'return ' : '';
        $body = $onInstance ? "\$this->__instance->{$expr}" : $expr;

        return <<<BODY
            if (!isset(\$this->__instance)) {
            \$this->__instance = (\$this->__initializer)();
        }

        {$return}{$body};
        BODY;
    }
}
