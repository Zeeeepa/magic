<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\SuperMagic\Application\SuperAgent\Service;

use App\Domain\Contact\Entity\MagicUserEntity;
use App\Domain\Contact\Entity\ValueObject\DataIsolation;
use App\Domain\Contact\Service\MagicDepartmentUserDomainService;
use App\Domain\Contact\Service\MagicUserDomainService;
use App\Domain\ModelGateway\Entity\ValueObject\AccessTokenType;
use App\Domain\ModelGateway\Service\AccessTokenDomainService;
use App\Infrastructure\Core\Exception\BusinessException;
use App\Infrastructure\Core\Exception\EventException;
use App\Infrastructure\Core\Exception\ExceptionBuilder;
use Dtyq\AsyncEvent\AsyncEventUtil;
use Dtyq\SuperMagic\Application\SuperAgent\DTO\TaskMessageDTO;
use Dtyq\SuperMagic\Application\SuperAgent\DTO\UserMessageDTO;
use Dtyq\SuperMagic\Domain\SuperAgent\Entity\TaskEntity;
use Dtyq\SuperMagic\Domain\SuperAgent\Entity\TaskMessageEntity;
use Dtyq\SuperMagic\Domain\SuperAgent\Entity\TopicEntity;
use Dtyq\SuperMagic\Domain\SuperAgent\Entity\ValueObject\ChatInstruction;
use Dtyq\SuperMagic\Domain\SuperAgent\Entity\ValueObject\TaskContext;
use Dtyq\SuperMagic\Domain\SuperAgent\Entity\ValueObject\TaskStatus;
use Dtyq\SuperMagic\Domain\SuperAgent\Event\RunTaskBeforeEvent;
use Dtyq\SuperMagic\Domain\SuperAgent\Service\AgentDomainService;
use Dtyq\SuperMagic\Domain\SuperAgent\Service\TaskDomainService;
use Dtyq\SuperMagic\Domain\SuperAgent\Service\TaskFileDomainService;
use Dtyq\SuperMagic\Domain\SuperAgent\Service\TopicDomainService;
use Dtyq\SuperMagic\ErrorCode\SuperAgentErrorCode;
use Dtyq\SuperMagic\Infrastructure\Utils\WorkDirectoryUtil;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Odin\Message\Role;
use Psr\Log\LoggerInterface;
use Throwable;

use function Hyperf\Translation\trans;

/**
 * Handle User Message Application Service
 * Responsible for handling the complete business process of users sending messages to agents.
 */
class HandleApiMessageAppService extends AbstractAppService
{
    protected LoggerInterface $logger;

    public function __construct(
        private readonly TopicDomainService $topicDomainService,
        private readonly TaskDomainService $taskDomainService,
        private readonly MagicDepartmentUserDomainService $departmentUserDomainService,
        private readonly TopicTaskAppService $topicTaskAppService,
        private readonly FileProcessAppService $fileProcessAppService,
        private readonly AgentDomainService $agentDomainService,
        private readonly AccessTokenDomainService $accessTokenDomainService,
        private readonly MagicUserDomainService $userDomainService,
        private readonly TaskFileDomainService $taskFileDomainService,
        LoggerFactory $loggerFactory
    ) {
        $this->logger = $loggerFactory->get(get_class($this));
    }

    /*
    * user send message to agent
    */

