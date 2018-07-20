<?php

class Data
{
    private $dir = '';

    private $handlers = [];
    private $variables = [];
    private $functions = [];
    private $pch = [];

    private $variableTypeCache = [];

    public function __construct(string $dir, string $handlers, string $variables, string $functions, string $pch)
    {
        $this->dir = $dir . '/';
        $this->handlers = $this->jsonDecode(file_get_contents($this->dir . $handlers));
        $this->variables = $this->jsonDecode(file_get_contents($this->dir . $variables));
        $this->functions = $this->jsonDecode(file_get_contents($this->dir . $functions));
        $this->pch = $this->loadPrecompiledHeaders($this->dir . $pch);
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

    public function getPrecompiledHeaders(): array
    {
        return $this->pch;
    }

    private function jsonDecode(string $json): array
    {
        // strip comments
        $json = preg_replace('#([\s]+//.*)|(^//.*)#', '', $json);
        return json_decode($json, true);
    }

    private function loadPrecompiledHeaders(string $pch): array
    {
        $pch = $this->jsonDecode(file_get_contents($pch));
        $files = [];

        foreach ($pch as $name => $constants) {
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
            $values = $this->loadPch($this->dir . $file, $patterns);

            foreach ($values as $name => $constants) {
                $pch[$name] = $constants;

                // workaround for short skill ids
                if ($name === 'SKILL') {
                    foreach ($constants as $id => $skill) {
                        if ($id % 65536 === 1) {
                            $id = ($id - 1) / 65536;
                            $pch['SKILL_SHORT'][$id] = $skill;
                        }
                    }
                }
            }
        }

        // workaround to skip @ab_none precompiled header
        unset($pch['ABNORMAL'][-1]);

        return $pch;
    }

    private function loadPch(string $path, array $patterns): array
    {
        $file = fopen($path, 'r');
        $result = array_combine(array_keys($patterns), array_fill(0, count($patterns), []));

        while ($file && !feof($file)) {
            $string = trim(fgets($file));
            $string = preg_replace('/[^\s\x20-\x7E]/', '', $string); // remove non-ASCII characters

            if (!$string || $string[0] !== '[') {
                continue;
            }

            if (($comment = strpos($string, '//')) !== false) {
                $string = trim(substr($string, 0, $comment));

                if (!$string) {
                    continue;
                }
            }

            if (strpos($string, '=') !== false) {
                [$constant, $id] = explode('=', $string);
                $constant = trim($constant);
                $id = trim($id);
            } else {
                [$constant, $id] = preg_split('/\s+/', $string);
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
        $regex = str_replace('*', '(.*)', $pattern);

        if ($pattern[0] !== '*') {
            $regex = '^' . $regex;
        }

        if ($pattern[strlen($pattern) - 1] !== '*') {
            $regex .= '$';
        }

        return '/' . $regex . '/';
    }
}
