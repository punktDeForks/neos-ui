<?php

namespace Neos\Neos\Ui\FlowQueryOperations;

/*
 * This file is part of the Neos.Neos.Ui package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindAncestorNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\NodeType\NodeTypeCriteria;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Eel\FlowQuery\Operations\AbstractOperation;
use Neos\Neos\FrontendRouting\NodeAddressFactory;
use Neos\Flow\Annotations as Flow;

class NeosUiDefaultNodesOperation extends AbstractOperation
{
    /**
     * {@inheritdoc}
     *
     * @var string
     */
    protected static $shortName = 'neosUiDefaultNodes';

    /**
     * {@inheritdoc}
     *
     * @var integer
     */
    protected static $priority = 110;

    /**
     * @Flow\Inject
     * @var ContentRepositoryRegistry
     */
    protected $contentRepositoryRegistry;

    /**
     * {@inheritdoc}
     *
     * @param array<int,mixed> $context (or array-like object) onto which this operation should be applied
     * @return boolean TRUE if the operation can be applied onto the $context, FALSE otherwise
     */
    public function canEvaluate($context)
    {
        return isset($context[0]) && ($context[0] instanceof Node);
    }

    /**
     * {@inheritdoc}
     *
     * @param FlowQuery<int,mixed> $flowQuery the FlowQuery object
     * @param array<int,mixed> $arguments the arguments for this operation
     * @return void
     */
    public function evaluate(FlowQuery $flowQuery, array $arguments)
    {
        /** @var array<int,mixed> $context */
        $context = $flowQuery->getContext();
        /** @var Node $siteNode */
        /** @var Node $documentNode */
        list($siteNode, $documentNode) = $context;
        /** @var string[] $toggledNodes Node Addresses */
        list($baseNodeType, $loadingDepth, $toggledNodes, $clipboardNodesContextPaths) = $arguments;

        $contentRepository = $this->contentRepositoryRegistry->get($documentNode->subgraphIdentity->contentRepositoryId);
        $nodeAddressFactory = NodeAddressFactory::create($contentRepository);

        $baseNodeTypeConstraints = NodeTypeCriteria::fromFilterString($baseNodeType);

        $subgraph = $this->contentRepositoryRegistry->subgraphForNode($documentNode);

        $ancestors = $subgraph->findAncestorNodes(
            $documentNode->nodeAggregateId,
            FindAncestorNodesFilter::create(
                NodeTypeCriteria::fromFilterString('Neos.Neos:Document')
            )
        );

        $nodes = [
            ($siteNode->nodeAggregateId->value) => $siteNode
        ];

        $gatherNodesRecursively = function (
            &$nodes,
            Node $baseNode,
            $level = 0
        ) use (
            &$gatherNodesRecursively,
            $baseNodeTypeConstraints,
            $loadingDepth,
            $toggledNodes,
            $ancestors,
            $subgraph,
            $nodeAddressFactory
        ) {
            $baseNodeAddress = $nodeAddressFactory->createFromNode($baseNode);

            if ($level < $loadingDepth || // load all nodes within loadingDepth
                $loadingDepth === 0 || // unlimited loadingDepth
                // load toggled nodes
                in_array($baseNodeAddress->serializeForUri(), $toggledNodes) ||
                // load children of all parents of documentNode
                in_array($baseNode->nodeAggregateId->value, array_map(
                    fn (Node $node): string => $node->nodeAggregateId->value,
                    iterator_to_array($ancestors)
                ))
            ) {
                foreach ($subgraph->findChildNodes(
                    $baseNode->nodeAggregateId,
                    FindChildNodesFilter::create(nodeTypes: $baseNodeTypeConstraints)
                ) as $childNode) {
                    $nodes[$childNode->nodeAggregateId->value] = $childNode;
                    $gatherNodesRecursively($nodes, $childNode, $level + 1);
                }
            }
        };
        $gatherNodesRecursively($nodes, $siteNode);

        if (!isset($nodes[$documentNode->nodeAggregateId->value])) {
            $nodes[$documentNode->nodeAggregateId->value] = $documentNode;
        }

        foreach ($clipboardNodesContextPaths as $clipboardNodeContextPath) {
            // TODO: does not work across multiple CRs yet.
            $clipboardNodeAddress = $nodeAddressFactory->createFromUriString($clipboardNodeContextPath);
            $clipboardNode = $subgraph->findNodeById($clipboardNodeAddress->nodeAggregateId);
            if ($clipboardNode && !array_key_exists($clipboardNode->nodeAggregateId->value, $nodes)) {
                $nodes[$clipboardNode->nodeAggregateId->value] = $clipboardNode;
            }
        }

        /* TODO: we might use the Subtree as this may be more efficient
         - but the logic above mirrors the old behavior better.
        if ($loadingDepth === 0) {
            throw new \RuntimeException('TODO: Loading Depth 0 not supported');
        }
        $subtree = $contentSubgraph->findSubtree([$siteNode], $loadingDepth, $nodeTypeConstraints);
        $subtree = $subtree->getChildren()[0];
        $this->flattenSubtreeToNodeList($nodeAccessor, $subtree, $nodes);*/

        $flowQuery->setContext($nodes);
    }

    /**
     * @param array<string,Node> &$nodes
     */
    /*
    private function flattenSubtreeToNodeList(
        NodeAccessorInterface $nodeAccessor,
        SubtreeInterface $subtree,
        array &$nodes
    ): void {
        $currentNode = $subtree->getNode();
        if (is_null($currentNode)) {
            return;
        }

        $nodes[(string)$currentNode->getNodeAggregateId()] = $currentNode;

        foreach ($subtree->getChildren() as $childSubtree) {
            $this->flattenSubtreeToNodeList($nodeAccessor, $childSubtree, $nodes);
        }
    }*/
}
