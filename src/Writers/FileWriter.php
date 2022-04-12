<?php

namespace Onion\Framework\Proxy\Writers;

use Laminas\Code\Generator\ClassGenerator;
use Laminas\Code\Generator\GeneratorInterface;
use Onion\Framework\Proxy\Interfaces\WriterInterface;

class FileWriter implements WriterInterface
{
    public function __construct(
        private ?string $location = null
    ) {
        $this->location ??= sys_get_temp_dir();
    }

    public function save(GeneratorInterface $reflection): bool
    {
        $path = $this->location;
        if ($reflection instanceof ClassGenerator) {
            $path = $path . DIRECTORY_SEPARATOR . preg_replace(
                '/\\\\/i',
                DIRECTORY_SEPARATOR,
                $reflection->getNamespaceName()
            );
        }

        if (!file_exists($path)) {
            mkdir($path, recursive: true);
        }
        $name = $reflection instanceof ClassGenerator ? $reflection->getName() : tempnam($path, '');

        $location = realpath($path) . DIRECTORY_SEPARATOR . $name . '.php';


        $code = $reflection->generate();

        eval($code);

        return file_put_contents(
            $location,
            "<?php\ndeclare(strict_types=1);\n{$code}"
        ) > 0;
    }
}
