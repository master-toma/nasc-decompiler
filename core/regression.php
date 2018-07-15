<?php

class Regression
{
    private $file = null;
    private $first = true;

    public function __construct(string $file)
    {
        $this->file = fopen($file, 'a+');
    }

    public function generate(?string $code)
    {
        if ($this->first) {
            ftruncate($this->file, 0);
            $this->first = false;
        }

        fwrite($this->file, pack('L', crc32($code)));
    }

    public function test(?string $code): bool
    {
        if (!$this->file) {
            return false;
        }

        return unpack('L', fread($this->file, 4))[1] === crc32($code);
    }
}
