<?php declare(strict_types=1);

namespace Rector\Rector\Contrib\PHPUnit\SpecificMethod;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use Rector\NodeAnalyzer\MethodCallAnalyzer;
use Rector\NodeChanger\IdentifierRenamer;
use Rector\Rector\AbstractPHPUnitRector;

/**
 * - Before:
 * - $this->assertTrue($foo === $bar, 'message');
 * - $this->assertFalse($foo >= $bar, 'message');
 *
 * - After:
 * - $this->assertSame($bar, $foo, 'message');
 * - $this->assertLessThanOrEqual($bar, $foo, 'message');
 */
final class AssertComparisonToSpecificMethodRector extends AbstractPHPUnitRector
{
    /**
     * @var string[][]|false[][]
     */
    private $defaultOldToNewMethods = [
        '===' => ['assertSame', 'assertNotSame'],
        '!==' => ['assertNotSame', 'assertSame'],
        '==' => ['assertEquals', 'assertNotEquals'],
        '!=' => ['assertNotEquals', 'assertEquals'],
        '<>' => ['assertNotEquals', 'assertEquals'],
        '>' => ['assertGreaterThan', 'assertLessThan'],
        '<' => ['assertLessThan', 'assertGreaterThan'],
        '>=' => ['assertGreaterThanOrEqual', 'assertLessThanOrEqual'],
        '<=' => ['assertLessThanOrEqual', 'assertGreaterThanOrEqual'],
    ];

    /**
     * @var MethodCallAnalyzer
     */
    private $methodCallAnalyzer;

    /**
     * @var IdentifierRenamer
     */
    private $identifierRenamer;

    /**
     * @var string|null
     */
    private $activeOpSignal;

    public function __construct(MethodCallAnalyzer $methodCallAnalyzer, IdentifierRenamer $identifierRenamer)
    {
        $this->methodCallAnalyzer = $methodCallAnalyzer;
        $this->identifierRenamer = $identifierRenamer;
    }

    public function isCandidate(Node $node): bool
    {
        if (! $this->isInTestClass($node)) {
            return false;
        }

        if (! $this->methodCallAnalyzer->isMethods($node, ['assertTrue', 'assertFalse'])) {
            return false;
        }

        /** @var MethodCall $methodCallNode */
        $methodCallNode = $node;

        $firstArgumentValue = $methodCallNode->args[0]->value;
        if (! $firstArgumentValue instanceof BinaryOp) {
            return false;
        }

        $opCallSignal = $firstArgumentValue->getOperatorSigil();
        if (! isset($this->defaultOldToNewMethods[$opCallSignal])) {
            return false;
        }

        $this->activeOpSignal = $opCallSignal;

        return true;
    }

    /**
     * @param MethodCall $methodCallNode
     */
    public function refactor(Node $methodCallNode): ?Node
    {
        $this->renameMethod($methodCallNode);
        $this->changeOrderArguments($methodCallNode);

        return $methodCallNode;
    }

    public function changeOrderArguments(MethodCall $methodCallNode): void
    {
        $oldArguments = $methodCallNode->args;
        /** @var BinaryOp $expression */
        $expression = $oldArguments[0]->value;

        $firstArgument = new Arg($expression->right);
        $secondArgument = new Arg($expression->left);

        unset($oldArguments[0]);

        $methodCallNode->args = array_merge([
            $firstArgument,
            $secondArgument,
        ], $oldArguments);
    }

    private function renameMethod(MethodCall $methodCallNode): void
    {
        /** @var Identifier $identifierNode */
        $identifierNode = $methodCallNode->name;
        $oldMethodName = $identifierNode->toString();

        [$trueMethodName, $falseMethodName] = $this->defaultOldToNewMethods[$this->activeOpSignal];

        if ($oldMethodName === 'assertTrue' && $trueMethodName) {
            $this->identifierRenamer->renameNode($methodCallNode, $trueMethodName);
        } elseif ($oldMethodName === 'assertFalse' && $falseMethodName) {
            $this->identifierRenamer->renameNode($methodCallNode, $falseMethodName);
        }
    }
}
