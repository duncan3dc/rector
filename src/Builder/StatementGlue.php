<?php declare(strict_types=1);

namespace Rector\Builder;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Nop;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\TraitUse;

final class StatementGlue
{
    /**
     * @param ClassMethod|Property|ClassMethod $node
     */
    public function addAsFirstMethod(Class_ $classNode, Node $node): void
    {
        foreach ($classNode->stmts as $key => $classElementNode) {
            if ($classElementNode instanceof ClassMethod) {
                $classNode->stmts = $this->insertBefore($classNode->stmts, $node, $key);

                return;
            }
        }

        $previousElement = null;
        foreach ($classNode->stmts as $key => $classElementNode) {
            if ($previousElement instanceof Property && ! $classElementNode instanceof Property) {
                $classNode->stmts = $this->insertBefore($classNode->stmts, $node, $key);

                return;
            }

            $previousElement = $classElementNode;
        }

        $classNode->stmts[] = $node;
    }

    public function addAsFirstTrait(Class_ $classNode, Node $node): void
    {
        $this->addStatementToClassBeforeTypes($classNode, $node, TraitUse::class, Property::class);
    }

    /**
     * @param Node[] $nodes
     * @return Node[] $nodes
     */
    public function insertBeforeAndFollowWithNewline(array $nodes, Node $node, int $key): array
    {
        $nodes = $this->insertBefore($nodes, $node, $key);
        $nodes = $this->insertBefore($nodes, new Nop(), $key);

        return $nodes;
    }

    /**
     * @param Node[] $nodes
     * @return Node[] $nodes
     */
    public function insertBefore(array $nodes, Node $node, int $key): array
    {
        array_splice($nodes, $key, 0, [$node]);

        return $nodes;
    }

    private function addStatementToClassBeforeTypes(Class_ $classNode, Node $node, string ...$types): void
    {
        foreach ($types as $type) {
            foreach ($classNode->stmts as $key => $classElementNode) {
                if ($classElementNode instanceof $type) {
                    $classNode->stmts = $this->insertBefore($classNode->stmts, $node, $key);

                    return;
                }
            }
        }

        $classNode->stmts[] = $node;
    }
}