    public function handleApiMessage(DataIsolation $dataIsolation, UserMessageDTO $userMessageDTO)
    {
        $topicId = 0;
        $taskId = '';
        try {
            // Get topic information
            $topicEntity = $this->topicDomainService->getTopicByChatTopicId($dataIsolation, $userMessageDTO->getChatTopicId());
            if (is_null($topicEntity)) {
                ExceptionBuilder::throw(SuperAgentErrorCode::TOPIC_NOT_FOUND, 'topic.topic_not_found');
            }
            $topicId = $topicEntity->getId();

            // Check message before task starts
            $this->beforeHandleChatMessage($dataIsolation, $userMessageDTO->getInstruction(), $topicEntity);

            // Get task mode from DTO, fallback to topic's task mode if empty
            $taskMode = $userMessageDTO->getTaskMode();
            if ($taskMode === '') {
                $taskMode = $topicEntity->getTaskMode();
            }
            $data = [
                'user_id' => $dataIsolation->getCurrentUserId(),
                'workspace_id' => $topicEntity->getWorkspaceId(),
                'project_id' => $topicEntity->getProjectId(),
                'topic_id' => $topicId,
                'task_id' => '', // Initially empty, this is agent's task id
                'task_mode' => $taskMode,
                'sandbox_id' => $topicEntity->getSandboxId(), // Current task prioritizes reusing previous topic's sandbox id
                'prompt' => $userMessageDTO->getPrompt(),
                'attachments' => $userMessageDTO->getAttachments(),
                'mentions' => $userMessageDTO->getMentions(),
                'task_status' => TaskStatus::WAITING->value,
                'work_dir' => $topicEntity->getWorkDir() ?? '',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            $taskEntity = TaskEntity::fromArray($data);
            // Initialize task
            $taskEntity = $this->taskDomainService->initTopicTask(
                dataIsolation: $dataIsolation,
                topicEntity: $topicEntity,
                taskEntity: $taskEntity
            );

            $taskId = (string) $taskEntity->getId();

            // Save user information
            $this->saveUserMessage($dataIsolation, $taskEntity, $userMessageDTO);

            // Send message to agent
            $taskContext = new TaskContext(
                task: $taskEntity,
                dataIsolation: $dataIsolation,
                chatConversationId: $userMessageDTO->getChatConversationId(),
                chatTopicId: $userMessageDTO->getChatTopicId(),
                agentUserId: $userMessageDTO->getAgentUserId(),
                sandboxId: $topicEntity->getSandboxId(),
                taskId: (string) $taskEntity->getId(),
                instruction: ChatInstruction::FollowUp,
                agentMode: $userMessageDTO->getTopicMode()->value,
            );
            $sandboxID = $this->createAndSendMessageToAgent($dataIsolation, $taskContext);
            $taskEntity->setSandboxId($sandboxID);

            // Update task status
            $this->topicTaskAppService->updateTaskStatus(
                dataIsolation: $dataIsolation,
                task: $taskEntity,
                status: TaskStatus::RUNNING
            );
        } catch (EventException $e) {
            $this->logger->error(sprintf(
                'Initialize task, event processing failed: %s',
                $e->getMessage()
            ));
            // Send error message directly to client
            // $this->clientMessageAppService->sendErrorMessageToClient(
            //     topicId: $topicId,
            //     taskId: $taskId,
            //     chatTopicId: $userMessageDTO->getChatTopicId(),
            //     chatConversationId: $userMessageDTO->getChatConversationId(),
            //     errorMessage: $e->getMessage()
            // );
            throw new BusinessException('Initialize task, event processing failed', 500);
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'handleChatMessage Error: %s, User: %s file: %s line: %s trace: %s',
                $e->getMessage(),
                $dataIsolation->getCurrentUserId(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString()
            ));
            // Send error message directly to client
            // $this->clientMessageAppService->sendErrorMessageToClient(
            //     topicId: $topicId,
            //     taskId: $taskId,
            //     chatTopicId: $userMessageDTO->getChatTopicId(),
            //     chatConversationId: $userMessageDTO->getChatConversationId(),
            //     errorMessage: trans('agent.initialize_error')
            // );
            throw new BusinessException('Initialize task failed', 500);
        }
    }

