<?php

class Tokenizer
{
    /** @var Token */
    private $head = null;

    /** @var Token */
    private $tail = null;

    /**
     * @param string $string
     * @return Token Head of doubly linked list of tokens
     */
    public function tokenize(string $string): Token
    {
        $token = new Token();
        $token->prev = $this->tail;

        if ($this->tail) {
            $this->tail->next = $token;
        }

        if ($string[0] === 'S' && is_numeric($string[1])) {
            // string token
            [$label, $string] = explode("\t", $string, 2);
            $token->name = substr($label, 0, -1);
            $token->data = [$string];
        } else {
            $parts = explode('//', $string, 2);
            $string = trim($parts[0]);
            $comment = trim($parts[1] ?? '');
            $data = $this->split($string);
            $token->name = array_shift($data);
            $token->data = $data;
            $token->comment = $comment;
        }

        if (!$this->head) {
            $this->head = $token;
        }

        $token->raw = $string;
        $this->tail = $token;
        return $token;
    }

    public function setHead(?Token $token)
    {
        $token->prev = null;
        $this->head = $token;
    }

    public function getHead(): ?Token
    {
        return $this->head;
    }

    public function reset()
    {
        $this->head = null;
        $this->tail = null;
    }

    private function split(string $string, string $delimiter = ' ')
    {
        $length = strlen($string);
        $parts = [];
        $part = '';
        $state = 0; // 1 - in string

        for ($i = 0; $i < $length; $i++) {
            $char = $string[$i];

            if ($state === 0 && $char === '"') {
                $state = 1; // 1 - in string
                $part .= $char;
            } elseif ($state === 1 && $char === '"' && $string[$i - 1] ?? '' !== '\\') {
                $state = 0;
                $part .= $char;
                $parts[] = $part;
                $part = '';
            } elseif ($state === 0 && $char === $delimiter) {
                if ($part !== '') {
                    $parts[] = $part;
                    $part = '';
                }
            } else {
                $part .= $char;
            }
        }

        if ($part !== '') {
            $parts[] = $part;
        }

        return $parts;
    }
}

class Token
{
    public $name = '';
    public $data = [];
    public $comment = '';
    public $raw = '';
    public $line = 0;

    /** @var Token */
    public $prev = null;

    /** @var Token */
    public $next = null;

    public function isString(): bool
    {
        return ($this->name[0] ?? '') === 'S';
    }

    public function isLabel(): bool
    {
        return ($this->name[0] ?? '') === 'L';
    }
}
