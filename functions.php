<?php

namespace Onion\Framework\Proxy;

use Closure;

if (!function_exists(__NAMESPACE__ . '\proxy')) {
    function proxy(string $className, Closure $initializer, array $interceptors = [], array $methods = []): object
    {
        static $generator;
        if (!isset($generator)) {
            $generator = new ScopeLocalizerFactory();
        }

        return $generator->generate(
            $className,
            $initializer,
            $interceptors,
            $methods,
        );
    }
}
