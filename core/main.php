<?php

// (▀̿Ĺ̯▀̿ ̿)
error_reporting(E_ALL);
ini_set('memory_limit', '1G');

$ignored = [
    'guild_master_test_helper1', // fatal
    'public_wyvern' // fatal
];

include_once 'tokenizer.php';
include_once 'ast.php';
include_once 'data.php';
include_once 'parser.php';
include_once 'regression.php';

include_once 'generators/interface.php';

$optionsConfig = [
    'test' => ["\t" . 'Run regression tests. Provide a test file name from the tests directory (without .bin extension).', null],
    'generate' => ['Generate regression tests. Provide a new test file name (without extension).', null],
    'input' => ["\t" . 'AI file to decompile.', 'ai.obj'],
    'chronicle' => ['AI chronicle. Provide a directory name from the data directory.', 'gf'],
    'language' => ['Resulting language. Provide a file name from the core/generators directory (without .php extension).', 'nasc'],
    'split' => ["\t" . 'Split result by classes.', true]
];

$longopts = [];
$defaults = [];

foreach ($optionsConfig as $option => $config) {
    $longopts[] = $option . '::';
    $defaults[$option] = $config[1];
}

$options = getopt('h', $longopts) + $defaults;

if (isset($options['h'])) {
    echo "\n";

    foreach ($optionsConfig as $option => $config) {
        echo "\t--" . $option . "\t" . $config[0] . " Default: " . var_export($config[1], true) . "\n";
    }

    die;
}

include_once 'generators/' . $options['language'] . '.php';

if (!isset($options['output'])) {
    $options['output'] = pathinfo($options['input'], PATHINFO_FILENAME);
}

$regression = null;
$failedTests = [];

if ($options['test']) {
    $regression = new Regression('tests/' . $options['test'] . '.bin');
} elseif ($options['generate']) {
    $regression = new Regression('tests/' . $options['generate'] . '.bin');
}

$data = new Data(
    'data/' . $options['chronicle'] . '/handlers.json',
    'data/' . $options['chronicle'] . '/variables.json',
    'data/' . $options['chronicle'] . '/functions.json',
    'data/' . $options['chronicle'] . '/enums.json',
    'data/' . $options['chronicle'] . '/fstring.txt'
);

$tokenizer = new Tokenizer();
$parser = new Parser($data);

$generatorClass = ucfirst($options['language']) . 'Generator';
$generator = new $generatorClass(); /** @var GeneratorInterface $generator */

$file = fopen($options['input'], 'r');
$line = 0;

if (!$options['split']) {
    $outputFile = $options['output'] . '.' . $options['language'];

    // workaround for NASC: write BOM
    if ($options['language'] === 'nasc') {
        file_put_contents($outputFile, pack('S', 0xFEFF));
    } else {
        file_put_contents($outputFile, '');
    }
} elseif (!is_dir($options['output'])) {
    mkdir($options['output']);
} else {
    echo 'Cleaning output directory: ' . $options['output'] . "\n\n";
    array_map('unlink', glob($options['output'] . '/*'));
}

while ($file && !feof($file)) {
    $string = trim(fgets($file));
    $string = preg_replace('/[^\s\x20-\x7E]/', '', $string); // remove non-ASCII characters
    $line++;

    if (!$string) {
        continue;
    }

    $token = $tokenizer->tokenize($string);
    $token->line = $line;

    if ($token->name === 'class') {
        $tokenizer->setHead($token);
    } elseif ($token->name === 'class_end') {
        $head = $tokenizer->getHead();
        $name = $head->data[1];

        if (in_array($name, $ignored)) {
            if ($options['test']) {
                $regression->test(null); // move cursor forward
            } elseif ($options['generate']) {
                $regression->generate(null); // write zero checksum for ignored class
            }

            continue;
        }

        echo 'Decompile ' . $name;
        $class = $parser->parseClass($head);
        $code = $generator->generateClass($class);

        if (!$options['split']) {
            $outputFile = $options['output'] . '.' . $options['language'];

            // workaround for NASC: convert to UTF-16LE BOM
            if ($options['language'] === 'nasc') {
                file_put_contents($outputFile, iconv('UTF-8', 'UTF-16LE', $code), FILE_APPEND);
            } else {
                file_put_contents($outputFile, $code, FILE_APPEND);
            }
        } else {
            $outputFile = $options['output'] . '/' . $name . '.' . $options['language'];
            file_put_contents($options['output'] . '/classes.txt', $name . "\n", FILE_APPEND);

            // workaround for NASC: convert to UTF-16LE BOM
            if ($options['language'] === 'nasc') {
                file_put_contents($outputFile, pack('S', 0xFEFF) . iconv('UTF-8', 'UTF-16LE', $code));
            } else {
                file_put_contents($outputFile, $code);
            }
        }

        if ($options['test']) {
            if ($regression->test($code)) {
                echo ' - PASSED (failed tests: ' . count($failedTests) . ')';
            } else {
                echo ' - FAILED';
                $failedTests[] = $name;
            }
        } elseif ($options['generate']) {
            $regression->generate($code);
        }

        echo "\n";
    }
}

if ($ignored) {
    echo "\nSkipped classes:\n\n";

    foreach ($ignored as $name) {
        echo $name . "\n";
    }
}

if ($failedTests) {
    echo "\nFailed tests:\n\n";

    foreach ($failedTests as $name) {
        echo $name . "\n";
    }
}

echo "\nDone!\n\n";

if ($options['split']) {
    echo 'Results in directory: ' . $options['output'] . "\n";
    echo 'Classes order file: ' . $options['output'] . "/classes.txt\n";
} else {
    echo 'Result in file: ' . $options['output'] . '.' . $options['language'] . "\n";
}
