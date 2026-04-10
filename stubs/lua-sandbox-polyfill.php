<?php

declare(strict_types=1);

namespace Lua;

if (! class_exists(Exception::class)) {
    class Exception extends \Exception {}
}

if (! class_exists(Sandbox::class)) {
    class Sandbox
    {
        public function __construct(
            int $memory_limit = 0,
            float $cpu_limit = 0.0,
        ) {}

        /**
         * @param  array<string, callable>  $callbacks
         */
        public function register(string $name, array $callbacks): void {}

        public function load(string $code): \Closure
        {
            return static fn (...$args) => null;
        }

        public function memoryUsage(): int
        {
            return 0;
        }
    }
}
