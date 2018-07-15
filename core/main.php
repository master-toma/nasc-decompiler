<?php

// (▀̿Ĺ̯▀̿ ̿)
error_reporting(E_ALL);
ini_set('memory_limit', '1G');

require_once 'tokenizer.php';
require_once 'ast.php';
require_once 'data.php';
require_once 'parser.php';
require_once 'regression.php';

require_once 'generators/interface.php';

class Main
{
    public const BOM = "\xFF\xFE";

    private $chronicles = [
        'default' => 'gf',
        2 => 'gf',
        56 => 'freya',
        60 => 'h5',
        73 => 'gd'
    ];

    // try comment this for your ai.obj
    private $ignoredClasses = [
        'guild_master_test_helper1',
        'public_wyvern'
    ];

    private $optionsConfig = [
        // option => [description, default value]
        'input' => ["\t" . 'AI file to decompile.', 'ai.obj'],
        'chronicle' => ['AI chronicle. Provide a directory name from the data directory.', 'gf'],
        'language' => ['Resulting language. Provide a file name from the core/generators directory (without .php extension).', 'nasc'],
        'tree' => ["\t" . 'Split result in tree structure. Provide the tree depth (0 - don\'t split, 1 - flat, more than 3 can cause problems on Windows).', 3],
        'join' => ["\t" . 'Join split classes into one file. Provide a directory which contains the classes.txt file.', null],
        'utf16le' => ['Encode output in UTF-16LE instead of UTF-8. NASC Compiler supports only UTF-16LE.', false],
        'test' => ["\t" . 'Run regression tests. Provide a test file name from the tests directory (without .bin extension).', null],
        'generate' => ['Generate regression tests. Provide a new test file name (without extension).', null]
    ];

    /** @var Regression */
    private $regression = null;
    /** @var Tokenizer */
    private $tokenizer = null;
    /** @var Parser */
    private $parser = null;
    /** @var GeneratorInterface */
    private $generator = null;

    private $options = [];
    private $failedTests = [];
    private $tree = [];

    public function run()
    {
        $this->parseOptions();
        $this->initializeDependencies();

        if (isset($this->options['h'])) {
            $this->printHelp();
            return;
        }

        if ($this->options['join']) {
            $this->join();
            return;
        }

        echo "\nUse -h option for help.\n\n";
        $this->prepareOutput();
        $this->decompile();

        if ($this->ignoredClasses) {
            echo "\nSkipped classes:\n\n";

            foreach ($this->ignoredClasses as $name) {
                echo $name . "\n";
            }
        }

        if ($this->failedTests) {
            echo "\nFailed tests:\n\n";

            foreach ($this->failedTests as $name) {
                echo $name . "\n";
            }
        }

        echo "\nDone!\n\n";

        if ($this->options['tree']) {
            echo 'Results in directory: ' . $this->options['output'] . "\n";
            echo 'Classes order file: ' . $this->options['output'] . "/classes.txt\n";
        } else {
            echo 'Result in file: ' . $this->options['output'] . '.' . $this->options['language'] . "\n";
        }
    }

    private function parseOptions()
    {
        $options = [];
        $defaults = [];

        foreach ($this->optionsConfig as $option => $config) {
            $options[] = $option . '::';
            $defaults[$option] = $config[1];
        }

        $this->options = getopt('h', $options) + $defaults;

        if (!isset($this->options['output'])) {
            $this->options['output'] = pathinfo($this->options['input'], PATHINFO_FILENAME);
        }
    }

    private function initializeDependencies()
    {
        if ($this->options['test'] || $this->options['generate']) {
            $this->regression = new Regression('tests/' . ($this->options['test'] ?? $this->options['generate']) . '.bin');
        }

        $data = new Data(
            'data/' . $this->options['chronicle'] . '/handlers.json',
            'data/' . $this->options['chronicle'] . '/variables.json',
            'data/' . $this->options['chronicle'] . '/functions.json',
            'data/' . $this->options['chronicle'] . '/enums.json'
        );

        $this->tokenizer = new Tokenizer();
        $this->parser = new Parser($data);

        require_once 'generators/' . $this->options['language'] . '.php';
        $generatorClass = ucfirst($this->options['language']) . 'Generator';
        $this->generator = new $generatorClass();
    }

    private function printHelp()
    {
        echo "\n";

        foreach ($this->optionsConfig as $option => $config) {
            echo "\t--" . $option . "\t" . $config[0] . " Default: " . var_export($config[1], true) . "\n";
        }
    }

    private function prepareOutput()
    {
        if (!$this->options['tree']) {
            $outputFile = $this->options['output'] . '.' . $this->options['language'];
            file_put_contents($outputFile, $this->options['utf16le'] ? self::BOM : '');
        } elseif (!is_dir($this->options['output'])) {
            mkdir($this->options['output']);
        } else {
            echo 'Cleaning output directory: ' . $this->options['output'] . "\n\n";
            $this->removeTree($this->options['output']);
            mkdir($this->options['output']);
        }
    }

