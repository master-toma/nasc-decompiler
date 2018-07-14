<?php

class NascGenerator implements GeneratorInterface
{
    private $priority = [
        '*' => 9,
        '/' => 9,
        '%' => 9,
        '+' => 8,
        '-' => 8,
        '<' => 7,
        '<=' => 7,
        '>' => 7,
        '>=' => 7,
        '==' => 6,
        '!=' => 6,
        '&' => 5,
        '^' => 4,
        '|' => 3,
        '&&' => 2,
        '||' => 1,
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

        return $this->indent($result . '}') . "\n";
    }

    private function generateExpression(Expression $expression, Expression $parent = null): string
    {
        if ($expression instanceof IntegerExpression) {
            // generate hex
            if ($parent instanceof OperationExpression && !trim($parent->getOperator(), '&|~')) {
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
        } elseif ($expression instanceof EnumExpression) {
            if ($expression->getType() === 'PSTATE') {
                // PSTATE type should't be prefixed
                return $expression->getName();
            } else {
                return '@' . $expression->getName();
            }
        } elseif ($expression instanceof ParameterExpression) {
            return $expression->getName();
        } elseif ($expression instanceof PropertyExpression) {
            return $expression->getName();
        } elseif ($expression instanceof UnaryExpression) {
            return $expression->getOperator() . $this->generateExpression($expression->getExpression(), $expression);
        } elseif ($expression instanceof BinaryExpression) {
            $lhs = $this->generateExpression($expression->getLHS(), $expression);
            $rhs = $this->generateExpression($expression->getRHS(), $expression);
            $result = $lhs . ' ' . $expression->getOperator() . ' ' . $rhs;

            // check operators priority
            if ($parent instanceof BinaryExpression) {
                $expressionPriority = $this->priority[$expression->getOperator()];
                $parentPriority = $this->priority[$parent->getOperator()];

                if ($expressionPriority < $parentPriority ||
                    $expression === $parent->getRHS() &&
                    $expressionPriority === $parentPriority &&
                    !trim($parent->getOperator(), '/-')
                ) {
                    $result = '(' . $result . ')';
                }
            }

            return $result;
        } elseif ($expression instanceof AssignExpression) {
            $lvalue = $this->generateExpression($expression->getLValue());
            $rvalue = $expression->getRValue();

            // generate increment/decrement
            if ($rvalue instanceof BinaryExpression && !trim($rvalue->getOperator(), '+-')) {
                $lhs = $rvalue->getLHS();
                $rhs = $rvalue->getRHS();

                if ($lhs instanceof IntegerExpression && $lhs->getInteger() === 1 &&
                    $rhs instanceof VariableExpression && $this->generateExpression($rhs) === $lvalue ||
                    $rhs instanceof IntegerExpression && $rhs->getInteger() === 1 &&
                    $lhs instanceof VariableExpression && $this->generateExpression($lhs) === $lvalue
                ) {
                    return $rvalue->getOperator() . $rvalue->getOperator() . $lvalue;
                }
            }

            return $lvalue . ' = ' . $this->generateExpression($rvalue);
        } elseif ($expression instanceof VariableExpression) {
            $object = $expression->getObject() ? $this->generateExpression($expression->getObject()) . '.' : '';
            return $object . $expression->getName();
        } elseif ($expression instanceof CallExpression) {
            $object = '';

            if ($expression->getObject()) {
                $object = $this->generateExpression($expression->getObject()) . '.';

                // myself. & gg. are not necessary for function calls
                if (strpos($object, 'myself.') === 0) {
                    $object = substr($object, strlen('myself.'));
                } elseif (strpos($object, 'gg.') === 0) {
                    $object = substr($object, strlen('gg.'));
                }
            }

            $arguments = implode(', ', array_map([$this, 'generateExpression'], $expression->getArguments()));
            return $object . $expression->getFunction() . '(' . $arguments . ')';
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
}
