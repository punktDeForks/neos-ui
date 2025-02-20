<?php
namespace Neos\Neos\Ui\ContentRepository\Service;

/*
 * This file is part of the Neos.Neos.Ui package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Factory\ContentRepositoryId;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Command\DiscardIndividualNodesFromWorkspace;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\Neos\FrontendRouting\NodeAddress;
use Neos\Neos\FrontendRouting\NodeAddressFactory;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Service\UserService as DomainUserService;
use Neos\Neos\PendingChangesProjection\ChangeFinder;
use Neos\Neos\Service\UserService;
use Neos\Neos\Ui\Domain\Model\Feedback\Operations\RemoveNode;
use Neos\Neos\Utility\NodeTypeWithFallbackProvider;

/**
 * @Flow\Scope("singleton")
 */
class WorkspaceService
{
    use NodeTypeWithFallbackProvider;

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * @Flow\Inject
     * @var UserService
     */
    protected $userService;

    /**
     * @Flow\Inject
     * @var DomainUserService
     */
    protected $domainUserService;

    /**
     * Get all publishable node context paths for a workspace
     *
     * @return array<int,array<string,string>>
     */
    public function getPublishableNodeInfo(WorkspaceName $workspaceName, ContentRepositoryId $contentRepositoryId): array
    {
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $workspace = $contentRepository->getWorkspaceFinder()->findOneByName($workspaceName);
        if (is_null($workspace) || $workspace->baseWorkspaceName === null) {
            return [];
        }
        $changeFinder = $contentRepository->projectionState(ChangeFinder::class);
        $changes = $changeFinder->findByContentStreamId($workspace->currentContentStreamId);
        $unpublishedNodes = [];
        foreach ($changes as $change) {
            if ($change->removalAttachmentPoint) {
                $nodeAddress = new NodeAddress(
                    $change->contentStreamId,
                    $change->originDimensionSpacePoint->toDimensionSpacePoint(),
                    $change->nodeAggregateId,
                    $workspaceName
                );

                /**
                 * See {@see Remove::apply} -> Removal Attachment Point == closest document node.
                 */
                $documentNodeAddress = new NodeAddress(
                    $change->contentStreamId,
                    $change->originDimensionSpacePoint->toDimensionSpacePoint(),
                    $change->removalAttachmentPoint,
                    $workspaceName
                );

                $unpublishedNodes[] = [
                    'contextPath' => $nodeAddress->serializeForUri(),
                    'documentContextPath' => $documentNodeAddress->serializeForUri()
                ];
            } else {
                $subgraph = $contentRepository->getContentGraph()->getSubgraph(
                    $workspace->currentContentStreamId,
                    $change->originDimensionSpacePoint->toDimensionSpacePoint(),
                    VisibilityConstraints::withoutRestrictions()
                );
                $node = $subgraph->findNodeById($change->nodeAggregateId);

                if ($node instanceof Node) {
                    $documentNode = $subgraph->findClosestNode($node->nodeAggregateId, FindClosestNodeFilter::create(nodeTypes: NodeTypeNameFactory::NAME_DOCUMENT));
                    if ($documentNode instanceof Node) {
                        $contentRepository = $this->contentRepositoryRegistry->get($documentNode->subgraphIdentity->contentRepositoryId);
                        $nodeAddressFactory = NodeAddressFactory::create($contentRepository);
                        $unpublishedNodes[] = [
                            'contextPath' => $nodeAddressFactory->createFromNode($node)->serializeForUri(),
                            'documentContextPath' => $nodeAddressFactory->createFromNode($documentNode)
                                ->serializeForUri()
                        ];
                    }
                }
            }
        }

        return array_filter($unpublishedNodes, function ($item) {
            return (bool)$item;
        });
    }

    /**
     * Get allowed target workspaces for current user
     *
     * @return array<string,array<string,mixed>>
     */
    public function getAllowedTargetWorkspaces(ContentRepository $contentRepository): array
    {
        $user = $this->domainUserService->getCurrentUser();

        $workspacesArray = [];
        foreach ($contentRepository->getWorkspaceFinder()->findAll() as $workspace) {
            // FIXME: This check should be implemented through a specialized Workspace Privilege or something similar
            // Skip workspace not owned by current user
            if ($workspace->workspaceOwner !== null && $workspace->workspaceOwner !== $user) {
                continue;
            }
            // Skip own personal workspace
            if ($workspace->workspaceName->value === $this->userService->getPersonalWorkspaceName()) {
                continue;
            }

            if ($workspace->isPersonalWorkspace()) {
                // Skip other personal workspaces
                continue;
            }

            $workspaceArray = [
                'name' => $workspace->workspaceName->jsonSerialize(),
                'title' => $workspace->workspaceTitle->jsonSerialize(),
                'description' => $workspace->workspaceDescription->jsonSerialize(),
                'readonly' => !$this->domainUserService->currentUserCanPublishToWorkspace($workspace)
            ];
            $workspacesArray[$workspace->workspaceName->jsonSerialize()] = $workspaceArray;
        }

        return $workspacesArray;
    }

    /** @return list<RemoveNode> */
    public function predictRemoveNodeFeedbackFromDiscardIndividualNodesFromWorkspaceCommand(
        DiscardIndividualNodesFromWorkspace $command,
        ContentRepository $contentRepository
    ): array {
        $workspace = $contentRepository->getWorkspaceFinder()->findOneByName($command->workspaceName);
        if (is_null($workspace)) {
            return [];
        }

        $changeFinder = $contentRepository->projectionState(ChangeFinder::class);
        $changes = $changeFinder->findByContentStreamId($workspace->currentContentStreamId);

        $handledNodes = [];
        $result = [];
        foreach ($changes as $change) {
            if ($change->created) {
                foreach ($command->nodesToDiscard as $nodeToDiscard) {
                    if (in_array($nodeToDiscard, $handledNodes)) {
                        continue;
                    }

                    if (
                        $nodeToDiscard->contentStreamId->equals($change->contentStreamId)
                        && $nodeToDiscard->nodeAggregateId->equals($change->nodeAggregateId)
                        && $nodeToDiscard->dimensionSpacePoint->equals($change->originDimensionSpacePoint)
                    ) {
                        $subgraph = $contentRepository->getContentGraph()
                            ->getSubgraph(
                                $nodeToDiscard->contentStreamId,
                                $nodeToDiscard->dimensionSpacePoint,
                                VisibilityConstraints::withoutRestrictions()
                            );

                        $childNode = $subgraph->findNodeById($nodeToDiscard->nodeAggregateId);
                        $parentNode = $subgraph->findParentNode($nodeToDiscard->nodeAggregateId);
                        if ($childNode && $parentNode) {
                            $result[] = new RemoveNode($childNode, $parentNode);
                            $handledNodes[] = $nodeToDiscard;
                        }
                    }
                }
            }
        }

        return $result;
    }
}
