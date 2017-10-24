<?php declare(strict_types=1);

namespace Rector\NodeTypeResolver;

use PhpParser\Node;
use Rector\Node\Attribute;
use Rector\NodeTypeResolver\Contract\PerNodeTypeResolver\PerNodeTypeResolverInterface;

final class NodeTypeResolver
{
    /**
     * @var PerNodeTypeResolverInterface[]
     */
    private $perNodeTypeResolvers = [];

    public function addPerNodeTypeResolver(PerNodeTypeResolverInterface $perNodeTypeResolver): void
    {
        $this->perNodeTypeResolvers[] = $perNodeTypeResolver;
    }

    public function resolve(Node $node): ?string
    {
        foreach ($this->perNodeTypeResolvers as $perNodeTypeResolver) {
            if (! is_a($node, $perNodeTypeResolver->getNodeClass(), true)) {
                continue;
            }

            // resolve just once
            if ($node->getAttribute(Attribute::TYPE)) {
                return $node->getAttribute(Attribute::TYPE);
            }

            return $perNodeTypeResolver->resolve($node);
        }

        return null;
    }
}