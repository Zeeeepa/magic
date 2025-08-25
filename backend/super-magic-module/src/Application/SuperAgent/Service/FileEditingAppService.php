<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\SuperMagic\Application\SuperAgent\Service;

use App\Infrastructure\Util\Context\RequestContext;
use Dtyq\SuperMagic\Domain\SuperAgent\Service\FileEditingDomainService;
use Dtyq\SuperMagic\Domain\SuperAgent\Service\TaskFileDomainService;

/**
 * 文件编辑状态应用服务
 */
class FileEditingAppService extends AbstractAppService
{
    public function __construct(
        private readonly FileEditingDomainService $fileEditingDomainService,
        private readonly TaskFileDomainService $taskFileDomainService,
    ) {
    }

    /**
     * 加入编辑.
     */
    public function joinEditing(RequestContext $requestContext, int $fileId): void
    {
        $userAuthorization = $requestContext->getUserAuthorization();
        $dataIsolation = $this->createDataIsolation($userAuthorization);

        // 权限检查
        $fileEntity = $this->taskFileDomainService->getUserFileEntityNoUser($fileId);
        $this->getAccessibleProject($fileEntity->getProjectId(), $userAuthorization->getId(), $userAuthorization->getOrganizationCode());

        // 委托Domain层处理业务逻辑
        $this->fileEditingDomainService->joinEditing($fileId, $userAuthorization->getId(), $userAuthorization->getOrganizationCode());
    }

    /**
     * 离开编辑.
     */
    public function leaveEditing(RequestContext $requestContext, int $fileId): void
    {
        $userAuthorization = $requestContext->getUserAuthorization();
        $dataIsolation = $this->createDataIsolation($userAuthorization);

        // 权限检查
        $fileEntity = $this->taskFileDomainService->getUserFileEntityNoUser($fileId);
        $this->getAccessibleProject($fileEntity->getProjectId(), $userAuthorization->getId(), $userAuthorization->getOrganizationCode());

        // 委托Domain层处理业务逻辑
        $this->fileEditingDomainService->leaveEditing($fileId, $userAuthorization->getId(), $userAuthorization->getOrganizationCode());
    }

    /**
     * 获取编辑用户数量.
     */
    public function getEditingUsers(RequestContext $requestContext, int $fileId): int
    {
        $userAuthorization = $requestContext->getUserAuthorization();
        $dataIsolation = $this->createDataIsolation($userAuthorization);

        // 权限检查
        $fileEntity = $this->taskFileDomainService->getUserFileEntityNoUser($fileId);
        $this->getAccessibleProject($fileEntity->getProjectId(), $userAuthorization->getId(), $userAuthorization->getOrganizationCode());

        // 委托Domain层查询编辑用户数量
        return $this->fileEditingDomainService->getEditingUsersCount($fileId, $userAuthorization->getOrganizationCode());
    }
}
