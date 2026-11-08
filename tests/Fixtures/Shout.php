<?php

declare(strict_types=1);

namespace Kipchak\Driver\Doctrine\Tests\Fixtures;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * SHOUT(expression): the expression in upper case. A custom DQL string function, registered through the driver's
 * custom_dql_functions configuration.
 */
final class Shout extends FunctionNode
{
    private Node $expression;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->expression = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return 'UPPER(' . $this->expression->dispatch($sqlWalker) . ')';
    }
}
