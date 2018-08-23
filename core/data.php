<?php

class Data
{
    private $dir = '';

    private $handlers = [];
    private $variables = [];
    private $functions = [];
    private $pch = [];

    private $idToString = [];
    private $stringToId = [];

    private $variableTypeCache = [];

    public function __construct(string $dir, string $handlers, string $variables, string $functions, string $pch, string $fString)
    {
        $this->dir = $dir . '/';
        $this->handlers = fileJsonDecode($this->dir . $handlers);
        $this->variables = fileJsonDecode($this->dir . $variables);
        $this->functions = fileJsonDecode($this->dir . $functions);
        $this->loadPrecompiledHeaders($this->dir . $pch);
        $this->loadFString($this->dir . $fString);
    }

    public function getHandler(int $classType, int $id): string
    {
        if (!isset($this->handlers[$classType][$id])) {
            throw new RuntimeException(sprintf('Handler %d for class type %d not found', $id, $classType));
        }

        return $this->handlers[$classType][$id];
    }

    public function getVariable(int $classType, ?string $objectType, int $address): array
    {
        if (!isset($this->variables[$classType][$objectType ? $objectType : '_'][$address])) {
            throw new RuntimeException(sprintf('Variable %s for class type %d not found', ($objectType ? $objectType . '->' : '') . $address, $classType));
        }

        return $this->variables[$classType][$objectType ? $objectType : '_'][$address];
    }

    public function getVariableType(int $classType, string $name): ?string
    {
        if (isset($this->variableTypeCache[$classType]) && array_key_exists($name, $this->variableTypeCache[$classType])) {
            return $this->variableTypeCache[$classType][$name];
        }

        foreach ($this->variables[$classType] as $class) {
            foreach ($class as $variable) {
                if ($variable['name'] === $name) {
                    $this->variableTypeCache[$classType][$name] = $variable['type'];
                    return $variable['type'];
                }
            }
        }

//        throw new RuntimeException(sprintf('Variable %s for class type %d not found', $name, $classType));
        return null;
    }

    public function getFunction(int $address): array
    {
        if (!isset($this->functions[$address])) {
            throw new RuntimeException(sprintf('Function %d not found', $address));
        }

        return $this->functions[$address];
    }

    public function getPrecompiledHeader(string $name, int $id): ?string
    {
        return $this->pch[$name][$id] ?? null;
    }
	
	public function getPrecompiledHeaderStr(string $name, string $id): ?string
    {
        return $this->pch[$name][$id] ?? null;
    }

    public function getPrecompiledHeaders(): array
    {
        return $this->pch;
    }

    public function getStringById(int $id): ?string
    {
        return $this->idToString[$id] ?? null;
    }

    public function getIdByString(string $string): ?int
    {
        return $this->stringToId[$string] ?? null;
    }

    private function loadPrecompiledHeaders(string $pch)
    {
        $this->pch = fileJsonDecode($pch);
        $files = [];

        foreach ($this->pch as $name => $constants) {
            $file = null;
            $pattern = null;

            if (is_string($constants)) {
                $file = $constants;
            } elseif (is_array($constants) && !empty($constants['file'])) {
                $file = $constants['file'];
                $pattern = $constants['pattern'] ?? null;
            } else {
                continue;
            }

            $files[$file][$name] = $pattern;
        }

        foreach ($files as $file => $patterns) {
            $values = $this->loadPCH($this->dir . $file, $patterns);

            foreach ($values as $name => $constants) {
                $this->pch[$name] = $constants;

                // workaround for short skill ids
                if ($name === 'SKILL') {
                    foreach ($constants as $id => $skill) {
                        if ($id % 65536 === 1) {
                            $id = ($id - 1) / 65536;
                            $this->pch['SKILL_SHORT'][$id] = $skill;
                        }
                    }
                }
            }
        }

        // workaround to skip @ab_none precompiled header
        unset($this->pch['ABNORMAL'][-1]);
    }

    private function loadFString(string $fString)
    {
        if (!file_exists($fString)) {
            return;
        }

        $file = fopen($fString, 'r');

        if (fread($file, 2) === BOM) {
            stream_filter_append($file, 'utf16le');
        }

        while (!feof($file)) {
            $line = trim(fgets($file));

            if (!$line) {
                continue;
            }

            [$id, $string] = preg_split('/\s+/', $line, 2);
            $id = (int) $id;
            $string = substr($string, 1, -1);
            $this->idToString[$id] = $string;
            $this->stringToId[$string] = $id;
        }
    }

    private function loadPCH(string $pch, array $patterns): array
    {
        $file = fopen($pch, 'r');
        $result = array_combine(array_keys($patterns), array_fill(0, count($patterns), []));

        while ($file && !feof($file)) {
            $line = trim(fgets($file));
            $line = preg_replace('/[^\s\x20-\x7E]/', '', $line); // remove non-ASCII characters

            if (!$line || $line[0] !== '[') {
                continue;
            }

            $comment = strpos($line, '//');

            if ($comment !== false) {
                $line = trim(substr($line, 0, $comment));

                if (!$line) {
                    continue;
                }
            }

            if (strpos($line, '=') !== false) {
                [$constant, $id] = explode('=', $line);
                $constant = trim($constant);
                $id = trim($id);
            } else {
                [$constant, $id] = preg_split('/\s+/', $line);
            }

            $id = strpos($id, '0x') === 0 ? hexdec($id) : $id;
            $constant = trim($constant, '[]');

            foreach ($patterns as $name => $pattern) {
                if (!$pattern || preg_match($this->patternToRegex($pattern), $constant)) {
                    $result[$name][$id] = $constant;
                    break;
                }
            }
        }

        return $result;
    }

    private function patternToRegex(string $pattern): string
    {
        $parts = explode('|', $pattern);
        $result = [];

        foreach ($parts as $part) {
            $regex = str_replace('*', '(.*)', $part);

            if ($pattern[0] !== '*') {
                $regex = '^' . $regex;
            }

            if ($pattern[strlen($part) - 1] !== '*') {
                $regex .= '$';
            }

            $result[] = $regex;
        }

        return '/' . implode('|', $result) . '/';
    }
}
