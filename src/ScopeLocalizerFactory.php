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

    public function generate(
        string $class,
        Closure $initializer,
        array $interceptors = [],
        array $methods = []
    ): object {
        $sourceReflection = new ReflectionClass($class);
        $name = trim(substr($sourceReflection->getName(), strlen($sourceReflection->getNamespaceName())), '\\');
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

            $constructor = $target->addMethod('__construct');
            $constructor->setBody('$this->__initializer = \Closure::bind($initializer, $this);')
                ->addParameter('initializer')
                ->setType(Closure::class);

            $constructor->addPromotedParameter('__interceptors')
                ->setVisibility('private')
                ->setReadOnly(true)
                ->setType('array');

            $constructor->addPromotedParameter('__proxyInstanceMagicMethods')
                ->setVisibility('private')
                ->setReadOnly(true)
                ->setType('array');

            $target->addProperty('__initializer')
                ->setVisibility('private')
                ->setReadOnly(true)
                ->setType(Closure::class);
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

            $interceptor = $target->addMethod('__callProxyMethodInterceptors')
                ->setVisibility('private');

            $interceptor->addParameter('method')
                ->setType('string');
            $interceptor->addParameter('arguments')
                ->setType('array')
                ->setDefaultValue([]);
            $interceptor->setBody(<<<BODY
                \$completed = false;
                \$invocation = new \Onion\Framework\Proxy\Invocation\Invocation(
                    [\$this->__instance, \$method],
                    \$arguments,
                    [...(\$this->__interceptors[\$method] ?? []), fn (\$i) => \$this->__instance->{\$method}(...\$i->getParameters())],
                    \$completed,
                );

                while (!\$invocation->isCompleted() && !\$invocation->isTerminated()) {
                    \$invocation->continue();
                }

                if (\$invocation->hasThrown()) {
                    throw \$invocation->getException();
                }


                return \$invocation->getReturnValue();
            BODY);

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
                    $method->getName(),
                    implode(', ', $params),
                    $method->getReturnType()?->getName() !== 'void'
                ));
            }

            foreach ($methods as $name => $method) {
                $target->addMethod($name)
                    ->setReturnType('mixed')
                    ->setBody("return \$this->__proxyInstanceMagicMethods['{$name}'](\$this);");
            }

            eval((string) $ns);

            $this->writer?->save($className, (string) $file);
        }

        return new ($className)($initializer, $interceptors, $methods);
    }

    private function getMethodBody(string $expr, string $paramsList, bool $shouldReturn, bool $onInstance = true): string
    {
        $return = $shouldReturn ? 'return $result === $this->__instance ? $this : $result;' : '';
        $body = $onInstance ? "\$this->__callProxyMethodInterceptors('{$expr}', [{$paramsList}])" : $expr;

        return <<<BODY
            if (!isset(\$this->__instance)) {
            \$this->__instance = (\$this->__initializer)();
        }
        \$result = {$body};
        {$return}
        BODY;
    }
}
