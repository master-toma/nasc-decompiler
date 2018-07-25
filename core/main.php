<?php

// (▀̿Ĺ̯▀̿ ̿)
error_reporting(E_ALL);
ini_set('memory_limit', '1G');

require_once 'common.php';
require_once 'tokenizer.php';
require_once 'ast.php';
require_once 'data.php';
require_once 'parser.php';
require_once 'regression.php';

require_once 'generators/interface.php';

class Main
{
    private $chronicles = [
        // nasc version => chronicles
        2   => 'gf',
        56  => 'freya',
        60  => 'h5',
        73  => 'gd',
        133 => 'c1'
    ];

    private $options = [
        // option       => [description, default value]
        'config'        => ['Path to JSON configuration file.', null],
        'input'         => ["\t" . 'Path to AI file to decompile.', 'ai.obj'],
        'output'        => ['Path to result file/directory.', null],
        'chronicles'    => ['AI chronicles. Provide a directory name from the data directory.', null],
        'language'      => ['Result language. Provide a file name from the core/generators directory (without .php extension).', 'nasc'],
        'tree'          => ["\t" . 'Split result in tree structure. Provide a tree depth (0 - don\'t split, 1 - flat, more than 3 can cause problems on Windows).', 3],
        'join'          => ["\t" . 'Join split classes into one file. Provide path to a directory which contains the classes.txt file.', null],
        'utf16le'       => ['Encode output in UTF-16LE instead of UTF-8. NASC Compiler supports only UTF-16LE.', false],
        'test'          => ["\t" . 'Run regression tests. Provide a test file name from the tests directory (without .bin extension).', null],
        'generate'      => ['Generate regression tests. Provide a new test file name (without extension).', null],
        'ignore'        => ['Comma-separated list of ignored classes.', null]
    ];

    /** @var Regression */
    private $regression = null;
    /** @var Tokenizer */
    private $tokenizer = null;
    /** @var Parser */
    private $parser = null;
    /** @var GeneratorInterface */
    private $generator = null;
    /** @var HeaderDeclaration */
    private $header = null;

    private $file = null;
    private $config = [];
    private $failedTests = [];
    private $tree = [];

    public function run()
    {
        $this->parseConfig();

        if (isset($this->config['h'])) {
            $this->printHelp();
            return;
        }

        if ($this->config['join']) {
            $this->join();
            return;
        }

        echo "\nUse -h option for help.\n\n";
        $this->initializeDependencies();
        $this->prepareOutput();
        $this->decompile();

        if ($this->config['ignore']) {
            echo "\nSkipped classes:\n\n";

            foreach ($this->config['ignore'] as $name) {
                echo $name . "\n";
            }
        }

        if ($this->failedTests) {
            echo "\nFailed tests (" . count($this->failedTests) . "):\n\n";

            foreach ($this->failedTests as $name) {
                echo $name . "\n";
            }
        } elseif ($this->config['test']) {
            echo "\nAll tests passed!\n";
        }

        echo "\nDone!\n\n";

        if ($this->config['tree']) {
            echo 'Results in directory: ' . $this->config['output'] . "\n";
            echo 'Classes order file: ' . $this->config['output'] . "/classes.txt\n";
        } else {
            echo 'Result in file: ' . $this->config['output'] . "\n";
        }
    }

    private function parseConfig()
    {
        // parse options
        $options = [];
        $defaults = [];

        foreach ($this->options as $option => $config) {
            $options[] = $option . '::';
            $defaults[$option] = $config[1];
        }

        $this->config = getopt('h', $options);

        // read config file
        if (!empty($this->config['config']) || !empty($defaults['config'])) {
            $this->config += fileJsonDecode($this->config['config'] ?? $defaults['config']);
        }

        $this->config += $defaults;

        // prepare --output option
        if (empty($this->config['output']) && !$this->config['join']) {
            $this->config['output'] = pathinfo($this->config['input'], PATHINFO_FILENAME);

            if (!$this->config['tree']) {
                $this->config['output'] .= '.' . $this->config['language'];
            }
        }

        // prepare --ignore option
        if (!is_array($this->config['ignore'])) {
            $this->config['ignore'] = array_filter(explode(',', $this->config['ignore']));
        }
    }

    private function initializeDependencies()
    {
        // open file
        stream_filter_register('utf16le', UTF16LEFilter::class);
        $this->file = fopen($this->config['input'], 'r');

        if (fread($this->file, 2) === BOM) {
            stream_filter_append($this->file, 'utf16le');
        }

        // init tests
        if ($this->config['test'] || $this->config['generate']) {
            $this->regression = new Regression('tests/' . ($this->config['test'] ?? $this->config['generate']) . '.bin');
        }

        // init generator
        require_once 'generators/' . $this->config['language'] . '.php';
        $generatorClass = ucfirst($this->config['language']) . 'Generator';
        $this->generator = new $generatorClass();

        // parse header & choose correct data
        $this->tokenizer = new Tokenizer();
        $this->parseHeader();

        $chronicles = $this->config['chronicles'] ?? $this->chronicles[$this->header->nascVersion];
        echo 'Chronicles: ' . $chronicles . "\n";

        $data = new Data(
            'data/' . $chronicles,
            'handlers.json',
            'variables.json',
            'functions.json',
            'pch.json',
            'fstring.txt'
        );

        $this->parser = new Parser($data);
    }

    private function printHelp()
    {
        echo "\n";

        foreach ($this->options as $option => $config) {
            echo "\t--" . $option . "\t" . $config[0] . " Default: " . var_export($config[1], true) . "\n";
        }
    }

