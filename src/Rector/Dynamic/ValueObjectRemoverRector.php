<?php declare(strict_types=1);

namespace Rector\Rector\Dynamic;

use Nette\Utils\Strings;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use Rector\Node\Attribute;
use Rector\NodeTraverserQueue\BetterNodeFinder;
use Rector\NodeTypeResolver\NodeTypeResolver;
use Rector\Rector\AbstractRector;
use Rector\ReflectionDocBlock\NodeAnalyzer\DocBlockAnalyzer;

final class ValueObjectRemoverRector extends AbstractRector
{
    /**
     * @var string[]
     */
    private $valueObjectsToSimpleTypes = [];

    /**
     * @var DocBlockAnalyzer
     */
    private $docBlockAnalyzer;

    /**
     * @var NodeTypeResolver
     */
    private $nodeTypeResolver;

    /**
     * @var BetterNodeFinder
     */
    private $betterNodeFinder;

    /**
     * @param string[] $valueObjectsToSimpleTypes
     */
    public function __construct(
        array $valueObjectsToSimpleTypes,
        DocBlockAnalyzer $docBlockAnalyzer,
        NodeTypeResolver $nodeTypeResolver,
        BetterNodeFinder $betterNodeFinder
    ) {
        $this->valueObjectsToSimpleTypes = $valueObjectsToSimpleTypes;
        $this->docBlockAnalyzer = $docBlockAnalyzer;
        $this->nodeTypeResolver = $nodeTypeResolver;
        $this->betterNodeFinder = $betterNodeFinder;
    }

    public function isCandidate(Node $node): bool
    {
        if ($node instanceof New_) {
            return $this->processNewCandidate($node);
        }

        if ($node instanceof Property) {
            return $this->processPropertyCandidate($node);
        }

        if ($node instanceof Name) {
            $parentNode = $node->getAttribute(Attribute::PARENT_NODE);
            if ($parentNode instanceof Param) {
                return true;
            }
        }

        // + Variable for docs update
        return $node instanceof NullableType || $node instanceof Variable;
    }

    /**
     * @param New_ $node
     */
    public function refactor(Node $node): ?Node
    {
        if ($node instanceof New_) {
            return $node->args[0];
        }

        if ($node instanceof Property) {
            return $this->refactorProperty($node);
        }

        if ($node instanceof Name) {
            return $this->refactorName($node);
        }

        if ($node instanceof NullableType) {
            return $this->refactorNullableType($node);
        }

        if ($node instanceof Variable) {
            return $this->refactorVariableNode($node);
        }

        return null;
    }

    private function processNewCandidate(New_ $newNode): bool
    {
        if (count($newNode->args) !== 1) {
            return false;
        }

        $classNodeTypes = $this->nodeTypeResolver->resolve($newNode->class);

        return (bool) array_intersect($classNodeTypes, $this->getValueObjects());
    }

    /**
     * @return string[]
     */
    private function getValueObjects(): array
    {
        return array_keys($this->valueObjectsToSimpleTypes);
    }

    private function processPropertyCandidate(Property $propertyNode): bool
    {
        $propertyNodeTypes = $this->nodeTypeResolver->resolve($propertyNode);

        return (bool) array_intersect($propertyNodeTypes, $this->getValueObjects());
    }

    private function refactorProperty(Property $propertyNode): Property
    {
        $newType = $this->matchNewType($propertyNode);
        if ($newType === null) {
            return $propertyNode;
        }

        $this->docBlockAnalyzer->replaceVarType($propertyNode, $newType);

        return $propertyNode;
    }

    private function matchNewType(Node $node): ?string
    {
        $nodeTypes = $this->nodeTypeResolver->resolve($node);
        foreach ($nodeTypes as $nodeType) {
            if (! isset($this->valueObjectsToSimpleTypes[$nodeType])) {
                continue;
            }

            return $this->valueObjectsToSimpleTypes[$nodeType];
        }

        return null;
    }

    /**
     * @return string[]|null
     */
    private function matchOriginAndNewType(Node $node): ?array
    {
        $nodeTypes = $this->nodeTypeResolver->resolve($node);
        foreach ($nodeTypes as $nodeType) {
            if (! isset($this->valueObjectsToSimpleTypes[$nodeType])) {
                continue;
            }

            return [
                $nodeType,
                $this->valueObjectsToSimpleTypes[$nodeType],
            ];
        }

        return null;
    }

    private function renameNullableInDocBlock(Node $node, string $oldType, string $newType): void
    {
        $this->docBlockAnalyzer->replaceInNode(
            $node,
            sprintf('%s|null', $oldType),
            sprintf('%s|null', $newType)
        );

        $this->docBlockAnalyzer->replaceInNode(
            $node,
            sprintf('null|%s', $oldType),
            sprintf('null|%s', $newType)
        );
    }

    private function refactorNullableType(NullableType $nullableTypeNode): NullableType
    {
        $newType = $this->matchNewType($nullableTypeNode->type);
        if (! $newType) {
            return $nullableTypeNode;
        }

        $parentNode = $nullableTypeNode->getAttribute(Attribute::PARENT_NODE);

        // in method parameter update docs as well
        if ($parentNode instanceof Param) {
            /** @var ClassMethod $classMethodNode */
            $classMethodNode = $parentNode->getAttribute(Attribute::PARENT_NODE);

            $this->renameNullableInDocBlock($classMethodNode, (string) $nullableTypeNode->type, $newType);
        }

        return new NullableType($newType);
    }

    private function refactorVariableNode(Variable $variableNode): Variable
    {
        $match = $this->matchOriginAndNewType($variableNode);
        if (! $match) {
            return $variableNode;
        }

        [$oldType, $newType] = $match;

        $exprNode = $this->betterNodeFinder->findFirstAncestorInstanceOf($variableNode, Expr::class);
        $node = $variableNode;
        if ($exprNode && $exprNode->getAttribute(Attribute::PARENT_NODE)) {
            $node = $exprNode->getAttribute(Attribute::PARENT_NODE);
        }

        $this->renameNullableInDocBlock($node, $oldType, $newType);

        // @todo use right away?
        // SingleName - no slashes or partial uses => return
        if (! Strings::contains($oldType, '\\')) {
            return $node;
        }

        // SomeNamespace\SomeName - possibly used only part in docs blocks
        $oldTypeParts = explode('\\', $oldType);
        $oldTypeParts = array_reverse($oldTypeParts);

        $oldType = '';
        foreach ($oldTypeParts as $oldTypePart) {
            $oldType .= $oldTypePart;

            $this->renameNullableInDocBlock($node, $oldType, $newType);
            $oldType .= '\\';
        }

        return $variableNode;
    }

    private function refactorName(Node $nameNode): ?Name
    {
        $newType = $this->matchNewType($nameNode);
        if ($newType === null) {
            return null;
        }

        return new Name($newType);
    }
}
