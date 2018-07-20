<?php

class NascGenerator implements GeneratorInterface
{
    private const LEFT_ASSOCIATIVE = 0;
    private const RIGHT_ASSOCIATIVE = 1;

    private $binaryOperators = [
        '*' => [9, self::LEFT_ASSOCIATIVE],
        '/' => [9, self::LEFT_ASSOCIATIVE],
        '%' => [9, self::LEFT_ASSOCIATIVE],
        '+' => [8, self::LEFT_ASSOCIATIVE],
        '-' => [8, self::LEFT_ASSOCIATIVE],
        '<' => [7, self::LEFT_ASSOCIATIVE],
        '<=' => [7, self::LEFT_ASSOCIATIVE],
        '>' => [7, self::LEFT_ASSOCIATIVE],
        '>=' => [7, self::LEFT_ASSOCIATIVE],
        '==' => [6, self::LEFT_ASSOCIATIVE],
        '!=' => [6, self::LEFT_ASSOCIATIVE],
        '&' => [5, self::LEFT_ASSOCIATIVE],
        '^' => [4, self::LEFT_ASSOCIATIVE],
        '|' => [3, self::LEFT_ASSOCIATIVE],
        '&&' => [2, self::LEFT_ASSOCIATIVE],
        '||' => [1, self::LEFT_ASSOCIATIVE]
    ];

    private $unaryOperators = [
        '++' => [10, self::RIGHT_ASSOCIATIVE],
        '--' => [10, self::RIGHT_ASSOCIATIVE],
        '~' => [10, self::RIGHT_ASSOCIATIVE],
        '-' => [10, self::RIGHT_ASSOCIATIVE]
    ];

    public function generateClass(ClassDeclaration $class): string
    {
        $result = '';

        // compiler options
        if ($class->getType() === ClassDeclaration::TYPE_NPC_EVENT) {
            $result .= "set_compiler_opt base_event_type(@NTYPE_NPC_EVENT)\n\n";
        } elseif ($class->getType() === ClassDeclaration::TYPE_MAKER_EVENT) {
            $result .= "set_compiler_opt base_event_type(@NTYPE_MAKER_EVENT)\n\n";
        }

        // class declaration
        if ($class->getSuper()) {
            $result .= 'class ' . $class->getName() . ' : ' . $class->getSuper() . " {\n";
        } else {
            $result .= 'class ' . $class->getName() . " {\n";
        }

        // parameters
        if ($class->getParameters()) {
            $result .= "parameter:\n";

            foreach ($class->getParameters() as $parameter) {
                if ($parameter->getValue()) {
                    $result .= $parameter->getType() . ' ' . $parameter->getName() . ' = ' . $this->generateExpression($parameter->getValue()) . ";\n";
                } else {
                    $result .= $parameter->getType() . ' ' . $parameter->getName() . ";\n";
                }
            }
        }

        // properties
        if ($class->getProperties()) {
            if ($class->getParameters()) {
                $result .= "\n";
            }

            $result .= "property:\n";

            foreach ($class->getProperties() as $property) {
                $list = [];

                foreach ($property->getRows() as $row) {
                    $list[] = "\n{" . implode('; ', $row) . '}';
                }

                $list = $list ? '{' . implode(';', $list) . "\n}" : '{}';
                $result .= $property->getType() . ' ' . $property->getName() . ' = ' . $list . ";\n";
            }
        }

        // handlers
        if ($class->getHandlers()) {
            if ($class->getParameters() || $class->getProperties()) {
                $result .= "\n";
            }

            $result .= "handler:\n";

            foreach ($class->getHandlers() as $handler) {
                $variables = [];

                foreach ($handler->getVariables() as $variable) {
                    $variables[] = $variable->getName();
                }

                $result .= 'EventHandler ' . $handler->getName() . '(' . implode(', ', $variables) . ") {\n";
                $result .= $this->generateStatement($handler->getBlock());
                $result .= "}\n";
            }
        }

        return $this->indent($result . '}');
    }

