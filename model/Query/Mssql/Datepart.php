<?php

namespace Doctrine\ORM\Query\CustomQuery\Mssql;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Lexer;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;

/**
 * @author Rafael Kassner <kassner@gmail.com>
 */
class Datepart extends FunctionNode
{
    public $date;

    public $firstDateExpression = null;

    public $intervalExpression = null;

    public $unit = null;

    protected static $allowedUnits = [
        'MICROSECOND',
        'SECOND',
        'MINUTE',
        'HOUR',
        'DAY',
        'WEEK',
        'MONTH',
        'QUARTER',
        'YEAR',
        'SECOND_MICROSECOND',
        'MINUTE_MICROSECOND',
        'MINUTE_SECOND',
        'HOUR_MICROSECOND',
        'HOUR_SECOND',
        'HOUR_MINUTE',
        'DAY_MICROSECOND',
        'DAY_SECOND',
        'DAY_MINUTE',
        'DAY_HOUR',
        'YEAR_MONTH',
    ];

    public function getSql(SqlWalker $sqlWalker): string
    {

        $unit = strtoupper(is_string($this->unit) ? $this->unit : $this->unit->value);

        if (!in_array($unit, self::$allowedUnits)) {
            throw QueryException::semanticalError('DATE_ADD() does not support unit "' . $unit . '".');
        }

        return 'DATEPART(' .
            $unit .', '.$sqlWalker->walkArithmeticTerm($this->firstDateExpression).
            ')';

    }

    /**
     * @throws \Doctrine\ORM\Query\QueryException
     */
    public function parse(Parser $parser)
    {
        $parser->match(Lexer::T_IDENTIFIER);
        $parser->match(Lexer::T_OPEN_PARENTHESIS);

        $this->unit = $parser->StringPrimary();
        $parser->match(Lexer::T_COMMA);
        $this->firstDateExpression = $parser->ArithmeticFactor();
        $parser->match(Lexer::T_CLOSE_PARENTHESIS);
    }
}