    public function handleAgentTask(DataIsolation $dataIsolation, UserMessageDTO $userMessageDTO): array
    {
        $topicId = 0;
        $taskId = '';
        try {
            // Get topic information
            $topicEntity = $this->topicDomainService->getTopicByChatTopicId($dataIsolation, $userMessageDTO->getChatTopicId());
            if (is_null($topicEntity)) {
                ExceptionBuilder::throw(SuperAgentErrorCode::TOPIC_NOT_FOUND, 'topic.topic_not_found');
            }
            $topicId = $topicEntity->getId();

            // Check message before task starts
            $this->beforeHandleChatMessage($dataIsolation, $userMessageDTO->getInstruction(), $topicEntity);

            // Get task mode from DTO, fallback to topic's task mode if empty
            $taskMode = $userMessageDTO->getTaskMode();
            if ($taskMode === '') {
                $taskMode = $topicEntity->getTaskMode();
            }

            $data = [
                'user_id' => $dataIsolation->getCurrentUserId(),
                'workspace_id' => $topicEntity->getWorkspaceId(),
                'project_id' => $topicEntity->getProjectId(),
                'topic_id' => $topicId,
                'task_id' => '', // Initially empty, this is agent's task id
                'task_mode' => $taskMode,
                'sandbox_id' => $topicEntity->getSandboxId(), // Current task prioritizes reusing previous topic's sandbox id
                'prompt' => $userMessageDTO->getPrompt(),
                'attachments' => $userMessageDTO->getAttachments(),
                'mentions' => $userMessageDTO->getMentions(),
                'task_status' => TaskStatus::WAITING->value,
                'work_dir' => $topicEntity->getWorkDir() ?? '',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            $taskEntity = TaskEntity::fromArray($data);
            // Initialize task
            $taskEntity = $this->taskDomainService->initTopicTask(
                dataIsolation: $dataIsolation,
                topicEntity: $topicEntity,
                taskEntity: $taskEntity
            );

            $taskId = (string) $taskEntity->getId();

            // Save user information
            $this->saveUserMessage($dataIsolation, $taskEntity, $userMessageDTO);

            // Send message to agent
            $taskContext = new TaskContext(
                task: $taskEntity,
                dataIsolation: $dataIsolation,
                chatConversationId: $userMessageDTO->getChatConversationId(),
                chatTopicId: $userMessageDTO->getChatTopicId(),
                agentUserId: $userMessageDTO->getAgentUserId(),
                sandboxId: $topicEntity->getSandboxId(),
                taskId: (string) $taskEntity->getId(),
                instruction: ChatInstruction::FollowUp,
                agentMode: $userMessageDTO->getTopicMode()->value,
            );
            $sandboxID = $this->createAndSendMessageToAgent($dataIsolation, $taskContext);
            $taskEntity->setSandboxId($sandboxID);

            // Update task status
            $this->topicTaskAppService->updateTaskStatus(
                dataIsolation: $dataIsolation,
                task: $taskEntity,
                status: TaskStatus::RUNNING
            );

            return ['sandbox_id' => $sandboxID, 'task_id' => $taskId];
        } catch (EventException $e) {
            $this->logger->error(sprintf(
                'Initialize task, event processing failed: %s',
                $e->getMessage()
            ));
            // Send error message directly to client
            // $this->clientMessageAppService->sendErrorMessageToClient(
            //     topicId: $topicId,
            //     taskId: $taskId,
            //     chatTopicId: $userMessageDTO->getChatTopicId(),
            //     chatConversationId: $userMessageDTO->getChatConversationId(),
            //     errorMessage: $e->getMessage()
            // );
            throw new BusinessException('Initialize task, event processing failed', 500);
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'handleChatMessage Error: %s, User: %s file: %s line: %s trace: %s',
                $e->getMessage(),
                $dataIsolation->getCurrentUserId(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString()
            ));
            // Send error message directly to client
            // $this->clientMessageAppService->sendErrorMessageToClient(
            //     topicId: $topicId,
            //     taskId: $taskId,
            //     chatTopicId: $userMessageDTO->getChatTopicId(),
            //     chatConversationId: $userMessageDTO->getChatConversationId(),
            //     errorMessage: trans('agent.initialize_error')
            // );
            throw new BusinessException('Initialize task failed', 500);
        }
    }

    public function getUserAuthorization(string $apiKey, string $uid = ''): MagicUserEntity
    {
        $accessToken = $this->accessTokenDomainService->getByAccessToken($apiKey);
        if (empty($accessToken)) {
            ExceptionBuilder::throw(SuperAgentErrorCode::ACCESS_TOKEN_NOT_FOUND, 'Access token not found');
        }

        if (empty($uid)) {
            if ($accessToken->getType() === AccessTokenType::Application->value) {
                $uid = $accessToken->getCreator();
            } else {
                $uid = $accessToken->getRelationId();
            }
        }

        return $this->userDomainService->getByUserId($uid);
    }

    /**
     * Pre-task detection.
     */
    private function beforeHandleChatMessage(DataIsolation $dataIsolation, ChatInstruction $instruction, TopicEntity $topicEntity): void
    {
        // get the current task run count
        $currentTaskRunCount = $this->pullUserTopicStatus($dataIsolation);
        $taskRound = $this->taskDomainService->getTaskNumByTopicId($topicEntity->getId());
        // get department ids
        $departmentIds = [];
        $departmentUserEntities = $this->departmentUserDomainService->getDepartmentUsersByUserIds([$dataIsolation->getCurrentUserId()], $dataIsolation);
        foreach ($departmentUserEntities as $departmentUserEntity) {
            $departmentIds[] = $departmentUserEntity->getDepartmentId();
        }
        AsyncEventUtil::dispatch(new RunTaskBeforeEvent($dataIsolation->getCurrentOrganizationCode(), $dataIsolation->getCurrentUserId(), $topicEntity->getId(), $taskRound, $currentTaskRunCount, $departmentIds));
        $this->logger->info(sprintf('Dispatched task start event, topic id: %s, round: %d, currentTaskRunCount: %d (after real status check)', $topicEntity->getId(), $taskRound, $currentTaskRunCount));
    }