    private function prepareOutput()
    {
        if (!$this->config['tree']) {
            file_put_contents($this->config['output'], $this->config['utf16le'] ? BOM : '');
        } elseif (!is_dir($this->config['output'])) {
            mkdir($this->config['output']);
        } else {
            echo 'Cleaning output directory: ' . $this->config['output'] . "\n\n";
            $this->removeTree($this->config['output']);
            mkdir($this->config['output']);
        }
    }

    private function parseHeader()
    {
        $this->header = new HeaderDeclaration();

        while ($this->file && !feof($this->file)) {
            $line = trim(fgets($this->file));

            if (!$line) {
                continue;
            }

            $token = $this->tokenizer->tokenize($line);

            switch ($token->name) {
                case 'SizeofPointer':
                    $this->header->sizeOfPointer = $token->data[0];
                    break;
                case 'SharedFactoryVersion':
                    $this->header->sharedFactoryVersion = $token->data[0];
                    break;
                case 'NPCHVersion':
                    $this->header->npcHVersion = $token->data[0];
                    break;
                case 'NASCVersion':
                    $this->header->nascVersion = $token->data[0];
                    break;
                case 'NPCEventHVersion':
                    $this->header->npcEventVersion = $token->data[0];
                    break;
                case 'Debug':
                    $this->header->debug = $token->data[0];
                    break;
                default:
                    break 2;
            }
        }

        fseek($this->file, 0);
    }

    private function decompile()
    {
        $lineNo = 0;

        while ($this->file && !feof($this->file)) {
            $line = trim(fgets($this->file));
            $lineNo++;

            if (!$line) {
                continue;
            }

            $token = $this->tokenizer->tokenize($line);
            $token->line = $lineNo;

            if ($token->name === 'class') {
                $this->tokenizer->setHead($token);
            } elseif ($token->name === 'class_end') {
                $head = $this->tokenizer->getHead();

                // c1 support workaround
                $name = count($head->data) === 3 ? $head->data[0] : $head->data[1];

                if ($this->isIgnoredClass($name)) {
                    // write zero checksum & move cursor forward
                    $this->generateOrRunTests($name, null);
                    continue;
                }

                echo 'Decompile ' . $name;
                $class = $this->parser->parseClass($head);
                $code = $this->generator->generateClass($class);
                $this->generateOrRunTests($name, $code);
                echo "\n";

                if (!$this->config['tree']) {
                    $code = $this->config['utf16le'] ? iconv('UTF-8', 'UTF-16LE', $code . "\n") : $code . "\n";
                    file_put_contents($this->config['output'], $code, FILE_APPEND);
                } else {
                    $path = $this->treePath($name, $class->getSuper(), $this->config['tree']);
                    $dir = $this->config['output'] . '/' . $path;
                    $outputFile = $dir . $name . '.' . $this->config['language'];

                    if (!file_exists($dir)) {
                        mkdir($dir, 0755, true);
                    }

                    file_put_contents($this->config['output'] . '/classes.txt', $path . $name . '.' . $this->config['language'] . "\n", FILE_APPEND);
                    $code = $this->config['utf16le'] ? BOM . iconv('UTF-8', 'UTF-16LE', $code) : $code;
                    file_put_contents($outputFile, $code);
                }
            }
        }
    }

    private function generateOrRunTests(string $class, ?string $code)
    {
        if ($code === null) {
            if ($this->config['test']) {
                $this->regression->test(null);
            } elseif ($this->config['generate']) {
                $this->regression->generate(null);
            }
        } elseif ($this->config['test']) {
            if ($this->regression->test($code)) {
                echo ' - PASSED (failed tests: ' . count($this->failedTests) . ')';
            } else {
                echo ' - FAILED';
                $this->failedTests[] = $class;
            }
        } elseif ($this->config['generate']) {
            $this->regression->generate($code);
        }
    }

    private function join()
    {
        echo "\nJoin classes...\n\n";

        $classes = file($this->config['join'] . '/classes.txt');
        $outputFile = pathinfo($this->config['join'], PATHINFO_FILENAME) . '.' . pathinfo(trim($classes[0]), PATHINFO_EXTENSION);
        $outputFile = $htis->config['output'] ?? $outputFile;
        file_put_contents($outputFile, $this->config['utf16le'] ? BOM : '');

        foreach ($classes as $line) {
            $class = trim($line);

            if (!$class) {
                continue;
            }

            $code = file_get_contents($this->config['join'] . '/' . $class) . "\n";
            $code = $this->config['utf16le'] ? iconv('UTF-8', 'UTF-16LE', $code) : $code;
            file_put_contents($outputFile, $code, FILE_APPEND);
            echo '.';
        }

        echo "\n\nDone!\n\n";
        echo 'Result in file: ' . $outputFile . "\n";
    }

    private function isIgnoredClass(string $class): bool
    {
        return in_array($class, $this->config['ignore']);
    }

    private function removeTree(string $dir) {
        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;

            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    private function treePath(string $class, ?string $super, int $depth): string
    {
        if ($super) {
            $this->tree[$class] = $super;
        }

        $path = '';
        $current = $class;

        while (isset($this->tree[$current])) {
            $current = $this->tree[$current];
            $path = $current . '/' . $path;
        }

        $parts = explode('/', $path);

        if (count($parts) >= $depth) {
            $path = implode('/', array_slice($parts, 0, $depth - 1));

            if ($path) {
                $path .= '/';
            }
        }

        return $path;
    }
}

$main = new Main();
$main->run();
