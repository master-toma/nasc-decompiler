<?php

interface Node
{
}

interface Statement extends Node
{
}

interface Expression extends Statement
{
    public function getType(): string;
}

interface Declaration extends Node
{
}

interface OperationExpression extends Expression
{
    public function getOperator(): string;
}

/* EXPRESSIONS */

class AssignExpression implements Expression
{
    private $lvalue = null;
    private $rvalue = null;

    public function __construct(VariableExpression $lvalue, Expression $rvalue)
    {
        $this->lvalue = $lvalue;
        $this->rvalue = $rvalue;
    }

    public function getLValue(): VariableExpression
    {
        return $this->lvalue;
    }

    public function getRValue(): Expression
    {
        return $this->rvalue;
    }

    public function getType(): string
    {
        return $this->rvalue->getType();
    }
}

class CallExpression implements Expression
{
    private $type = '';
    private $function = '';
    private $object = null;
    private $arguments = [];
    private $comment = null;

    public function __construct(string $type, string $function, array $arguments, ?Expression $object)
    {
        $this->type = $type;
        $this->function = $function;
        $this->arguments = $arguments;
        $this->object = $object;
    }

    public function getFunction(): string
    {
        return $this->function;
    }

    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function getObject(): ?Expression
    {
        return $this->object;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setComment(string $comment)
    {
        $this->comment = $comment;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }
}

class ParameterExpression implements Expression
{
    private $type = '';
    private $name = '';

    public function __construct(string $type, string $name)
    {
        $this->type = $type;
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }
}

class PropertyExpression extends ParameterExpression
{
}

class VariableExpression implements Expression
{
    private $type = '';
    private $name = '';
    private $object = null;

    public function __construct(string $type, string $name, Expression $object = null)
    {
        $this->type = $type;
        $this->name = $name;
        $this->object = $object;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getObject(): ?Expression
    {
        return $this->object;
    }

    public function getType(): string
    {
        return $this->type;
    }
}

class IntegerExpression implements Expression
{
    private $integer = 0;

    public function __construct(int $integer)
    {
        $this->integer = $integer;
    }

    public function getInteger(): int
    {
        return $this->integer;
    }

    public function getType(): string
    {
        return 'int';
    }
}

class FloatExpression implements Expression
{
    private $float = 0.0;

    public function __construct(float $float)
    {
        $this->float = $float;
    }

    public function getFloat(): float
    {
        return $this->float;
    }

    public function getType(): string
    {
        return 'double';
    }
}

class StringExpression implements Expression
{
    private $string = '';

    public function __construct(string $string)
    {
        $this->string = $string;
    }

    public function appendString(string $string)
    {
        $this->string .= $string;
    }

    public function getString(): string
    {
        return $this->string;
    }

    public function getType(): string
    {
        return 'string';
    }
}

class PCHExpression implements Expression
{
    private $type = '';
    private $name = '';

    public function __construct(string $type, string $name)
    {
        $this->type = $type;
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }
}

class BinaryExpression implements OperationExpression
{
    private $lhs = null;
    private $rhs = null;
    private $operator = '';

    public function __construct(Expression $lhs, Expression $rhs, string $operator)
    {
        $this->lhs = $lhs;
        $this->rhs = $rhs;
        $this->operator = $operator;
    }

    public function getLHS(): Expression
    {
        return $this->lhs;
    }

    public function getRHS(): Expression
    {
        return $this->rhs;
    }

    public function getOperator(): string
    {
        return $this->operator;
    }

    public function getType(): string
    {
        switch ($this->operator) {
            case '+':
                if ($this->lhs->getType() === 'string' || $this->rhs->getType() === 'string') {
                    return 'string';
                }

                return 'int';
            case '-':
            case '*':
            case '/':
                // workaround for short skill ids
                if ($this->lhs->getType() === 'SKILL') {
                    return 'SKILL_SHORT';
                } elseif ($this->lhs->getType() === 'int' && $this->rhs->getType() === 'int') {
                    return 'int';
                }

                return 'double';
            case '%':
            case '&':
            case '|':
            case '^':
                return 'int';
        }

        return 'BOOL';
    }
}

class UnaryExpression implements OperationExpression
{
    private $expression = null;
    private $operator = '';

    public function __construct(Expression $expression, string $operator)
    {
        $this->expression = $expression;
        $this->operator = $operator;
    }

    public function getExpression(): Expression
    {
        return $this->expression;
    }

    public function getOperator(): string
    {
        return $this->operator;
    }

    public function getType(): string
    {
        return $this->expression->getType();
    }
}

/* STATEMENTS */

class ReturnStatement implements Statement
{
}

class BreakStatement implements Statement
{
}

class SuperStatement implements Statement
{
    private $handler = null;

    public function __construct(HandlerDeclaration $handler)
    {
        $this->handler = $handler;
    }

    public function getHandler(): HandlerDeclaration
    {
        return $this->handler;
    }
}

class BlockStatement implements Statement
{
    private $statements = [];

    public function addStatement(Statement $statement)
    {
        $this->statements[] = $statement;
    }

    public function getStatements(): array
    {
        return $this->statements;
    }

