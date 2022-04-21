<?php

declare(strict_types=1);

namespace Onion\Framework\Proxy\Invocation;

use Onion\Framework\Aspects\Interfaces\InvocationInterface;

class Invocation implements InvocationInterface
{
    /** @var array<int,callable> */
    private array $callbacks = [];

    private object $instance;
    private string $method;
    private array $arguments;
    private bool $earlyReturn;
    private mixed $returnValue = null;

    private \Throwable $exception;

    public function __construct(array $target, array $params, array $callbacks, bool &$earlyReturn)
    {
        list($this->instance, $this->method) = $target;
        $this->arguments = $params;

        $this->callbacks = $callbacks;
        $this->earlyReturn = &$earlyReturn;
    }

    public function getMethodName(): string
    {
        return $this->method;
    }

    public function getTarget(): array
    {
        return [$this->instance, $this->getMethodName()];
    }

    public function getParameters(): array
    {
        return $this->arguments;
    }

    public function getReturnValue()
    {
        return $this->returnValue;
    }

    public function exit(): void
    {
        $this->earlyReturn = true;
    }

    public function continue(): mixed
    {
        try {
            if (!$this->earlyReturn && !empty($this->callbacks)) {
                return ($this->returnValue = (array_shift($this->callbacks))($this));
            }

            return $this->returnValue;
        } catch (\Throwable $ex) {
            $this->exception = $ex;
        }
    }

    public function isCompleted(): bool
    {
        return $this->callbacks === [];
    }

    public function isTerminated(): bool
    {
        return $this->earlyReturn || $this->hasThrown();
    }

    public function hasThrown(): bool
    {
        return isset($this->exception);
    }

    public function getException(): ?\Throwable
    {
        return $this->exception;
    }

    public function getArguments(): ?array
    {
        return $this->arguments;
    }
}
