<?php
declare(strict_types=1);
namespace Neos\Neos\Ui\Domain\Model\Changes;

/*
 * This file is part of the Neos.Neos.Ui package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateClassification;
use Neos\Neos\FrontendRouting\NodeAddressFactory;
use Neos\ContentRepository\Core\Projection\ContentGraph\Nodes;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Ui\ContentRepository\Service\NeosUiNodeService;
use Neos\Neos\Ui\Domain\Model\AbstractChange;
use Neos\Neos\Ui\Domain\Model\Feedback\Operations\ReloadDocument;
use Neos\Neos\Ui\Domain\Model\Feedback\Operations\RenderContentOutOfBand;
use Neos\Neos\Ui\Domain\Model\Feedback\Operations\UpdateNodeInfo;
use Neos\Neos\Ui\Domain\Model\RenderedNodeDomAddress;
use Neos\Neos\Utility\NodeTypeWithFallbackProvider;

/**
 * A change that performs structural actions like moving or creating nodes
 */
abstract class AbstractStructuralChange extends AbstractChange
{
    use NodeTypeWithFallbackProvider;

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * The node dom address for the parent node of the created node
     */
    protected ?RenderedNodeDomAddress $parentDomAddress = null;

    /**
     * The node dom address for the referenced sibling node of the created node
     */
    protected ?RenderedNodeDomAddress $siblingDomAddress = null;

    /**
     * @Flow\Inject
     * @var NeosUiNodeService
     */
    protected $nodeService;

    protected ?Node $cachedSiblingNode = null;

    /**
     * Used when creating nodes within non-default tree preset
     */
    protected ?string $baseNodeType = null;

    public function setBaseNodeType(string $baseNodeType): void
    {
        $this->baseNodeType = $baseNodeType;
    }

    public function getBaseNodeType(): ?string
    {
        return $this->baseNodeType;
    }

    /**
     * Get the insertion mode (before|after|into) that is represented by this change
     */
    abstract public function getMode(): string;

    public function setParentDomAddress(RenderedNodeDomAddress $parentDomAddress = null): void
    {
        $this->parentDomAddress = $parentDomAddress;
    }

    /**
     * Get the DOM address of the closest RENDERED node in the DOM tree.
     *
     * DOES NOT HAVE TO BE THE PARENT NODE!
     */
    public function getParentDomAddress(): ?RenderedNodeDomAddress
    {
        return $this->parentDomAddress;
    }

    public function setSiblingDomAddress(RenderedNodeDomAddress $siblingDomAddress = null): void
    {
        $this->siblingDomAddress = $siblingDomAddress;
    }

    public function getSiblingDomAddress(): ?RenderedNodeDomAddress
    {
        return $this->siblingDomAddress;
    }

    /**
     * Get the sibling node
     */
    public function getSiblingNode(): ?Node
    {
        if ($this->siblingDomAddress === null || !$this->getSubject()) {
            return null;
        }

        if ($this->cachedSiblingNode === null) {
            $this->cachedSiblingNode = $this->nodeService->findNodeBySerializedNodeAddress(
                $this->siblingDomAddress->getContextPath(),
                $this->getSubject()->subgraphIdentity->contentRepositoryId
            );
        }

        return $this->cachedSiblingNode;
    }

    /**
     * Perform finish tasks - needs to be called from inheriting class on `apply`
     *
     * @param Node $node
     * @return void
     */
    protected function finish(Node $node)
    {
        $updateNodeInfo = new UpdateNodeInfo();
        $updateNodeInfo->setNode($node);
        $updateNodeInfo->recursive();
        $this->feedbackCollection->add($updateNodeInfo);

        $parentNode = $this->contentRepositoryRegistry->subgraphForNode($node)
            ->findParentNode($node->nodeAggregateId);
        if ($parentNode) {
            $updateParentNodeInfo = new UpdateNodeInfo();
            $updateParentNodeInfo->setNode($parentNode);
            if ($this->baseNodeType) {
                $updateParentNodeInfo->setBaseNodeType($this->baseNodeType);
            }
            $this->feedbackCollection->add($updateParentNodeInfo);
        }

        $this->updateWorkspaceInfo();

        if ($this->getNodeType($node)->isOfType('Neos.Neos:Content')
            && ($this->getParentDomAddress() || $this->getSiblingDomAddress())) {
            // we can ONLY render out of band if:
            // 1) the parent of our new (or copied or moved) node is a ContentCollection;
            // so we can directly update an element of this content collection

            $contentRepository = $this->contentRepositoryRegistry->get($node->subgraphIdentity->contentRepositoryId);
            if ($parentNode && $this->getNodeType($parentNode)->isOfType('Neos.Neos:ContentCollection') &&
                // 2) the parent DOM address (i.e. the closest RENDERED node in DOM is actually the ContentCollection;
                // and no other node in between
                $this->getParentDomAddress() &&
                $this->getParentDomAddress()->getFusionPath() &&
                $this->getParentDomAddress()->getContextPath() ===
                    NodeAddressFactory::create($contentRepository)->createFromNode($parentNode)->serializeForUri()
            ) {
                $renderContentOutOfBand = new RenderContentOutOfBand();
                $renderContentOutOfBand->setNode($node);
                $renderContentOutOfBand->setParentDomAddress($this->getParentDomAddress());
                $renderContentOutOfBand->setSiblingDomAddress($this->getSiblingDomAddress());
                $renderContentOutOfBand->setMode($this->getMode());

                $this->feedbackCollection->add($renderContentOutOfBand);
            } else {
                $reloadDocument = new ReloadDocument();
                $reloadDocument->setNode($node);

                $this->feedbackCollection->add($reloadDocument);
            }
        }
    }

    protected function findChildNodes(Node $node): Nodes
    {
        // TODO REMOVE
        return $this->contentRepositoryRegistry->subgraphForNode($node)
            ->findChildNodes($node->nodeAggregateId, FindChildNodesFilter::create());
    }

    protected function isNodeTypeAllowedAsChildNode(Node $node, NodeType $nodeType): bool
    {
        if ($node->classification !== NodeAggregateClassification::CLASSIFICATION_TETHERED) {
            return $this->getNodeType($node)->allowsChildNodeType($nodeType);
        }

        $subgraph = $this->contentRepositoryRegistry->subgraphForNode($node);
        $parentNode = $subgraph->findParentNode($node->nodeAggregateId);
        $nodeTypeManager = $this->contentRepositoryRegistry->get($node->subgraphIdentity->contentRepositoryId)->getNodeTypeManager();

        return !$parentNode || $nodeTypeManager->isNodeTypeAllowedAsChildToTetheredNode(
            $this->getNodeType($parentNode),
            $node->nodeName,
            $nodeType
        );
    }
}