    /**
     * Update topics and tasks by pulling sandbox status.
     */
    private function pullUserTopicStatus(DataIsolation $dataIsolation): int
    {
        // Get user's running tasks
        $topicEntities = $this->topicDomainService->getUserRunningTopics($dataIsolation);
        // Get sandbox IDs
        $sandboxIds = [];
        foreach ($topicEntities as $topicEntityItem) {
            $sandboxId = $topicEntityItem->getSandboxId();
            if ($sandboxId === '') {
                continue;
            }
            $sandboxIds[] = $sandboxId;
        }
        // Batch query status
        $result = $this->agentDomainService->getBatchSandboxStatus($sandboxIds);

        // Get running sandbox IDs from remote result
        $runningSandboxIds = $result->getRunningSandboxIds();

        // Find sandbox IDs that are not running (including missing ones)
        $updateSandboxIds = array_diff($sandboxIds, $runningSandboxIds);
        // Update topic status
        $this->topicDomainService->updateTopicStatusBySandboxIds($updateSandboxIds, TaskStatus::Suspended);
        // Update task status
        $this->taskDomainService->updateTaskStatusBySandboxIds($updateSandboxIds, TaskStatus::Suspended, 'Synchronize sandbox status');

        $initialRunningCount = count($topicEntities);
        $suspendedCount = count($updateSandboxIds); // Number of tasks to suspend
        return $initialRunningCount - $suspendedCount; // Number of tasks actually running
    }

    /**
     * Initialize agent environment.
     */
    private function createAndSendMessageToAgent(DataIsolation $dataIsolation, TaskContext $taskContext): string
    {
        // Create sandbox container
        $fullPrefix = $this->taskFileDomainService->getFullPrefix($dataIsolation->getCurrentOrganizationCode());
        $fullWorkdir = WorkDirectoryUtil::getFullWorkdir($fullPrefix, $taskContext->getTask()->getWorkDir());

        if (empty($taskContext->getSandboxId())) {
            $sandboxId = (string) $taskContext->getTopicId();
        } else {
            $sandboxId = $taskContext->getSandboxId();
        }
        $sandboxId = $this->agentDomainService->createSandbox($dataIsolation, (string) $taskContext->getProjectId(), $sandboxId, $fullWorkdir);

        // Initialize agent
        $this->agentDomainService->initializeAgent($dataIsolation, $taskContext);

        // Wait for workspace to be ready
        $this->agentDomainService->waitForWorkspaceReady($taskContext->getSandboxId());

        // Send message to agent
        //  $this->agentDomainService->sendChatMessage($dataIsolation, $taskContext);

        // Send message to agent
        return $sandboxId;
    }

    /**
     * Save user information and corresponding attachments.
     */
    private function saveUserMessage(DataIsolation $dataIsolation, TaskEntity $taskEntity, UserMessageDTO $userMessageDTO): void
    {
        // Convert mentions string to array if not null
        $mentionsArray = $userMessageDTO->getMentions() !== null ? json_decode($userMessageDTO->getMentions(), true) : null;

        // Convert attachments string to array if not null
        $attachmentsArray = $userMessageDTO->getAttachments() !== null ? json_decode($userMessageDTO->getAttachments(), true) : null;

        // Create TaskMessageDTO for user message
        $taskMessageDTO = new TaskMessageDTO(
            taskId: (string) $taskEntity->getId(),
            role: Role::User->value,
            senderUid: $dataIsolation->getCurrentUserId(),
            receiverUid: $userMessageDTO->getAgentUserId(),
            messageType: 'chat',
            content: $taskEntity->getPrompt(),
            status: null,
            steps: null,
            tool: null,
            topicId: $taskEntity->getTopicId(),
            event: '',
            attachments: $attachmentsArray,
            mentions: $mentionsArray,
            showInUi: true,
            messageId: null
        );

        $taskMessageEntity = TaskMessageEntity::taskMessageDTOToTaskMessageEntity($taskMessageDTO);

        $this->taskDomainService->recordTaskMessage($taskMessageEntity);

        // Process user uploaded attachments
        $attachmentsStr = $userMessageDTO->getAttachments();
        $this->fileProcessAppService->processInitialAttachments($attachmentsStr, $taskEntity, $dataIsolation);
    }
}
