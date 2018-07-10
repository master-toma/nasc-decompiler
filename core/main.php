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

include_once 'generators/interface.php';
include_once 'generators/nasc.php';

include_once 'regression.php';

$isTest = ($argv[1] ?? '') === 'test';
$isGenerate = ($argv[1] ?? '') === 'generate';
$regression = $isTest || $isGenerate ? new Regression('tests/' . $argv[2] . '.bin') : null;
$failedTests = [];

$data = new Data(
    'data/gf/handlers.json',
    'data/gf/variables.json',
    'data/gf/functions.json',
    'data/gf/enums.json',
    'data/gf/fstring.txt'
);

$tokenizer = new Tokenizer();
$parser = new Parser($data);
$generator = new NASCGenerator();

$file = fopen('ai.obj', 'r');
$line = 0;

// write BOM
file_put_contents('ai.nasc', pack('S', 0xFEFF));

while (!feof($file)) {
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
        $name = $tokenizer->getHead()->data[1];

        if (in_array($name, $ignored)) {
            if ($isTest) {
                $regression->test(null); // move cursor forward
            } elseif ($isGenerate) {
                $regression->generate(null); // write zero checksum for ignored class
            }

            continue;
        }

        echo 'Decompile ' . $name;
        $class = $parser->parseClass($tokenizer->getHead());
        $code = $generator->generateClass($class);
        file_put_contents('ai.nasc', iconv('UTF-8', 'UTF-16LE', $code), FILE_APPEND);

        if ($isTest) {
            if ($regression->test($code)) {
                echo ' - PASSED';
            } else {
                echo ' - FAILED';
                $failedTests[] = $name;
            }
        } elseif ($isGenerate) {
            $regression->generate($code);
        }

        echo "\n";
    }
}

if ($failedTests) {
    echo "\nFailed tests:\n\n";

    foreach ($failedTests as $name) {
        echo $name . "\n";
    }
}

echo "\nDone!\n";
