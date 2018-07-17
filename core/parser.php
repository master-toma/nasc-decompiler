<?php

class Parser
{
    private const STATE_NONE = 0;
    private const STATE_PARAMETERS = 1;
    private const STATE_PARAMETERS_STRING = 2;
    private const STATE_PROPERTIES_BUYSELL = 3;
    private const STATE_PROPERTIES_TELPOS = 4;
    private const STATE_VARIABLES = 5;

    private $data = null;

    private $state = self::STATE_NONE;

    /** @var SplStack */
    private $expressionStack = null;
    /** @var SplStack */
    private $statementStack = null;
    /** @var SplStack */
    private $blockStack = null;
    /** @var SplStack */
    private $branchStack = null;

    private $parameters = []; // TODO: parameters should be traversed through class hierarchy
    private $labels = [];
    private $strings = [];

    /** @var ClassDeclaration */
    private $class = null;
    /** @var PropertyDeclaration */
    private $property = null;
    /** @var HandlerDeclaration */
    private $handler = null; // TODO: use handler property instead of the expression stack

    public function __construct(Data $data)
    {
        $this->data = $data;
    }

    public function parseClass(Token $token): ?ClassDeclaration
    {
        // reset state variables & stacks
        $this->state = self::STATE_NONE;

        $this->expressionStack = new SplStack();
        $this->statementStack = new SplStack();
        $this->blockStack = new SplStack();
        $this->branchStack = new SplStack();

        $this->labels = [];
        $this->strings = [];

        $this->class = null;

        while ($token) {
            // skip comments
            if (!$token->name) {
                $token = $token->next;
                continue;
            }

            switch ($token->name) {
                case 'class':
                    $this->parseClassBegin($token);
                    break;
                case 'parameter_define_begin':
                    $this->parseParameterDefineBegin();
                    break;
                case 'parameter_define_end':
                    $this->parseParameterDefineEnd();
                    break;
                case 'buyselllist_begin':
                    $this->parseBuySellListBegin($token);
                    break;
                case 'buyselllist_end':
                    $this->parseBuySellListEnd();
                    break;
                case 'telposlist_begin':
                    $this->parseTelPosListBegin($token);
                    break;
                case 'telposlist_end':
                    $this->parseTelPosListEnd();
                    break;
                case 'handler':
                    $this->parseHandlerBegin($token);
                    break;
                case 'handler_end':
                    $this->parseHandlerEnd();
                    break;
                case 'variable_begin':
                    $this->parseVariableBegin();
                    break;
                case 'variable_end':
                    $this->parseVariableEnd();
                    break;
                case 'branch_false':
                    $this->parseBranchFalse($token);
                    break;
                case 'shift_sp':
                    $this->parseShiftSp($token);
                    break;
                case 'assign4':
                case 'assign':
                    $this->parseAssign($token);
                    break;
                case 'jump':
                    $this->parseJump($token);
                    break;
                case 'push_const':
                    $this->parsePushConst($token);
                    break;
                case 'func_call':
                    $this->parseFuncCall($token);
                    break;
                case 'add':
                case 'add_string':
                    $this->parseAdd($token);
                    break;
                case 'push_string':
                    $this->parsePushString($token);
                    break;
                case 'push_parameter':
                    $this->parsePushParameter($token);
                    break;
                case 'push_property':
                    $this->parsePushProperty($token);
                    break;
                case 'fetch_i':
                case 'fetch_i4':
                case 'fetch_f':
                case 'fetch_d':
                    $this->parseFetch($token);
                    break;
                case 'call_super':
                    $this->parseCallSuper();
                    break;
                case 'exit_handler':
                    $this->parseExitHandler();
                    break;
                case 'equal':
                    $this->parseEqual();
                    break;
                case 'not_equal':
                    $this->parseBinary('!=');
                    break;
                case 'greater':
                    $this->parseBinary('>');
                    break;
                case 'greater_equal':
                    $this->parseBinary('>=');
                    break;
                case 'less':
                    $this->parseBinary('<');
                    break;
                case 'less_equal':
                    $this->parseBinary('<=');
                    break;
                case 'and':
                    $this->parseBinary('&&');
                    break;
                case 'or':
                    $this->parseBinary('||');
                    break;
                case 'bit_and':
                    $this->parseBinary('&');
                    break;
                case 'bit_or':
                    $this->parseBinary('|');
                    break;
                case 'mul':
                    $this->parseBinary('*');
                    break;
                case 'div':
                    $this->parseBinary('/');
                    break;
                case 'sub':
                    $this->parseBinary('-');
                    break;
                case 'xor':
                    $this->parseBinary('^');
                    break;
                case 'mod':
                    $this->parseBinary('%');
                    break;
                case 'not':
                    $this->parseUnary('~');
                    break;
                case 'negate':
                    $this->parseUnary('-');
                    break;
                default:
                    if ($token->isString()) {
                        $this->parseString($token);
                    } elseif ($token->isLabel()) {
                        $this->parseLabel($token);
                    } else {
                        switch ($this->state) {
                            case self::STATE_PARAMETERS:
                                $this->parseParameter($token);
                                break;
                            case self::STATE_PARAMETERS_STRING:
                                $this->parseParameterString($token);
                                break;
                            case self::STATE_PROPERTIES_BUYSELL:
                                $this->parseBuySellList($token);
                                break;
                            case self::STATE_PROPERTIES_TELPOS:
                                $this->parseTelPosList($token);
                                break;
                            case self::STATE_VARIABLES:
                                $this->parseVariable($token);
                                break;
                        }
                    }
            }

            $token = $token->next;
        }

        return $this->class;
    }

