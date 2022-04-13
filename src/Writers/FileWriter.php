<?php

namespace Onion\Framework\Proxy\Writers;

use Nette\PhpGenerator\PhpFile;
use Onion\Framework\Proxy\Interfaces\WriterInterface;

class FileWriter implements WriterInterface
{
    public function __construct(
        private ?string $location = null
    ) {
        $this->location ??= sys_get_temp_dir();
    }

    public function save(string $className, string $code): bool
    {
        $path = $this->location .
            DIRECTORY_SEPARATOR .
            preg_replace(
                '/\\\\/i',
                DIRECTORY_SEPARATOR,
                trim($className, '\\'),
            );

        $dir = dirname($path);
        $file = basename($path);

        if (!is_dir($dir)) {
            mkdir($dir, recursive: true);
        };
        $dir = realpath($dir);
        $path = "{$dir}/{$file}.php";

        return file_put_contents($path, $code) > 0;
    }
}
