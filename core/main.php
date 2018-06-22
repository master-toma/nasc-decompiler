<?php

// (▀̿Ĺ̯▀̿ ̿)
error_reporting(E_ALL);
ini_set('memory_limit', '1G');

// TODO: fix problems with these classes
$ignore = [
    // 'e11_drake_avlariel', // TODO: else if bug
    'merchant_for_pvp',
    'guild_master_test_helper1',
    'announce_raid_boss_position',
    'c_tower_maker_special3',
    'public_wyvern',
];

include_once 'tokenizer.php';
include_once 'ast.php';
include_once 'data.php';
include_once 'parser.php';
include_once 'codegen.php';

include_once 'regression.php';

$isTest = ($argv[1] ?? '') === 'test';
$isGen = ($argv[1] ?? '') === 'gen';
$regression = $isTest || $isGen ? new Regression('tests/' . $argv[2] . '.bin') : null;
$failed = [];

$data = new Data(
    'data/handlers.json',
    'data/variables.json',
    'data/functions.json',
    'data/enums.json'
);

$tokenizer = new Tokenizer();
$parser = new Parser($data);
$codegen = new Codegen();

$file = fopen('ai_adv.obj', 'r');
$line = 0;

// write BOM
file_put_contents('ai.nasc', pack('S',0xFEFF));

while (!feof($file)) {
    $string = trim(fgets($file));
    $string = preg_replace('/[^\s\x20-\x7E]/','', $string); // remove non-ASCII characters
    $line++;

    if (!$string) {
        continue;
    }

    $token = $tokenizer->tokenize($string);
    $token->line = $line;

    if ($token->name === 'class') {
        $tokenizer->setHead($token);
    } else if ($token->name === 'class_end') {
        $name = $tokenizer->getHead()->data[1];

        if (in_array($name, $ignore)) {
            if ($isTest) {
                $regression->test(null);
            } else if ($isGen) {
                $regression->generate(null);
            }

            continue;
        }

        echo 'Decompile ' . $name;
        $class = $parser->parseClass($tokenizer->getHead());
        $code = $codegen->generateClass($class);
        file_put_contents('ai.nasc', iconv('UTF-8', 'UTF-16LE', $code), FILE_APPEND);

        if ($isTest) {
            if ($regression->test($code)) {
                echo ' - PASSED';
            } else {
                echo ' - FAILED';
                $failed[] = $name;
            }
        } else if ($isGen) {
            $regression->generate($code);
        }

        echo "\n";
    }
}

if ($failed) {
    echo "\nFailed tests:\n\n";

    foreach ($failed as $name) {
        echo $name . "\n";
    }
}

echo "\nDone!\n";
