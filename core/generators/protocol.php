<?php

interface GeneratorProtocol {
    public function generateClass(ClassDeclaration $class): string;
}