    public function popStatement(): ?Statement
    {
        return array_pop($this->statements);
    }
}

class IfStatement implements Statement
{
    private $condition = null;
    private $thenBlock = null;
    private $elseBlock = null;

    public function __construct(Expression $condition)
    {
        $this->condition = $condition;
        $this->thenBlock = new BlockStatement();
        $this->elseBlock = new BlockStatement();
    }

    public function getCondition(): Expression
    {
        return $this->condition;
    }

    public function getThenBlock(): BlockStatement
    {
        return $this->thenBlock;
    }

    public function getElseBlock(): BlockStatement
    {
        return $this->elseBlock;
    }
}

class WhileStatement implements Statement
{
    private $condition = null;
    private $block = null;

    public function __construct(Expression $condition)
    {
        $this->condition = $condition;
        $this->block = new BlockStatement();
    }

    public function getCondition(): Expression
    {
        return $this->condition;
    }

    public function getBlock(): BlockStatement
    {
        return $this->block;
    }
}

class ForStatement implements Statement
{
    private $init = null;
    private $condition = null;
    private $update = null;
    private $block = null;

    public function __construct(Expression $init, Expression $condition, Expression $update)
    {
        $this->init = $init;
        $this->condition = $condition;
        $this->update = $update;
        $this->block = new BlockStatement();
    }

    public function getInit(): Expression
    {
        return $this->init;
    }

    public function getCondition(): Expression
    {
        return $this->condition;
    }

    public function getUpdate(): Expression
    {
        return $this->update;
    }

    public function getBlock(): BlockStatement
    {
        return $this->block;
    }
}

class SelectStatement implements Statement
{
    private $condition = null;
    private $cases = [];

    public function __construct(Expression $condition)
    {
        $this->condition = $condition;
    }

    public function getCondition(): Expression
    {
        return $this->condition;
    }

    public function addCase(CaseStatement $case)
    {
        $this->cases[] = $case;
    }

    /**
     * @return CaseStatement[]
     */
    public function getCases(): array
    {
        return $this->cases;
    }
}

class CaseStatement implements Statement
{
    private $expression = null;
    private $block = null;

    public function __construct(Expression $expression)
    {
        $this->expression = $expression;
        $this->block = new BlockStatement();
    }

    public function getExpression(): Expression
    {
        return $this->expression;
    }

    public function getBlock(): BlockStatement
    {
        return $this->block;
    }
}

/* DECLARATIONS */

class ClassDeclaration implements Declaration
{
    public const TYPE_NPC_EVENT = 0;
    public const TYPE_MAKER_EVENT = 1;

    private $type = self::TYPE_NPC_EVENT;
    private $name = '';
    private $super = '';

    private $parameters = [];
    private $properties = [];
    private $handlers = [];

    public function __construct(int $type, string $name, string $super = '')
    {
        $this->type = $type;
        $this->name = $name;
        $this->super = $super;
    }

    public function getType(): int
    {
        return $this->type;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSuper(): string
    {
        return $this->super;
    }

    public function addParameter(ParameterDeclaration $parameter)
    {
        $this->parameters[] = $parameter;
    }

    /**
     * @return ParameterDeclaration[]
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function addProperty(PropertyDeclaration $property)
    {
        $this->properties[] = $property;
    }

    /**
     * @return PropertyDeclaration[]
     */
    public function getProperties(): array
    {
        return $this->properties;
    }

    public function addHandler(HandlerDeclaration $handler)
    {
        $this->handlers[] = $handler;
    }

    /**
     * @return HandlerDeclaration[]
     */
    public function getHandlers(): array
    {
        return $this->handlers;
    }
}

class ParameterDeclaration implements Declaration
{
    private $type = '';
    private $name = '';
    private $value = null;

    public function __construct(string $type, string $name, ?Expression $value)
    {
        $this->type = $type;
        $this->name = $name;
        $this->value = $value;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): ?Expression
    {
        return $this->value;
    }
}

class PropertyDeclaration implements Declaration
{
    private $type = '';
    private $name = '';
    private $rows = [];

    public function __construct(string $type, string $name)
    {
        $this->type = $type;
        $this->name = $name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function addRow(array $row, ?string $comment = null)
    {
        $this->rows[] = [$row, $comment];
    }

    public function getRows(): array
    {
        return $this->rows;
    }
}

class VariableDeclaration implements Declaration
{
    private $type = '';
    private $name = '';

    public function __construct(string $type, string $name)
    {
        $this->type = $type;
        $this->name = $name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getName(): string
    {
        return $this->name;
    }
}

class HandlerDeclaration implements Declaration
{
    private $name = '';
    private $block = null;
    private $variables = [];

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->block = new BlockStatement();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function addVariable(VariableDeclaration $variable)
    {
        $this->variables[] = $variable;
    }

    /**
     * @return VariableDeclaration[]
     */
    public function getVariables(): array
    {
        return $this->variables;
    }

    public function getBlock(): BlockStatement
    {
        return $this->block;
    }
}

class HeaderDeclaration implements Declaration
{
    public $sizeOfPointer = 0;
    public $sharedFactoryVersion = 0;
    public $npcHVersion = 0;
    public $nascVersion = 0;
    public $npcEventVersion = 0;
    public $debug = 0;
}