    /* PARSERS */

    private function parseClassBegin(Token $token)
    {
        if (count($token->data) === 3) { // c1 support workaround
            $this->class = new ClassDeclaration(
                0,
                $token->data[0],
                $token->data[2] !== '(null)' ? $token->data[2] : ''
            );
        } else {
            $this->class = new ClassDeclaration(
                $token->data[0],
                $token->data[1],
                $token->data[3] !== '(null)' ? $token->data[3] : ''
            );
        }
    }

    private function parseParameterDefineBegin()
    {
        $this->state = self::STATE_PARAMETERS;
    }

    private function parseParameterDefineEnd()
    {
        $this->state = self::STATE_NONE;
    }

    private function parseBuySellListBegin(Token $token)
    {
        $this->state = self::STATE_PROPERTIES_BUYSELL;
        $property = new PropertyDeclaration('BuySellList', $token->data[0]);
        $this->class->addProperty($property);
        $this->property = $property;
    }

    private function parseBuySellListEnd()
    {
        $this->state = self::STATE_NONE;
        $this->property = null;
    }

    private function parseTelPosListBegin(Token $token)
    {
        $this->state = self::STATE_PROPERTIES_TELPOS;
        $property = new PropertyDeclaration('TelPosList', $token->data[0]);
        $this->class->addProperty($property);
        $this->property = $property;
    }

    private function parseTelPosListEnd()
    {
        $this->state = self::STATE_NONE;
        $this->property = null;
    }

    private function parseHandlerBegin(Token $token)
    {
        $name = $this->data->getHandler($this->class->getType(), $token->data[0]);
        $this->handler = new HandlerDeclaration($name);

        $this->class->addHandler($this->handler);
        $this->blockStack[] = $this->handler->getBlock();
        $this->statementStack[] = $this->handler;
    }

    private function parseHandlerEnd()
    {
        $this->statementStack->pop();
        $this->blockStack->pop();

        // reset handler-related state
        $this->expressionStack = new SplStack();
        $this->statementStack = new SplStack();
        $this->blockStack = new SplStack();
        $this->branchStack = new SplStack();

        $this->labels = [];
        $this->strings = [];
    }

    private function parseVariableBegin()
    {
        $this->state = self::STATE_VARIABLES;
    }

    private function parseVariableEnd()
    {
        $this->state = self::STATE_NONE;
    }

    private function parseBranchFalse(Token $token)
    {
        if ($this->isWhileToken($token)) {
            [$condition] = $this->popExpressions(1);
            $while = new WhileStatement($condition);
            $this->blockStack->top()->addStatement($while);
            $this->blockStack[] = $while->getBlock();
            $this->statementStack[] = $while;
        } elseif ($this->statementStack->top() === '_case') {
            $this->statementStack->pop();
            [$expression] = $this->popExpressions(1);
            $select = $this->statementStack->top();
            $case = new CaseStatement($this->getEnum([$select->getCondition()->getType(), 'SKILL'], $expression->getInteger()));
            $select->addCase($case);
            $this->blockStack[0] = $case->getBlock();
        } elseif ($this->isSelectToken($token)) {
            [$expression] = $this->popExpressions(1);
            $select = new SelectStatement($expression->getLHS());
            $case = new CaseStatement($expression->getRHS());
            $select->addCase($case);
            $this->blockStack->top()->addStatement($select);
            $this->blockStack[] = $case->getBlock();
            $this->statementStack[] = $select;
        } elseif ($token->next->name === 'jump') {
            $this->statementStack[] = '_for';
        } elseif ($token->next->isLabel() || strpos($token->prev->name, 'fetch_i') === false) { // $token->prev->comment !== 'and list'
            [$condition] = $this->popExpressions(1);
            $if = new IfStatement($condition);
            $this->blockStack->top()->addStatement($if);
            $this->blockStack[] = $if->getThenBlock();
            $this->statementStack[] = $if;
        } else {
            return;
        }

        $this->branchStack[] = $token->data[0];
    }