    private function decompile()
    {
        stream_filter_register('utf16le', utf16le_filter::class);
        $file = fopen($this->options['input'], 'r');
        stream_filter_append($file, 'utf16le');

        $line = 0;

        while ($file && !feof($file)) {
            $string = trim(fgets($file));
            $line++;

            if (!$string) {
                continue;
            }

            $token = $this->tokenizer->tokenize($string);
            $token->line = $line;

            if ($token->name === 'class') {
                $this->tokenizer->setHead($token);
            } elseif ($token->name === 'class_end') {
                $head = $this->tokenizer->getHead();
                $name = $head->data[1];

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

                if (!$this->options['tree']) {
                    $outputFile = $this->options['output'] . '.' . $this->options['language'];
                    file_put_contents($outputFile, ($this->options['utf16le'] ? iconv('UTF-8', 'UTF-16LE', $code) : $code) . "\n", FILE_APPEND);
                } else {
                    $path = $this->treePath($name, $class->getSuper(), $this->options['tree']);
                    $dir = $this->options['output'] . '/' . $path;
                    $outputFile = $dir . $name . '.' . $this->options['language'];

                    if (!file_exists($dir)) {
                        mkdir($dir, 0755, true);
                    }

                    file_put_contents($this->options['output'] . '/classes.txt', $path . $name . '.' . $this->options['language'] . "\n", FILE_APPEND);
                    file_put_contents($outputFile, $this->options['utf16le'] ? self::BOM . iconv('UTF-8', 'UTF-16LE', $code) : $code);
                }
            }
        }
    }

    private function parseHeader()
    {
//        $this->header = new HeaderDeclaration();
//
//        while ($this->file && !feof($this->file)) {
//            $string = trim(fgets($this->file));
//
//            if (!$string) {
//                continue;
//            }
//
//            $token = $this->tokenizer->tokenize($string);
//
//            switch ($token->name) {
//                case 'SizeofPointer':
//                    $this->header->sizeOfPointer = $token->data[0];
//                    break;
//                case 'SharedFactoryVersion':
//                    $this->header->sharedFactoryVersion = $token->data[0];
//                    break;
//                case 'NPCHVersion':
//                    $this->header->npcHVersion = $token->data[0];
//                    break;
//                case 'NASCVersion':
//                    $this->header->nascVersion = $token->data[0];
//                    break;
//                case 'NPCEventHVersion':
//                    $this->header->npcEventVersion = $token->data[0];
//                    break;
//                case 'Debug':
//                    $this->header->debug = $token->data[0];
//                    break;
//                default:
//                    break 2;
//            }
//        }
//
//        fseek($this->file, 0);
    }

    private function generateOrRunTests(string $class, ?string $code)
    {
        if ($code === null) {
            if ($this->options['test']) {
                $this->regression->test($code);
            } elseif ($this->options['generate']) {
                $this->regression->generate($code);
            }
        } else if ($this->options['test']) {
            if ($this->regression->test($code)) {
                echo ' - PASSED (failed tests: ' . count($this->failedTests) . ')';
            } else {
                echo ' - FAILED';
                $this->failedTests[] = $class;
            }
        } elseif ($this->options['generate']) {
            $this->regression->generate($code);
        }
    }

    private function join()
    {
        echo "\nJoin classes...\n\n";

        $classes = file($this->options['join'] . '/classes.txt');
        $outputFile = pathinfo($this->options['join'], PATHINFO_FILENAME) . '.' . pathinfo(trim($classes[0]), PATHINFO_EXTENSION);
        file_put_contents($outputFile, $this->options['utf16le'] ? self::BOM : '');

        foreach ($classes as $line) {
            $class = trim($line);

            if (!$class) {
                continue;
            }

            $code = file_get_contents($this->options['join'] . '/' . $class) . "\n";
            file_put_contents($outputFile, $this->options['utf16le'] ? iconv('UTF-8', 'UTF-16LE', $code) : $code, FILE_APPEND);
            echo '.';
        }

        echo "\n\nDone!\n\n";
        echo 'Result in file: ' . $outputFile . "\n";
    }

    private function isIgnoredClass(string $class): bool
    {
        return in_array($class, $this->ignoredClasses);
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

class utf16le_filter extends php_user_filter
{
    public function filter($in, $out, &$consumed, $closing)
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $data = substr($bucket->data, 0, 2) === Main::BOM ? substr($bucket->data, 2) : $bucket->data;
            $bucket->data = iconv('UTF-16LE', 'UTF-8', $data);
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}

$main = new Main();
$main->run();
