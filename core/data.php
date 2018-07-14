<?php

class Data
{
    private $handlers = [];
    private $variables = [];
    private $functions = [];
    private $enums = [];
    private $strings = [];

    public function __construct(string $handlers, string $variables, string $functions, string $enums, string $strings)
    {
        $this->handlers = $this->jsonDecode(file_get_contents($handlers));
        $this->variables = $this->jsonDecode(file_get_contents($variables));
        $this->functions = $this->jsonDecode(file_get_contents($functions));
        $this->enums = $this->loadEnums($enums);
        $this->strings = $this->loadStrings($strings);
    }

    public function getHandler(int $type, int $id): string
    {
        if (!isset($this->handlers[$type][$id])) {
            throw new RuntimeException(sprintf('Handler %d for class type %d not found', $id, $type));
        }

        return $this->handlers[$type][$id];
    }

    public function getVariable(int $type, ?string $class, int $address): array
    {
        if (!isset($this->variables[$type][$class ? $class : '_'][$address])) {
            throw new RuntimeException(sprintf('Variable %s for class type %d not found', ($class ? $class . '->' : '') . $address, $type));
        }

        return $this->variables[$type][$class ? $class : '_'][$address];
    }

    public function getFunction(int $address): array
    {
        if (!isset($this->functions[$address])) {
            throw new RuntimeException(sprintf('Function %d not found', $address));
        }

        return $this->functions[$address];
    }

    public function getString(int $id): string
    {
        if (!isset($this->strings[$id])) {
            trigger_error(sprintf('String %d not found', $id), E_USER_WARNING);
            return (string) $id;
        }

        return $this->strings[$id];
    }

    public function getEnum(string $name, int $id): ?string
    {
        return $this->enums[$name][$id] ?? null;
    }

    public function getEnums(): array
    {
        return $this->enums;
    }

    private function jsonDecode(string $json): array
    {
        // strip comments
        $json = preg_replace('#([\s]+//.*)|(^//.*)#', '', $json);
        return json_decode($json, true);
    }

    private function loadEnums(string $enums): array
    {
        $enums = $this->jsonDecode(file_get_contents($enums));

        foreach ($enums as $name => $constants) {
            if (!is_string($constants)) {
                continue;
            }

            // workaround for short skill ids
            if ($name === 'SKILL') {
                $pch = $this->loadPch($constants);
                $enums[$name] = $pch;

                foreach ($pch as $id => $skill) {
                    if ($id % 65536 === 1) {
                        $id = ($id - 1) / 65536;
                        $enums['SKILL_SHORT'][$id] = $skill;
                    }
                }
            } else {
                $enums[$name] = $this->loadPch($constants);
            }
        }

        return $enums;
    }

    private function loadPch(string $path): array
    {
        $file = fopen($path, 'r');
        $result = [];

        while ($file && !feof($file)) {
            $string = trim(fgets($file));
            $string = preg_replace('/[^\s\x20-\x7E]/', '', $string); // remove non-ASCII characters

            if (!$string) {
                continue;
            }

            if (($comment = strpos($string, '//')) !== false) {
                $string = trim(substr($string, 0, $comment));

                if (!$string) {
                    continue;
                }
            }

            if (strpos($string, '=') !== false) {
                [$name, $id] = explode('=', $string);
                $name = trim($name);
                $id = trim($id);
            } else {
                [$name, $id] = preg_split('/\s+/', $string);
            }

            $name = trim($name, '[]');
            $result[$id] = '@' . $name;
        }

        return $result;
    }

    private function loadStrings(string $path): array
    {
        // TODO implement
        return [];
    }
}