    private function parseShiftSp(Token $token)
    {
        if ($token->data[0] != -1 || !$this->expressionStack->count()) {
            return;
        }

        $expression = $this->expressionStack->top();

        // check if the last expression is a statement
        if ($token->prev->name !== 'func_call' || $expression instanceof CallExpression && !$expression->getArguments()) {
            $this->blockStack->top()->addStatement($this->expressionStack->pop());
        }
    }

    private function parseAssign(Token $token)
    {
        [$rvalue, $lvalue] = $this->popExpressions(2);

        // detect increment/decrement
        if (in_array($token->prev->name, ['add', 'sub']) &&
            $token->prev->prev->name === 'push_const' && $token->prev->prev->data[0] == 1 &&
            strpos($token->prev->prev->prev->name, 'fetch_i') === 0 &&
            strpos($token->prev->prev->prev->prev->name, 'fetch_i') === 0
        ) {
            $this->expressionStack[] = new UnaryExpression($lvalue, $token->prev->name === 'add' ? '++' : '--');
        } else {
            if ($rvalue instanceof IntegerExpression) {
                $rvalue = $this->getEnum($lvalue->getType(), $rvalue->getInteger());
            }

            $this->expressionStack[] = new AssignExpression($lvalue, $rvalue);
        }

        // for statement
        if ($this->statementStack->top() === '_for') {
            [$update, $condition, $init] = $this->popExpressions(3);
            $for = new ForStatement($init, $condition, $update);
            $this->blockStack->top()->addStatement($for);
            $this->statementStack[0] = $for;
            $this->blockStack[] = $for->getBlock();
        }
    }

    private function parseJump(Token $token)
    {
        for ($i = 0; $i < $this->statementStack->count(); $i++) {
            $statement = $this->statementStack[$i];

            // insert breaks inside while & for statements
            if ($statement instanceof WhileStatement || $statement instanceof ForStatement) {
                if ($token->data[0] === $this->branchStack[$i]) {
                    $this->blockStack->top()->addStatement(new BreakStatement());
                }

                break;
            }
        }
    }

    private function parsePushConst(Token $token)
    {
        if ($token->prev->name === 'push_event') {
            $variable = $this->data->getVariable($this->class->getType(), null, $token->data[0]);
            $this->expressionStack[] = new VariableExpression($variable['type'], $variable['name']);
        } elseif (strpos($token->data[0], '.') !== false) {
            $this->expressionStack[] = new FloatExpression($token->data[0]);
        } else {
            $this->expressionStack[] = new IntegerExpression($token->data[0]);
        }
    }

    private function parseFuncCall(Token $token)
    {
        $function = $this->data->getFunction($token->data[0]);
        $arguments = [];

        foreach (array_reverse($function['arguments']) as $type) {
            $expression = $this->expressionStack->pop();

            if ($expression instanceof IntegerExpression) {
                $expression = $this->getEnum($type, $expression->getInteger());
            }

            array_unshift($arguments, $expression);
        }

        [$object] = $this->popExpressions(1);
        $this->expressionStack[] = new CallExpression($function['type'], $function['name'], $arguments, $object);
    }

    private function parseAdd(Token $token)
    {
        if ($token->prev->prev->name === 'push_event') {
            return;
        }

        [$rhs, $lhs] = $this->popExpressions(2);

        if ($this->isObjectType($lhs->getType()) && $rhs instanceof IntegerExpression) {
            $variable = $this->data->getVariable($this->class->getType(), $lhs->getType(), $rhs->getInteger());
            $this->expressionStack[] = new VariableExpression($variable['type'], $variable['name'], $lhs);
        } else {
            $this->expressionStack[] = $lhs;
            $this->expressionStack[] = $rhs;
            $this->parseBinary('+');
        }
    }

