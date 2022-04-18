<?php

declare(strict_types=1);

namespace Onion\Framework\Proxy;

use Nette\PhpGenerator\PhpFile;
use Onion\Framework\Proxy\Interfaces\WriterInterface;
use ReflectionClass;
use ReflectionMethod;

class ValueHolderFactory
{
    public function __construct(
        private readonly string $namespacesPrefix = '__Proxy',
        private readonly ?WriterInterface $writer = null,
    ) {
    }

    public function generate(callable $initializer, string $class): object
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
            $target->addMethod('__constructor')
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

            $target->setImplements($sourceReflection->getInterfaceNames());
            $target->setExtends($sourceReflection->getParentClass()?->getName());

            foreach ($sourceReflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isConstructor() || $method->getName() === '__get' || $method->getName() === '__set') {
                    continue;
                }

                $methodReturnType = $method->getReturnType()?->getName();

                $m = $target->addMethod($method->getName())
                    ->setReturnNullable($method->getReturnType()?->allowsNull() ?? false)
                    ->setReturnType(match ($methodReturnType) {
                        'static' => $class,
                        'self' => $class,
                        default => $methodReturnType,
                    });

                $params = [];
                foreach ($method->getParameters() as $param) {
                    $m->addParameter($param->getName(), $param->isOptional() ? $param->getDefaultValue() : null)
                        ->setNullable($param->getType()?->allowsNull() ?? false)
                        ->setType($param->getType()?->getName());
                    $params[] = "\${$param->name}";
                }

                $m->setBody($this->getMethodBody(
                    $method->getName() . '(' . implode(', ', $params) . ')',
                    $methodReturnType !== 'void',
                    true,
                ));
            }

            $getter = $target->hasMethod('__get') ? $target->getMethod('__get') : $target->addMethod('__get');
            $getter->setParameters([])->setBody(
                $this->getMethodBody('{$name}', true)
            )->setReturnType('mixed');
            $getter->addParameter('name')->setType('string');


            $setter = $target->hasMethod('__set') ? $target->getMethod('__set') : $target->addMethod('__set');
            $setter->setParameters([])->setBody(
                $this->getMethodBody('{$name} = $value', false)
            )->setReturnType('void');

            $setter->addParameter('name')->setType('string');
            $setter->addParameter('value')->setType('mixed');

            $isset = $target->hasMethod('__isset') ? $target->getMethod('__isset') : $target->addMethod('__isset');
            $isset->setParameters([])->setBody(
                $this->getMethodBody('isset($this->__instance->{$name})', true)
            )->setReturnType('bool');
            $isset->addParameter('name')
                ->setType('string');

            $unset = $target->hasMethod('__unset') ? $target->getMethod('__unset') : $target->addMethod('__unset');
            $unset->setParameters([])->setBody(
                $this->getMethodBody('unset($this->__instance->{$name})', true)
            )->setReturnType('void');
            $unset->addParameter('name')
                ->setType('string');

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