    private function generateExpression(Expression $expression, Expression $parent = null): string
    {
        if ($expression instanceof IntegerExpression) {
            // generate hex
            if ($parent instanceof OperationExpression && in_array($parent->getOperator(), ['&', '|', '~'])) {
                return '0x' . dechex($expression->getInteger());
            }

            return $expression->getInteger();
        } elseif ($expression instanceof FloatExpression) {
            $f = $expression->getFloat();

            return floor($f * 10) === $f * 10
                ? sprintf('%.1f', $f)
                : $f;
        } elseif ($expression instanceof StringExpression) {
            return '"' . $expression->getString() . '"';
        } elseif ($expression instanceof PCHExpression) {
            $name = $expression->getName();

            if (strpos($name, 'PSTATE_') === 0) {
                // PSTATE should't be prefixed
                return $expression->getName();
            } else {
                return '@' . (preg_match('/\W/', $name) ? '"' . $name . '"' : $name);
            }
        } elseif ($expression instanceof ParameterExpression) {
            return $expression->getName();
        } elseif ($expression instanceof PropertyExpression) {
            return $expression->getName();
        } elseif ($expression instanceof UnaryExpression) {
            $rhs = $expression->getExpression();
            $generatedRHS = $this->generateExpression($rhs, $expression);

            if ($rhs instanceof OperationExpression && $this->getPrecedence($expression) > $this->getPrecedence($rhs)) {
                return $expression->getOperator() . '(' . $generatedRHS . ')';
            } else {
                return $expression->getOperator() . $generatedRHS;
            }
        } elseif ($expression instanceof BinaryExpression) {
            $lhs = $expression->getLHS();
            $rhs = $expression->getRHS();
            $generatedLHS = $this->generateExpression($lhs, $expression);
            $generatedRHS = $this->generateExpression($rhs, $expression);

            if ($lhs instanceof OperationExpression && (
                $this->getPrecedence($expression) > $this->getPrecedence($lhs) ||
                $this->getPrecedence($expression) == $this->getPrecedence($lhs) &&
                $this->isRightAssociative($expression)
            )) {
                $generatedLHS = '(' . $generatedLHS . ')';
            }

            if ($rhs instanceof OperationExpression && (
                $this->getPrecedence($expression) > $this->getPrecedence($rhs) ||
                $this->getPrecedence($expression) == $this->getPrecedence($rhs) &&
                $this->isLeftAssociative($expression)
            )) {
                $generatedRHS = '(' . $generatedRHS . ')';
            }

            return $generatedLHS . ' ' . $expression->getOperator() . ' ' . $generatedRHS;
        } elseif ($expression instanceof AssignExpression) {
            $lvalue = $this->generateExpression($expression->getLValue());
            $rvalue = $expression->getRValue();
            return $lvalue . ' = ' . $this->generateExpression($rvalue);
        } elseif ($expression instanceof VariableExpression) {
            $path = $expression->getObject() ? $this->generateExpression($expression->getObject()) . '.' : '';
            return $path . $expression->getName();
        } elseif ($expression instanceof CallExpression) {
            $path = '';
            $object = $expression->getObject();

            // myself. & gg. are not necessary for function calls
            if ($object && (!$object instanceof VariableExpression || !in_array($object->getName(), ['myself', 'gg']))) {
                $path = $this->generateExpression($expression->getObject()) . '.';
            }

            $arguments = implode(', ', array_map([$this, 'generateExpression'], $expression->getArguments()));
            return $path . $expression->getFunction() . '(' . $arguments . ')';
        }

        return '';
    }

    private function generateStatement(Statement $statement): string
    {
        if ($statement instanceof IfStatement) {
            $if = 'if (' . $this->generateExpression($statement->getCondition()) . ") {\n";
            $if .= $this->generateStatement($statement->getThenBlock());
            $if .= '}';

            $else = $statement->getElseBlock()->getStatements();

            if (count($else) === 1 && $else[0] instanceof IfStatement) {
                $if .= ' else ' . $this->generateStatement($else[0]);
            } elseif ($else) {
                $if .= " else {\n";
                $if .= $this->generateStatement($statement->getElseBlock());
                $if .= '}';
            }

            return $if;
        } elseif ($statement instanceof SelectStatement) {
            $select = 'select (' . $this->generateExpression($statement->getCondition()) . ") {\n";

            foreach ($statement->getCases() as $case) {
                $select .= 'case ' . $this->generateExpression($case->getExpression()) . ":\n";
                $select .= $this->generateStatement($case->getBlock());
            }

            return $select . '}';
        } elseif ($statement instanceof WhileStatement) {
            $while = 'while (' . $this->generateExpression($statement->getCondition()) . ") {\n";
            $while .= $this->generateStatement($statement->getBlock());
            return $while . '}';
        } elseif ($statement instanceof ForStatement) {
            $init = $this->generateExpression($statement->getInit());
            $condition = $this->generateExpression($statement->getCondition());
            $update = $this->generateExpression($statement->getUpdate());
            $for = 'for (' . $init . '; ' . $condition . '; ' . $update . ") {\n";
            $for .= $this->generateStatement($statement->getBlock());
            return $for . '}';
        } elseif ($statement instanceof ReturnStatement) {
            return 'return;';
        } elseif ($statement instanceof SuperStatement) {
            return 'super;';
        } elseif ($statement instanceof BreakStatement) {
            return 'break;';
        } elseif ($statement instanceof Expression) {
            return $this->generateExpression($statement) . ';';
        } elseif ($statement instanceof BlockStatement) {
            $block = '';

            foreach ($statement->getStatements() as $statement) {
                $block .= $this->generateStatement($statement) . "\n";
            }

            return $block;
        }

        return '';
    }

    private function indent(string $string): string
    {
        $result = [];
        $lines = explode("\n", $string);
        $indent = 0;

        foreach ($lines as $index => $line) {
            $prev = $lines[$index - 1] ?? '';
            $prevLastChar = substr($prev, -1, 1);
            $currLastChar = substr($line, -1, 1);
            $currFirstChar = $line[0] ?? '';

            if ($currFirstChar === '}' || $currLastChar === ':') {
                $indent--;
            }

            if ($currLastChar !== ':' &&
                $currFirstChar !== '}' &&
                ($prevLastChar === '}' || $currLastChar === '{' && trim($prevLastChar, '{:'))
            ) {
                $result[] = '';
            }

            $result[] = str_repeat("\t", $indent) . $line;

            if ($currLastChar === '{' || $currLastChar === ':') {
                $indent++;
            }
        }

        return implode("\n", $result) . "\n";
    }

    private function getPrecedence(OperationExpression $expression): int
    {
        $operators = $expression instanceof BinaryExpression ? $this->binaryOperators : $this->unaryOperators;
        return $operators[$expression->getOperator()][0];
    }

    private function isLeftAssociative(OperationExpression $expression): bool
    {
        $operators = $expression instanceof BinaryExpression ? $this->binaryOperators : $this->unaryOperators;
        return $operators[$expression->getOperator()][1] === self::LEFT_ASSOCIATIVE;
    }

    private function isRightAssociative(OperationExpression $expression): bool
    {
        $operators = $expression instanceof BinaryExpression ? $this->binaryOperators : $this->unaryOperators;
        return $operators[$expression->getOperator()][1] === self::RIGHT_ASSOCIATIVE;
    }
}