    private function parsePushString(Token $token)
    {
        $this->expressionStack[] = new StringExpression($this->strings[$token->data[0]]);
    }

    private function parsePushParameter(Token $token)
    {
        if (!isset($this->parameters[$token->data[0]])) {
            $this->parameters[$token->data[0]] = 'int';
        }

        $this->expressionStack[] = new ParameterExpression($this->parameters[$token->data[0]], $token->data[0]);
    }

    private function parsePushProperty(Token $token)
    {
        $this->expressionStack[] = new PropertyExpression('', $token->data[0]);
    }

    private function parseEqual()
    {
        if ($this->statementStack->top() !== '_case') {
            $this->parseBinary('==');
        }
    }

    private function parseFetch(Token $token)
    {
        if (strpos($token->next->name, 'fetch_') === 0) {
            $this->expressionStack[] = $this->expressionStack->top();
        }
    }

    private function parseCallSuper()
    {
        $this->blockStack->top()->addStatement(new SuperStatement($this->handler));
    }

    private function parseExitHandler()
    {
        $this->blockStack->top()->addStatement(new ReturnStatement());
    }

    private function parseBinary(string $operator)
    {
        [$rhs, $lhs] = $this->popExpressions(2);

        if ($rhs instanceof IntegerExpression) {
            $rhs = $this->getEnum([$lhs->getType(), 'SKILL'], $rhs->getInteger());
        }

        if ($lhs instanceof IntegerExpression) {
            $lhs = $this->getEnum([$rhs->getType(), 'SKILL'], $lhs->getInteger());
        }

        $this->expressionStack[] = new BinaryExpression($lhs, $rhs, $operator);
    }

    private function parseUnary(string $operator)
    {
        [$expression] = $this->popExpressions(1);

        if ($expression instanceof IntegerExpression && $operator === '-') {
            // negation execution for negative enums
            $this->expressionStack[] = new IntegerExpression(-$expression->getInteger());
        } else {
            $this->expressionStack[] = new UnaryExpression($expression, $operator);
        }
    }

    private function parseString(Token $token)
    {
        $this->strings[$token->name] = trim($token->data[0], '"');
    }

    private function parseLabel(Token $token)
    {
        $this->labels[$token->name] = true;

        while (in_array($token->name, iterator_to_array($this->branchStack))) {
            $this->branchStack->pop();
            $statement = $this->statementStack->pop();

            if ($statement instanceof SelectStatement) {
                if ($token->prev->name === 'jump' && ($token->prev->prev->name === 'jump' || $token->next->isLabel())) {
                    $this->blockStack->top()->addStatement(new BreakStatement());
                }

                $next = $token->next;

                if ($next->name === 'jump') {
                    $next = $this->goToLabel($next);
                }

                if ($next && $next->name === 'push_reg_sp') {
                    $this->statementStack[] = $statement;
                    $this->statementStack[] = '_case';
                } else {
                    $this->blockStack->pop();
                }
            } elseif ($statement instanceof IfStatement && $token->prev->name === 'jump') {
                $this->blockStack[0] = $statement->getElseBlock();
                $this->branchStack[] = $token->prev->data[0];
                $this->statementStack[] = '_else';
            } else {
                $this->blockStack->pop();
            }
        }
    }

    private function parseParameter(Token $token)
    {
        $type = $this->fixTypeCase($token->name);
        $name = $token->data[0];
        $value = $token->data[1] ?? null;
        $expression = null;

        if ($value !== null) {
            if (strpos($value, '"') === 0) {
                $expression = new StringExpression(trim($value, '"'));

                if ($value === '"' || substr($value, -1) !== '"') {
                    $this->state = self::STATE_PARAMETERS_STRING;
                    $this->expressionStack[] = $expression;
                }
            } elseif (strpos($value, '.') !== false) {
                $expression = new FloatExpression($value);
            } elseif (stripos($name, 'skill') !== false ||
                stripos($name, 'buff') !== false ||
                stripos($name, 'spell') !== false ||
                stripos($name, 'magic') !== false ||
                stripos($name, 'phys') !== false ||
                stripos($name, 'heal') !== false
            ) {
                $expression = $this->getEnum('SKILL', $value);
            } elseif (stripos($name, 'item') !== false ||
                stripos($name, 'weapon') !== false
            ) {
                $expression = $this->getEnum('ITEM', $value);
            } elseif (stripos($name, 'npc') !== false) {
                $expression = $this->getEnum('NPC', $value);
            } elseif (strtolower($name) === 'gm_id') {
                $expression = $this->getEnum('GM_ID', $value);
            } else {
                $expression = $this->getEnum(['NPC', 'SKILL'], $value);
            }
        }

        $parameter = new ParameterDeclaration($type, $name, $expression);
        $this->class->addParameter($parameter);
        $this->parameters[$name] = $expression ? $expression->getType() : $type;
    }

