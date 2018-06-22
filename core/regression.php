<?php

class Regression {
    private $file;

    public function __construct(string $file) {
        $this->file = fopen($file, 'a+');
    }

    public function generate(?string $class) {
        static $first = true;

        if ($first) {
            ftruncate($this->file, 0);
            $first = false;
        }

        fwrite($this->file, pack('L', crc32($class)));
    }

    public function test(?string $class): bool {
        return unpack('L', fread($this->file, 4))[1] === crc32($class);
    }
}
