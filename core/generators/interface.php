<?php

interface GeneratorInterface {
    public function generateClass(ClassDeclaration $class): string;
}