    private function parseParameterString(Token $token)
    {
        $this->expressionStack->top()->appendString("\n\t" . trim($token->raw, '"'));

        if (substr($token->raw, -1) === '"') {
            $this->state = self::STATE_PARAMETERS;
            $this->expressionStack->pop();
        }
    }

    private function parseBuySellList(Token $token)
    {
        $raw = substr($token->raw, 1, -1);
        $row = array_map('trim', explode(';', $raw));

        if (is_numeric($row[0])) {
            $row[0] = '"' . $this->data->getEnum('ITEM', $row[0]) . '"';
        }

        $this->property->addRow($row);
    }

    private function parseTelPosList(Token $token)
    {
        $raw = substr($token->raw, 1, -1);
        $row = array_map('trim', explode(';', $raw));
        $this->property->addRow($row);
    }

    private function parseVariable(Token $token)
    {
        $variable = $this->fixVariableName(trim($token->name, '"'));

        if ($variable !== 'myself' && $variable[0] !== '_') {
            $type = $this->data->getVariableType($this->class->getType(), $variable);

            if ($type) {
                $this->statementStack->top()->addVariable(new VariableDeclaration($type, $variable));
            }
        }
    }

    /* UTILITY */

    /**
     * Tries to pop $number of expressions from the expression stack.
     * If there is not enough expressions, pops from the current block's statements list.
     *
     * @param int $number
     * @return Expression[]
     */
    private function popExpressions(int $number): array
    {
        $expressions = [];

        while ($number) {
            $number--;

            if ($this->expressionStack->count()) {
                $expressions[] = $this->expressionStack->pop();
            } elseif ($this->blockStack->count()) {
                $expressions[] = $this->blockStack->top()->popStatement();
            }
        }

        return $expressions;
    }

    private function isWhileToken(Token $token): bool
    {
        $token = $this->goToLabel($token);

        if (!$token) {
            return false;
        }

        return $token->prev->name === 'jump' && isset($this->labels[$token->prev->data[0]]);
    }

    private function isSelectToken(Token $token): bool
    {
        $token = $this->goToLabel($token);

        if (!$token) {
            return false;
        }

        return $token->prev->name === 'jump' && $token->next->name === 'push_reg_sp';
    }

    private function goToLabel(Token $token): ?Token
    {
        $label = $token->data[0];

        if (isset($this->labels[$label])) {
            return null;
        }

        while ($token->name !== $label) {
            $token = $token->next;
        }

        return $token;
    }

    private function isObjectType(string $type): bool
    {
        $primitives = $this->data->getEnums();
        $primitives['int'] = true;
        $primitives['float'] = true;
        $primitives['double'] = true;
        $primitives['string'] = true;
        $primitives['void'] = true;
        $primitives['pointer'] = true;
        $primitives['WayPointsType'] = true;
        $primitives['WayPointDelaysType'] = true;
        return !isset($primitives[$type]);
    }

    private function getEnum($name, int $id): Expression
    {
        $names = (array) $name;

        foreach ($names as $name) {
            $enum = $this->data->getEnum($name, $id);

            if ($enum) {
                $expression = new EnumExpression($name, $enum);

                // workaround for short skill ids
                if ($name === 'SKILL_SHORT') {
                    return new BinaryExpression($expression, new IntegerExpression(65536), '/');
                } else {
                    return $expression;
                }
            }
        }

        return new IntegerExpression($id);
    }

    private function fixTypeCase(string $type): string
    {
        switch ($type) {
            case 'waypointstype':
                return 'WayPointsType';
            case 'waypointdelaystype':
                return 'WayPointDelaysType';
            default:
                return $type;
        }
    }

    private function fixVariableName(string $variable): string
    {
        switch ($variable) {
            case 'damege':
                return 'damage';
            case 'script_event3':
                return 'script_event_arg3';
            default:
                return $variable;
        }
    }
}
