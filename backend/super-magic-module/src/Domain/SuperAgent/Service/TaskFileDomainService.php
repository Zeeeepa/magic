<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\SuperMagic\Domain\SuperAgent\Service;

use App\Domain\Contact\Entity\ValueObject\DataIsolation;
use App\Domain\File\Repository\Persistence\Facade\CloudFileRepositoryInterface;
use App\ErrorCode\GenericErrorCode;
use App\Infrastructure\Core\Exception\ExceptionBuilder;
use App\Infrastructure\Core\ValueObject\StorageBucketType;
use App\Infrastructure\Util\IdGenerator\IdGenerator;
use Dtyq\SuperMagic\Domain\SuperAgent\Entity\ProjectEntity;
use Dtyq\SuperMagic\Domain\SuperAgent\Entity\TaskFileEntity;
use Dtyq\SuperMagic\Domain\SuperAgent\Entity\ValueObject\FileType;
use Dtyq\SuperMagic\Domain\SuperAgent\Entity\ValueObject\MessageMetadata;
use Dtyq\SuperMagic\Domain\SuperAgent\Entity\ValueObject\SandboxFileNotificationDataValueObject;
use Dtyq\SuperMagic\Domain\SuperAgent\Entity\ValueObject\StorageType;
use Dtyq\SuperMagic\Domain\SuperAgent\Entity\ValueObject\TaskFileSource;
use Dtyq\SuperMagic\Domain\SuperAgent\Repository\Facade\TaskFileRepositoryInterface;
use Dtyq\SuperMagic\Domain\SuperAgent\Repository\Facade\TaskRepositoryInterface;
use Dtyq\SuperMagic\Domain\SuperAgent\Repository\Facade\TopicRepositoryInterface;
use Dtyq\SuperMagic\Domain\SuperAgent\Repository\Facade\WorkspaceVersionRepositoryInterface;
use Dtyq\SuperMagic\ErrorCode\SuperAgentErrorCode;
use Dtyq\SuperMagic\Infrastructure\Utils\AccessTokenUtil;
use Dtyq\SuperMagic\Infrastructure\Utils\ContentTypeUtil;
use Dtyq\SuperMagic\Infrastructure\Utils\FileSortUtil;
use Dtyq\SuperMagic\Infrastructure\Utils\WorkDirectoryUtil;
use Hyperf\DbConnection\Db;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use Throwable;

use function Hyperf\Translation\trans;

class TaskFileDomainService
{
    private readonly LoggerInterface $logger;

    public function __construct(
        protected TaskRepositoryInterface $taskRepository,
        protected TaskFileRepositoryInterface $taskFileRepository,
        protected WorkspaceVersionRepositoryInterface $workspaceVersionRepository,
        protected TopicRepositoryInterface $topicRepository,
        protected CloudFileRepositoryInterface $cloudFileRepository,
        LoggerFactory $loggerFactory
    ) {
        $this->logger = $loggerFactory->get(get_class($this));
    }

    public function getProjectFilesFromCloudStorage(string $organizationCode, string $workDir): array
    {
        return $this->cloudFileRepository->listObjectsByCredential(
            $organizationCode,
            $workDir,
            StorageBucketType::SandBox,
        );
    }

    /**
     * Get file by ID.
     */
    public function getById(int $id): ?TaskFileEntity
    {
        return $this->taskFileRepository->getById($id);
    }

    /**
     * Get file by file key.
     */
    public function getByFileKey(string $fileKey): ?TaskFileEntity
    {
        return $this->taskFileRepository->getByFileKey($fileKey);
    }

    /**
     * Get file by project ID and file key.
     */
    public function getByProjectIdAndFileKey(int $projectId, string $fileKey): ?TaskFileEntity
    {
        return $this->taskFileRepository->getByProjectIdAndFileKey($projectId, $fileKey);
    }

    /**
     * Find user files by file IDs and user ID.
     *
     * @param array $fileIds File ID array
     * @param string $userId User ID
     * @return TaskFileEntity[] User file list
     */
    public function findUserFilesByIds(array $fileIds, string $userId): array
    {
        return $this->taskFileRepository->findUserFilesByIds($fileIds, $userId);
    }

    /**
     * @return TaskFileEntity[] User file list
     */
    public function findUserFilesByTopicId(string $topicId): array
    {
        return $this->taskFileRepository->findUserFilesByTopicId($topicId);
    }

    public function findUserFilesByProjectId(string $projectId): array
    {
        return $this->taskFileRepository->findUserFilesByProjectId($projectId);
    }

    /**
     * Get the latest updated file by project ID.
     */
    public function getLatestUpdatedByProjectId(int $projectId): string
    {
        $lastUpdatedTime = null;

        // 获取文件最新更新的时间
        $lastFileEntity = $this->taskFileRepository->findLatestUpdatedByProjectId($projectId);
        if ($lastFileEntity) {
            $lastUpdatedTime = $lastFileEntity->getUpdatedAt();
        }

        // 获取版本更新时间
        $lastVersionEntity = $this->workspaceVersionRepository->getLatestUpdateVersionProjectId($projectId);
        if ($lastVersionEntity) {
            $versionUpdatedTime = $lastVersionEntity->getUpdatedAt();

            // 使用 strtotime 进行更安全的时间比较
            if ($lastUpdatedTime === null || strtotime($versionUpdatedTime) > strtotime($lastUpdatedTime)) {
                $lastUpdatedTime = $versionUpdatedTime;
            }
        }

        // 如果两个时间都为空，返回空字符串；否则返回最新时间
        return $lastUpdatedTime ?? '';
    }

    /**
     * Get file list by topic ID.
     *
     * @param int $topicId Topic ID
     * @param int $page Page number
     * @param int $pageSize Page size
     * @param array $fileType File type filter
     * @param string $storageType Storage type
     * @return array{list: TaskFileEntity[], total: int} File list and total count
     */
    public function getByTopicId(int $topicId, int $page, int $pageSize, array $fileType = [], string $storageType = 'workspace'): array
    {
        return $this->taskFileRepository->getByTopicId($topicId, $page, $pageSize, $fileType, $storageType);
    }

    /**
     * Get file list by task ID.
     *
     * @param int $taskId Task ID
     * @param int $page Page number
     * @param int $pageSize Page size
     * @return array{list: TaskFileEntity[], total: int} File list and total count
     */
    public function getByTaskId(int $taskId, int $page, int $pageSize): array
    {
        return $this->taskFileRepository->getByTaskId($taskId, $page, $pageSize);
    }

    /**
     * Insert file.
     */
    public function insert(TaskFileEntity $entity): TaskFileEntity
    {
        return $this->taskFileRepository->insert($entity);
    }

    /**
     * Insert file or ignore if conflict.
     */
    public function insertOrIgnore(TaskFileEntity $entity): ?TaskFileEntity
    {
        return $this->taskFileRepository->insertOrIgnore($entity);
    }

    /**
     * Update file by ID.
     */
    public function updateById(TaskFileEntity $entity): TaskFileEntity
    {
        return $this->taskFileRepository->updateById($entity);
    }

    /**
     * Delete file by ID.
     */
    public function deleteById(int $id): void
    {
        $this->taskFileRepository->deleteById($id);
    }

    /**
     * 根据文件key和topicId获取相对于工作目录的文件路径。
     * 逻辑参考 AgentFileAppService::getFileVersions 方法。
     *
     * @param string $fileKey 完整的文件key（包含 workDir 前缀）
     * @param int $topicId 话题 ID
     *
     * @return string 相对于 workDir 的文件路径（当未匹配到 workDir 时返回原始 $fileKey）
     */
    public function getFileWorkspacePath(string $fileKey, int $topicId): string
    {
        // 通过仓储直接获取话题，避免领域服务之间的依赖
        $topicEntity = $this->topicRepository->getTopicById($topicId);

        // 若话题不存在或 workDir 为空，直接返回原始 fileKey
        if (empty($topicEntity) || empty($topicEntity->getWorkDir())) {
            return $fileKey;
        }

        $workDir = rtrim($topicEntity->getWorkDir(), '/') . '/';

        // 使用 workDir 在 fileKey 中找到最后一次出现的位置，截取其后内容
        $pos = strrpos($fileKey, $workDir);
        if ($pos === false) {
            // 未找到 workDir，返回原始 fileKey
            return $fileKey;
        }

        return substr($fileKey, $pos + strlen($workDir));
    }

    /**
     * Bind files to project with proper parent directory setup.
     *
     * Note: This method assumes all files are in the same directory level.
     * It uses the first file's path to determine the parent directory for all files.
     * If files are from different directories, they will all be placed in the same parent directory.
     *
     * @param DataIsolation $dataIsolation Data isolation context for permission check
     * @param int $projectId Project ID to bind files to
     * @param array $fileIds Array of file IDs to bind
     * @param string $workDir Project work directory
     * @return bool Whether binding was successful
     */
    public function bindProjectFiles(
        DataIsolation $dataIsolation,
        int $projectId,
        array $fileIds,
        string $workDir
    ): bool {
        if (empty($fileIds)) {
            return true;
        }

        // 1. Permission check: only query files belonging to current user
        $fileEntities = $this->taskFileRepository->findUserFilesByIds(
            $fileIds,
            $dataIsolation->getCurrentUserId()
        );

        if (empty($fileEntities)) {
            ExceptionBuilder::throw(
                SuperAgentErrorCode::FILE_NOT_FOUND,
                trans('file.files_not_found_or_no_permission')
            );
        }

        // 2. Find or create project root directory as parent directory
        $parentId = $this->findOrCreateDirectoryAndGetParentId(
            projectId: $projectId,
            userId: $dataIsolation->getCurrentUserId(),
            organizationCode: $dataIsolation->getCurrentOrganizationCode(),
            fullFileKey: $fileEntities[0]->getFileKey(),
            workDir: $workDir,
        );

        // 3. Filter unbound files and prepare for batch update
        $unboundFileIds = [];
        foreach ($fileEntities as $fileEntity) {
            if ($fileEntity->getProjectId() <= 0) {
                $unboundFileIds[] = $fileEntity->getFileId();
            }
        }

        if (empty($unboundFileIds)) {
            return true; // All files already bound, no operation needed
        }

        // 4. Batch update: set both project_id and parent_id atomically
        $this->taskFileRepository->batchBindToProject(
            $unboundFileIds,
            $projectId,
            $parentId
        );

        return true;
    }

    /**
     * Save project file.
     *
     * @param DataIsolation $dataIsolation Data isolation context
     * @param TaskFileEntity $taskFileEntity Task file entity with data to save
     * @return TaskFileEntity Saved file entity
     */
    public function saveProjectFile(
        DataIsolation $dataIsolation,
        TaskFileEntity $taskFileEntity
    ): TaskFileEntity {
        // Check if file already exists by project_id and file_key
        if ($taskFileEntity->getProjectId() > 0 && ! empty($taskFileEntity->getFileKey())) {
            $existingFile = $this->taskFileRepository->getByFileKey($taskFileEntity->getFileKey());
            if ($existingFile !== null) {
                return $existingFile;
            }
        }

        // Set data isolation context
        $taskFileEntity->setUserId($dataIsolation->getCurrentUserId());
        $taskFileEntity->setOrganizationCode($dataIsolation->getCurrentOrganizationCode());

        // Generate file ID if not set
        if ($taskFileEntity->getFileId() === 0) {
            $taskFileEntity->setFileId(IdGenerator::getSnowId());
        }

        // Extract file extension from file name if not set
        if (empty($taskFileEntity->getFileExtension()) && ! empty($taskFileEntity->getFileName())) {
            $fileExtension = pathinfo($taskFileEntity->getFileName(), PATHINFO_EXTENSION);
            $taskFileEntity->setFileExtension($fileExtension);
        }

        // Set timestamps
        $now = date('Y-m-d H:i:s');
        $taskFileEntity->setCreatedAt($now);
        $taskFileEntity->setUpdatedAt($now);

        // Save to repository
        $this->insert($taskFileEntity);

        return $taskFileEntity;
    }

    /**
     * Create project file or folder.
     *
     * @param DataIsolation $dataIsolation Data isolation context
     * @param ProjectEntity $projectEntity Project entity
     * @param int $parentId Parent file ID (0 for root)
     * @param string $fileName File name
     * @param bool $isDirectory Whether it's a directory
     * @return TaskFileEntity Created file entity
     */
    public function createProjectFile(
        DataIsolation $dataIsolation,
        ProjectEntity $projectEntity,
        int $parentId,
        string $fileName,
        bool $isDirectory,
        int $sortValue = 0
    ): TaskFileEntity {
        $organizationCode = $dataIsolation->getCurrentOrganizationCode();
        $workDir = $projectEntity->getWorkDir();

        if (empty($workDir)) {
            ExceptionBuilder::throw(SuperAgentErrorCode::WORK_DIR_NOT_FOUND, trans('project.work_dir.not_found'));
        }

        $fullPrefix = $this->getFullPrefix($organizationCode);
        if (! empty($parentId)) {
            $parentFIleEntity = $this->taskFileRepository->getById($parentId);
            if ($parentFIleEntity === null || $parentFIleEntity->getProjectId() != $projectEntity->getId()) {
                ExceptionBuilder::throw(SuperAgentErrorCode::FILE_NOT_FOUND, trans('file.file_not_found'));
            }
            $fileKey = rtrim($parentFIleEntity->getFileKey(), '/') . '/' . $fileName;
        } else {
            $fileKey = WorkDirectoryUtil::getFullFileKey($fullPrefix, $workDir, $fileName);
        }

        if ($isDirectory) {
            $fileKey = rtrim($fileKey, '/') . '/';
        }

        $fullWorkdir = WorkDirectoryUtil::getFullWorkdir($fullPrefix, $workDir);
        if (! WorkDirectoryUtil::checkEffectiveFileKey($fullWorkdir, $fileKey)) {
            ExceptionBuilder::throw(SuperAgentErrorCode::FILE_ILLEGAL_KEY, trans('file.illegal_file_key'));
        }

        // Check if file already exists
        $existingFile = $this->taskFileRepository->getByFileKey($fileKey);
        if ($existingFile !== null) {
            ExceptionBuilder::throw(SuperAgentErrorCode::FILE_EXIST, trans('file.file_exist'));
        }

        Db::beginTransaction();
        try {
            // Create object in cloud storage
            if ($isDirectory) {
                $this->cloudFileRepository->createFolderByCredential(WorkDirectoryUtil::getPrefix($workDir), $organizationCode, $fileKey, StorageBucketType::SandBox);
            } else {
                $this->cloudFileRepository->createFileByCredential(WorkDirectoryUtil::getPrefix($workDir), $organizationCode, $fileKey, '', StorageBucketType::SandBox);
            }

            // Create file entity
            $taskFileEntity = new TaskFileEntity();
            $taskFileEntity->setFileId(IdGenerator::getSnowId());
            $taskFileEntity->setProjectId($projectEntity->getId());
            $taskFileEntity->setFileKey($fileKey);
            $taskFileEntity->setFileName($fileName);
            $taskFileEntity->setFileSize(0); // Empty file/folder initially
            $taskFileEntity->setFileType(FileType::USER_UPLOAD->value);
            $taskFileEntity->setIsDirectory($isDirectory);
            $taskFileEntity->setParentId($parentId === 0 ? null : $parentId);
            $taskFileEntity->setSource(TaskFileSource::PROJECT_DIRECTORY);
            $taskFileEntity->setStorageType(StorageType::WORKSPACE);
            $taskFileEntity->setUserId($dataIsolation->getCurrentUserId());
            $taskFileEntity->setOrganizationCode($organizationCode);
            $taskFileEntity->setIsHidden(false);
            $taskFileEntity->setSort(0);

            // Extract file extension for files
            if (! $isDirectory && ! empty($fileName)) {
                $fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
                $taskFileEntity->setFileExtension($fileExtension);
            }

            // Set timestamps
            $now = date('Y-m-d H:i:s');
            $taskFileEntity->setCreatedAt($now);
            $taskFileEntity->setUpdatedAt($now);

            // Save to database
            $this->insert($taskFileEntity);

            Db::commit();
            return $taskFileEntity;
        } catch (Throwable $e) {
            Db::rollBack();
            throw $e;
        }
    }

    public function deleteProjectFiles(DataIsolation $dataIsolation, TaskFileEntity $fileEntity, string $workDir): bool
    {
        $fullPrefix = $this->getFullPrefix($dataIsolation->getCurrentOrganizationCode());
        $fullWorkdir = WorkDirectoryUtil::getFullWorkdir($fullPrefix, $workDir);
        if (! WorkDirectoryUtil::checkEffectiveFileKey($fullWorkdir, $fileEntity->getFileKey())) {
            ExceptionBuilder::throw(SuperAgentErrorCode::FILE_ILLEGAL_KEY, trans('file.illegal_file_key'));
        }

        // Delete cloud file
        try {
            $prefix = WorkDirectoryUtil::getPrefix($workDir);
            $this->cloudFileRepository->deleteObjectByCredential($prefix, $dataIsolation->getCurrentOrganizationCode(), $fileEntity->getFileKey(), StorageBucketType::SandBox);
        } catch (Throwable $e) {
            $this->logger->warning('Failed to delete cloud file', ['file_key' => $fileEntity->getFileKey(), 'error' => $e->getMessage()]);
        }

        Db::beginTransaction();
        try {
            // Delete file record
            $this->taskFileRepository->deleteById($fileEntity->getFileId());
            // Delete the same file in projects
            $this->taskFileRepository->deleteByFileKeyAndProjectId($fileEntity->getFileKey(), $fileEntity->getProjectId());
            Db::commit();
            return true;
        } catch (Throwable $e) {
            Db::rollBack();
            throw $e;
        }
    }

    public function deleteDirectoryFiles(DataIsolation $dataIsolation, string $workDir, int $projectId, string $targetPath): int
    {
        $organizationCode = $dataIsolation->getCurrentOrganizationCode();

        Db::beginTransaction();
        try {
            // 1. 查找目录下所有文件（限制500条）
            $fileEntities = $this->taskFileRepository->findFilesByDirectoryPath($projectId, $targetPath);

            if (empty($fileEntities)) {
                Db::commit();
                return 0;
            }
            $deletedCount = 0;
            $fullPrefix = $this->getFullPrefix($organizationCode);
            $fullWorkdir = WorkDirectoryUtil::getFullWorkdir($fullPrefix, $workDir);
            $prefix = WorkDirectoryUtil::getPrefix($workDir);

            // 3. 批量删除云存储文件
            $fileKeys = [];
            foreach ($fileEntities as $fileEntity) {
                if (WorkDirectoryUtil::checkEffectiveFileKey($fullWorkdir, $fileEntity->getFileKey())) {
                    $fileKeys[] = $fileEntity->getFileKey();
                }
            }

            // 删除云存储文件（批量操作）
            foreach ($fileKeys as $fileKey) {
                try {
                    $this->cloudFileRepository->deleteObjectByCredential($prefix, $organizationCode, $fileKey, StorageBucketType::SandBox);
                    ++$deletedCount;
                } catch (Throwable $e) {
                    $this->logger->error('Failed to delete cloud file', [
                        'file_key' => $fileKey,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // 4. 批量删除数据库记录
            $fileIds = array_map(fn ($entity) => $entity->getFileId(), $fileEntities);
            // 根据文件ID批量删除数据库记录
            $this->taskFileRepository->deleteByIds($fileIds);

            Db::commit();
            return $deletedCount;
        } catch (Throwable $e) {
            Db::rollBack();
            throw $e;
        }
    }

    public function copyProjectFile(DataIsolation $dataIsolation, TaskFileEntity $fileEntity, string $workDir, string $targetObject)
    {
        try {
            // target file exist
            $organizationCode = $dataIsolation->getCurrentOrganizationCode();
            $fullPrefix = $this->getFullPrefix($organizationCode);
            $fullTargetFileKey = WorkDirectoryUtil::getFullFileKey($fullPrefix, $workDir, $targetObject);

            $targetFileEntity = $this->taskFileRepository->getByFileKey($fullTargetFileKey);
            if ($targetFileEntity !== null) {
                ExceptionBuilder::throw(SuperAgentErrorCode::FILE_EXIST, trans('file.file_exist'));
            }

            // call cloud file service
            $this->cloudFileRepository->copyObjectByCredential($fullPrefix, $organizationCode, $fileEntity->getFileKey(), $fullTargetFileKey, StorageBucketType::SandBox);
        } catch (Throwable $e) {
            throw $e;
        }
    }

    public function renameProjectFile(DataIsolation $dataIsolation, TaskFileEntity $fileEntity, string $workDir, string $targetName): TaskFileEntity
    {
        $dir = dirname($fileEntity->getFileKey());
        $fullTargetFileKey = $dir . DIRECTORY_SEPARATOR . $targetName;
        $targetFileEntity = $this->taskFileRepository->getByFileKey($fullTargetFileKey);
        if ($targetFileEntity !== null) {
            ExceptionBuilder::throw(SuperAgentErrorCode::FILE_EXIST, trans('file.file_exist'));
        }

        $fullWorkdir = WorkDirectoryUtil::getFullWorkdir(
            $this->getFullPrefix($dataIsolation->getCurrentOrganizationCode()),
            $workDir
        );
        if (! WorkDirectoryUtil::checkEffectiveFileKey($fullWorkdir, $fullTargetFileKey)) {
            ExceptionBuilder::throw(SuperAgentErrorCode::FILE_ILLEGAL_KEY, trans('file.illegal_file_key'));
        }

        Db::beginTransaction();
        try {
            $organizationCode = $dataIsolation->getCurrentOrganizationCode();
            $prefix = WorkDirectoryUtil::getPrefix($workDir);
            // call cloud file service
            $this->cloudFileRepository->renameObjectByCredential($prefix, $organizationCode, $fileEntity->getFileKey(), $fullTargetFileKey, StorageBucketType::SandBox);

            // rename file record
            $fileEntity->setFileKey($fullTargetFileKey);
            $fileEntity->setFileName(basename($fullTargetFileKey));
            $fileExtension = pathinfo(basename($fullTargetFileKey), PATHINFO_EXTENSION);
            $fileEntity->setFileExtension($fileExtension);
            $fileEntity->setUpdatedAt(date('Y-m-d H:i:s'));
            $this->taskFileRepository->updateById($fileEntity);

            Db::commit();
            return $fileEntity;
        } catch (Throwable $e) {
            Db::rollBack();
            throw $e;
        }
    }

    public function renameDirectoryFiles(DataIsolation $dataIsolation, TaskFileEntity $dirEntity, string $workDir, string $newDirName): int
    {
        $organizationCode = $dataIsolation->getCurrentOrganizationCode();
        $oldDirKey = $dirEntity->getFileKey();
        $parentDir = dirname($oldDirKey);
        $newDirKey = rtrim($parentDir, '/') . '/' . $newDirName . '/';

        // Check if target directory name already exists
        $targetFileEntity = $this->taskFileRepository->getByFileKey($newDirKey);
        if ($targetFileEntity !== null) {
            ExceptionBuilder::throw(SuperAgentErrorCode::FILE_EXIST, trans('file.file_exist'));
        }

        // Validate new directory key is within work directory
        $fullWorkdir = WorkDirectoryUtil::getFullWorkdir(
            $this->getFullPrefix($organizationCode),
            $workDir
        );
        if (! WorkDirectoryUtil::checkEffectiveFileKey($fullWorkdir, $newDirKey)) {
            ExceptionBuilder::throw(SuperAgentErrorCode::FILE_ILLEGAL_KEY, trans('file.illegal_file_key'));
        }

        Db::beginTransaction();
        try {
            // 1. Find all files in the directory (flat query)
            $fileEntities = $this->taskFileRepository->findFilesByDirectoryPath($dirEntity->getProjectId(), $oldDirKey);

            if (empty($fileEntities)) {
                Db::commit();
                return 0;
            }

            $renamedCount = 0;
            $fullPrefix = $this->getFullPrefix($organizationCode);
            $prefix = WorkDirectoryUtil::getPrefix($workDir);

            // 2. Batch update file keys in database
            foreach ($fileEntities as $fileEntity) {
                if (! WorkDirectoryUtil::checkEffectiveFileKey($fullWorkdir, $fileEntity->getFileKey())) {
                    continue;
                }

                // Calculate new file key by replacing old directory path with new directory path
                $newFileKey = str_replace($oldDirKey, $newDirKey, $fileEntity->getFileKey());
                $oldFileKey = $fileEntity->getFileKey();

                // Update entity
                $fileEntity->setFileKey($newFileKey);
                if ($fileEntity->getFileId() === $dirEntity->getFileId()) {
                    // Update directory name for the main directory entity
                    $fileEntity->setFileName($newDirName);
                }
                $fileEntity->setUpdatedAt(date('Y-m-d H:i:s'));

                // Update in database
                $this->taskFileRepository->updateById($fileEntity);

                // 3. Rename in cloud storage
                try {
                    $this->cloudFileRepository->renameObjectByCredential($prefix, $organizationCode, $oldFileKey, $newFileKey, StorageBucketType::SandBox);
                    ++$renamedCount;
                } catch (Throwable $e) {
                    $this->logger->error('Failed to rename file in cloud storage', [
                        'old_file_key' => $oldFileKey,
                        'new_file_key' => $newFileKey,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Db::commit();
            return $renamedCount;
        } catch (Throwable $e) {
            Db::rollBack();
            throw $e;
        }
    }

    public function moveProjectFile(DataIsolation $dataIsolation, TaskFileEntity $fileEntity, string $workDir, int $targetParentId): void
    {
        if ($targetParentId <= 0) {
            ExceptionBuilder::throw(SuperAgentErrorCode::FILE_NOT_FOUND, trans('file.file_not_found'));
        }

        $targetParentEntity = $this->taskFileRepository->getById($targetParentId);
        if ($targetParentEntity === null) {
            ExceptionBuilder::throw(SuperAgentErrorCode::FILE_NOT_FOUND, trans('file.file_not_found'));
        }

        // Validate target parent is a directory
        if (! $targetParentEntity->getIsDirectory()) {
            ExceptionBuilder::throw(GenericErrorCode::ParameterValidationFailed, trans('file.target_parent_not_directory'));
        }

        // Validate target parent belongs to same project
        if ($targetParentEntity->getProjectId() !== $fileEntity->getProjectId()) {
            ExceptionBuilder::throw(SuperAgentErrorCode::FILE_PERMISSION_DENIED, trans('file.permission_denied'));
        }

        // Validate target parent belongs to same user
        if ($targetParentEntity->getUserId() !== $dataIsolation->getCurrentUserId()) {
            ExceptionBuilder::throw(SuperAgentErrorCode::FILE_PERMISSION_DENIED, trans('file.permission_denied'));
        }

        // This method now only handles cross-directory moves
        // Build full target file key
        $targetParentPath = rtrim($targetParentEntity->getFileKey(), '/') . '/' . basename($fileEntity->getFileKey());
        $fullWorkdir = WorkDirectoryUtil::getFullWorkdir(
            $this->getFullPrefix($dataIsolation->getCurrentOrganizationCode()),
            $workDir
        );
        if (! WorkDirectoryUtil::checkEffectiveFileKey($fullWorkdir, $targetParentPath)) {
            ExceptionBuilder::throw(SuperAgentErrorCode::FILE_ILLEGAL_KEY, trans('file.illegal_file_key'));
        }

        // Check if target file already exists
        $existingTargetFile = $this->taskFileRepository->getByFileKey($targetParentPath);
        if (! empty($existingTargetFile)) {
            ExceptionBuilder::throw(SuperAgentErrorCode::FILE_EXIST, trans('file.file_exist'));
        }

        // Prevent moving directory to its subdirectory (for directories)
        if ($fileEntity->getIsDirectory()) {
            // Check if target is a subdirectory of the file being moved
            if ($this->isSubdirectory($fileEntity->getFileId(), $targetParentId)) {
                ExceptionBuilder::throw(GenericErrorCode::ParameterValidationFailed, trans('file.cannot_move_to_subdirectory'));
            }
        }

        Db::beginTransaction();
        try {
            // Call cloud file service to move the file
            $prefix = WorkDirectoryUtil::getPrefix($workDir);
            $this->cloudFileRepository->renameObjectByCredential($prefix, $dataIsolation->getCurrentOrganizationCode(), $fileEntity->getFileKey(), $targetParentPath, StorageBucketType::SandBox);

            // Update file record (parentId and sort have already been set by handleFileSortOnMove)
            $fileEntity->setFileKey($targetParentPath);
            $fileEntity->setUpdatedAt(date('Y-m-d H:i:s'));
            $this->taskFileRepository->updateById($fileEntity);

            Db::commit();
        } catch (Throwable $e) {
            Db::rollBack();
            throw $e;
        }
    }

    public function getUserFileEntity(DataIsolation $dataIsolation, int $fileId): TaskFileEntity
    {
        $fileEntity = $this->taskFileRepository->getById($fileId);
        if ($fileEntity === null) {
            ExceptionBuilder::throw(SuperAgentErrorCode::FILE_NOT_FOUND, trans('file.file_not_found'));
        }

        if ($fileEntity->getUserId() !== $dataIsolation->getCurrentUserId()) {
            ExceptionBuilder::throw(SuperAgentErrorCode::FILE_PERMISSION_DENIED, trans('file.permission_denied'));
        }

        if ($fileEntity->getProjectId() <= 0) {
            ExceptionBuilder::throw(SuperAgentErrorCode::PROJECT_NOT_FOUND, trans('project.project_not_found'));
        }

        return $fileEntity;
    }

    /**
     * 为新文件计算排序值（领域逻辑）.
     */
    public function calculateSortForNewFile(?int $parentId, int $preFileId, int $projectId): int
    {
        // Use FileSortUtil for consistent sorting logic
        return FileSortUtil::calculateSortValue($this->taskFileRepository, $parentId, $preFileId, $projectId);
    }

    /**
     * 处理移动文件时的排序（领域协调）.
     */
    public function handleFileSortOnMove(
        TaskFileEntity $fileEntity,
        int $targetParentId,
        int $preFileId
    ): void {
        $newParentId = $targetParentId === 0 ? null : $targetParentId;

        // 计算新的排序值
        $newSort = $this->calculateSortForNewFile(
            $newParentId,
            $preFileId,
            $fileEntity->getProjectId()
        );

        // 更新实体
        $fileEntity->setSort($newSort);
        $fileEntity->setParentId($newParentId);
    }

    /**
     * Find or create directory structure and return parent ID for a file.
     * This method ensures all necessary directories exist for the given file path.
     *
     * @param int $projectId Project ID
     * @param string $fullFileKey Complete file key from storage
     * @param string $workDir Project work directory
     * @return int The file_id of the direct parent directory
     */
    public function findOrCreateDirectoryAndGetParentId(int $projectId, string $userId, string $organizationCode, string $fullFileKey, string $workDir): int
    {
        // 1. Get relative path of the file
        $relativePath = WorkDirectoryUtil::getRelativeFilePath($fullFileKey, $workDir);

        // 2. Get parent directory path
        $parentDirPath = dirname($relativePath);

        // 3. If file is in root directory, return project root directory ID
        if ($parentDirPath === '.' || $parentDirPath === '/' || empty($parentDirPath)) {
            return $this->findOrCreateProjectRootDirectory($projectId, $workDir, $userId, $organizationCode);
        }

        // 4. Ensure all directory levels exist and return the final parent ID
        return $this->ensureDirectoryPathExists($projectId, $parentDirPath, $workDir, $userId, $organizationCode);
    }

    /**
     * Handle sandbox file notification (CREATE/UPDATE operations).
     *
     * @param DataIsolation $dataIsolation Data isolation context
     * @param ProjectEntity $projectEntity Project entity
     * @param string $fileKey Complete file key
     * @param SandboxFileNotificationDataValueObject $data File data
     * @return TaskFileEntity Created or updated file entity
     */
    public function handleSandboxFileNotification(
        DataIsolation $dataIsolation,
        ProjectEntity $projectEntity,
        string $fileKey,
        SandboxFileNotificationDataValueObject $data,
        MessageMetadata $metadata
    ): TaskFileEntity {
        $organizationCode = $dataIsolation->getCurrentOrganizationCode();
        $userId = $dataIsolation->getCurrentUserId();
        $projectId = $projectEntity->getId();
        $workDir = $projectEntity->getWorkDir();

        Db::beginTransaction();
        try {
            // 1. Get parent directory ID (create directories if needed)
            $parentId = $this->findOrCreateDirectoryAndGetParentId(
                $projectId,
                $userId,
                $organizationCode,
                $fileKey,
                $workDir
            );

            // 2. Check if file already exists
            $existingFile = $this->taskFileRepository->getByFileKey($fileKey);

            if ($existingFile !== null) {
                // Update existing file
                $taskFileEntity = $this->updateSandboxFile($existingFile, $data, $organizationCode);
            } else {
                // Create new file
                $taskFileEntity = $this->createSandboxFile(
                    $dataIsolation,
                    $projectEntity,
                    $fileKey,
                    $parentId,
                    (int) $metadata->getSuperMagicTaskId(),
                    $data
                );
            }

            Db::commit();
            return $taskFileEntity;
        } catch (Throwable $e) {
            Db::rollBack();
            throw $e;
        }
    }

    /**
     * Handle sandbox file delete operation.
     *
     * @param DataIsolation $dataIsolation Data isolation context
     * @param string $fileKey Complete file key
     * @return bool Whether file was deleted
     */
    public function handleSandboxFileDelete(DataIsolation $dataIsolation, string $fileKey): bool
    {
        $existingFile = $this->taskFileRepository->getByFileKey($fileKey);

        if ($existingFile === null) {
            // File doesn't exist, consider it as successfully deleted
            return true;
        }

        // Check permission
        if ($existingFile->getUserId() !== $dataIsolation->getCurrentUserId()) {
            ExceptionBuilder::throw(SuperAgentErrorCode::FILE_PERMISSION_DENIED, trans('file.permission_denied'));
        }

        try {
            $this->taskFileRepository->deleteById($existingFile->getFileId());

            // Delete the same file in projects
            $this->taskFileRepository->deleteByFileKeyAndProjectId($existingFile->getFileKey(), $existingFile->getProjectId());

            return true;
        } catch (Throwable $e) {
            // Log error if needed
            return false;
        }
    }

    /**
     * Find or create project root directory.
     *
     * @param int $projectId Project ID
     * @param string $workDir Project work directory
     * @param string $userId User ID
     * @param string $organizationCode Organization code
     * @return int Root directory file_id
     */
    public function findOrCreateProjectRootDirectory(int $projectId, string $workDir, string $userId, string $organizationCode): int
    {
        // Look for existing root directory (parent_id IS NULL and is_directory = true)
        $rootDir = $this->findDirectoryByParentIdAndName(null, '/', $projectId);

        if ($rootDir !== null) {
            return $rootDir->getFileId();
        }
        $fullPrefix = $this->getFullPrefix($organizationCode);
        $fullWorkDir = WorkDirectoryUtil::getFullWorkdir($fullPrefix, $workDir);
        $fileKey = rtrim($fullWorkDir, '/') . '/';

        // Call remote file system
        $metadata = WorkDirectoryUtil::generateDefaultWorkDirMetadata();
        $this->cloudFileRepository->createFolderByCredential(WorkDirectoryUtil::getPrefix($workDir), $organizationCode, $fileKey, StorageBucketType::SandBox, ['metadata' => $metadata]);

        // Create root directory if not exists
        $rootDirEntity = new TaskFileEntity();
        $rootDirEntity->setFileId(IdGenerator::getSnowId());
        $rootDirEntity->setUserId($userId);
        $rootDirEntity->setOrganizationCode($organizationCode);
        $rootDirEntity->setProjectId($projectId);
        $rootDirEntity->setFileName('/');
        $rootDirEntity->setFileKey($fileKey);
        $rootDirEntity->setFileSize(0);
        $rootDirEntity->setFileType(FileType::DIRECTORY->value);
        $rootDirEntity->setIsDirectory(true);
        $rootDirEntity->setParentId(null);
        $rootDirEntity->setSource(TaskFileSource::PROJECT_DIRECTORY);
        $rootDirEntity->setStorageType(StorageType::WORKSPACE);
        $rootDirEntity->setIsHidden(true);
        $rootDirEntity->setSort(0);

        $now = date('Y-m-d H:i:s');
        $rootDirEntity->setCreatedAt($now);
        $rootDirEntity->setUpdatedAt($now);

        $this->insert($rootDirEntity);

        return $rootDirEntity->getFileId();
    }

    /**
     * Get file URLs for multiple files.
     *
     * @param DataIsolation $dataIsolation Data isolation context
     * @param array $fileIds Array of file IDs
     * @param string $downloadMode Download mode (download, preview, etc.)
     * @param array $options Additional options
     * @return array Array of file URLs
     */
    public function getFileUrls(DataIsolation $dataIsolation, array $fileIds, string $downloadMode, array $options = []): array
    {
        $organizationCode = $dataIsolation->getCurrentOrganizationCode();
        $result = [];

        foreach ($fileIds as $fileId) {
            // 获取文件实体
            $fileEntity = $this->taskFileRepository->getById((int) $fileId);
            if (empty($fileEntity)) {
                // 如果文件不存在，跳过
                continue;
            }

            // 验证文件是否属于当前用户
            if ($fileEntity->getUserId() !== $dataIsolation->getCurrentUserId()) {
                // 如果这个文件不是本人的，不处理
                continue;
            }

            // 跳过目录
            if ($fileEntity->getIsDirectory()) {
                continue;
            }

            try {
                $result[] = $this->generateFileUrlForEntity($dataIsolation, $fileEntity, $downloadMode, $fileId);
            } catch (Throwable $e) {
                // 如果获取URL失败，跳过
                continue;
            }
        }

        return $result;
    }

    /**
     * Get file URLs by access token.
     *
     * @param array $fileIds Array of file IDs
     * @param string $token Access token
     * @param string $downloadMode Download mode
     * @return array Array of file URLs
     */
    public function getFileUrlsByAccessToken(array $fileIds, string $token, string $downloadMode): array
    {
        // 从缓存里获取数据
        if (! AccessTokenUtil::validate($token)) {
            ExceptionBuilder::throw(GenericErrorCode::AccessDenied, 'task_file.access_denied');
        }

        // 从token获取内容
        $topicId = AccessTokenUtil::getResource($token);
        $organizationCode = AccessTokenUtil::getOrganizationCode($token);
        $result = [];

        // 获取 topic 详情
        $topicEntity = $this->topicRepository->getTopicById((int) $topicId);
        if (! $topicEntity) {
            ExceptionBuilder::throw(SuperAgentErrorCode::TOPIC_NOT_FOUND);
        }

        foreach ($fileIds as $fileId) {
            $fileEntity = $this->taskFileRepository->getById((int) $fileId);
            $isBelongTopic = ((string) $fileEntity?->getTopicId()) === $topicId;
            $isBelongProject = ((string) $fileEntity?->getProjectId()) == $topicEntity->getProjectId();
            if (empty($fileEntity) || (! $isBelongTopic && ! $isBelongProject)) {
                // 如果文件不存在或既不属于该话题也不属于该项目，跳过
                continue;
            }

            // 跳过目录
            if ($fileEntity->getIsDirectory()) {
                continue;
            }

            try {
                // 创建临时的数据隔离对象用于生成URL
                $dataIsolation = new DataIsolation();
                $dataIsolation->setCurrentUserId($fileEntity->getUserId());
                $dataIsolation->setCurrentOrganizationCode($organizationCode);

                $result[] = $this->generateFileUrlForEntity($dataIsolation, $fileEntity, $downloadMode, $fileId);
            } catch (Throwable $e) {
                // 如果获取URL失败，跳过
                continue;
            }
        }

        return $result;
    }

    public function getFullPrefix(string $organizationCode): string
    {
        return $this->cloudFileRepository->getFullPrefix($organizationCode);
    }

    /**
     * Get pre-signed URL for file download or upload.
     *
     * @param DataIsolation $dataIsolation Data isolation context
     * @param TaskFileEntity $fileEntity File entity to generate URL for
     * @param array $options Additional options (method, expires, filename, etc.)
     * @return string Pre-signed URL
     * @throws Throwable
     */
    public function getFilePreSignedUrl(
        DataIsolation $dataIsolation,
        TaskFileEntity $fileEntity,
        array $options = []
    ): string {
        // Permission check: ensure file belongs to current user
        if ($fileEntity->getUserId() !== $dataIsolation->getCurrentUserId()) {
            ExceptionBuilder::throw(SuperAgentErrorCode::FILE_PERMISSION_DENIED, trans('file.permission_denied'));
        }

        // Permission check: ensure file belongs to current organization
        if ($fileEntity->getOrganizationCode() !== $dataIsolation->getCurrentOrganizationCode()) {
            ExceptionBuilder::throw(SuperAgentErrorCode::FILE_PERMISSION_DENIED, trans('file.permission_denied'));
        }

        // Cannot generate URL for directories
        if ($fileEntity->getIsDirectory()) {
            ExceptionBuilder::throw(SuperAgentErrorCode::FILE_ILLEGAL_KEY, trans('file.cannot_generate_url_for_directory'));
        }

        // Set default filename if not provided
        if (! isset($options['filename'])) {
            $options['filename'] = $fileEntity->getFileName();
        }

        // Set default HTTP method for downloads
        if (! isset($options['method'])) {
            $options['method'] = 'GET';
        }

        // Determine storage bucket type based on file storage type
        $bucketType = StorageBucketType::SandBox;

        try {
            return $this->cloudFileRepository->getPreSignedUrlByCredential(
                $dataIsolation->getCurrentOrganizationCode(),
                $fileEntity->getFileKey(),
                $bucketType,
                $options
            );
        } catch (Throwable $e) {
            ExceptionBuilder::throw(
                SuperAgentErrorCode::FILE_NOT_FOUND,
                trans('file.file_not_found')
            );
        }
    }

    /**
     * Ensure the complete directory path exists, creating missing directories.
     *
     * @param int $projectId Project ID
     * @param string $dirPath Directory path (e.g., "a/b/c")
     * @param string $workDir Project work directory
     * @return int The file_id of the final directory in the path
     */
    private function ensureDirectoryPathExists(int $projectId, string $dirPath, string $workDir, string $userId, string $organizationCode): int
    {
        // Cache to avoid duplicate database queries in single request
        static $pathCache = [];
        $cacheKey = "{$projectId}:{$dirPath}";

        if (isset($pathCache[$cacheKey])) {
            return $pathCache[$cacheKey];
        }

        // Split path into parts and process each level
        $pathParts = array_filter(explode('/', trim($dirPath, '/')));
        $currentParentId = $this->findOrCreateProjectRootDirectory($projectId, $workDir, $userId, $organizationCode);
        $currentPath = '';

        foreach ($pathParts as $dirName) {
            $currentPath = empty($currentPath) ? $dirName : "{$currentPath}/{$dirName}";
            $currentCacheKey = "{$projectId}:{$currentPath}";

            // Check cache first
            if (isset($pathCache[$currentCacheKey])) {
                $currentParentId = $pathCache[$currentCacheKey];
                continue;
            }

            // Look for existing directory
            $existingDir = $this->findDirectoryByParentIdAndName($currentParentId, $dirName, $projectId);

            if ($existingDir !== null) {
                $currentParentId = $existingDir->getFileId();
            } else {
                // Create new directory
                $newDirId = $this->createDirectory($projectId, $currentParentId, $dirName, $currentPath, $workDir, $userId, $organizationCode);
                $currentParentId = $newDirId;
            }

            // Cache the result
            $pathCache[$currentCacheKey] = $currentParentId;
        }

        $pathCache[$cacheKey] = $currentParentId;
        return $currentParentId;
    }

    /**
     * Find directory by parent ID and name.
     *
     * @param null|int $parentId Parent directory ID (null for root level)
     * @param string $dirName Directory name
     * @param int $projectId Project ID
     * @return null|TaskFileEntity Found directory entity or null
     */
    private function findDirectoryByParentIdAndName(?int $parentId, string $dirName, int $projectId): ?TaskFileEntity
    {
        // Get all siblings under the parent directory
        $siblings = $this->taskFileRepository->getSiblingsByParentId($parentId, $projectId);

        foreach ($siblings as $sibling) {
            // Convert array to entity for consistency (if needed)
            if (is_array($sibling)) {
                if ($sibling['is_directory'] && $sibling['file_name'] === $dirName) {
                    return $this->taskFileRepository->getById($sibling['file_id']);
                }
            } elseif ($sibling instanceof TaskFileEntity) {
                if ($sibling->getIsDirectory() && $sibling->getFileName() === $dirName) {
                    return $sibling;
                }
            }
        }

        return null;
    }

    /**
     * Create a new directory entity.
     *
     * @param int $projectId Project ID
     * @param int $parentId Parent directory ID
     * @param string $dirName Directory name
     * @param string $relativePath Relative path from project root
     * @param string $workDir Project work directory
     * @return int Created directory file_id
     */
    private function createDirectory(int $projectId, int $parentId, string $dirName, string $relativePath, string $workDir, string $userId, string $organizationCode): int
    {
        $dirEntity = new TaskFileEntity();
        $dirEntity->setFileId(IdGenerator::getSnowId());
        $dirEntity->setProjectId($projectId);
        $dirEntity->setUserId($userId);
        $dirEntity->setOrganizationCode($organizationCode);
        $dirEntity->setFileName($dirName);

        // Build complete file_key: workDir + relativePath + trailing slash
        $fullPrefix = $this->getFullPrefix($organizationCode);
        $fileKey = WorkDirectoryUtil::getFullFileKey($fullPrefix, $workDir, $relativePath);
        $dirEntity->setFileKey($fileKey);
        $dirEntity->setFileSize(0);
        $dirEntity->setFileType(FileType::DIRECTORY->value);
        $dirEntity->setIsDirectory(true);
        $dirEntity->setParentId($parentId);
        $dirEntity->setSource(TaskFileSource::PROJECT_DIRECTORY);
        if (WorkDirectoryUtil::isSnapshotFile($fileKey)) {
            $dirEntity->setStorageType(StorageType::SNAPSHOT);
        } else {
            $dirEntity->setStorageType(StorageType::WORKSPACE);
        }
        $dirEntity->setIsHidden(false);
        $dirEntity->setSort(0);

        $now = date('Y-m-d H:i:s');
        $dirEntity->setCreatedAt($now);
        $dirEntity->setUpdatedAt($now);

        $this->insert($dirEntity);

        return $dirEntity->getFileId();
    }

    /**
     * Update existing sandbox file.
     *
     * @param TaskFileEntity $existingFile Existing file entity
     * @param SandboxFileNotificationDataValueObject $data File data
     * @param string $organizationCode Organization code
     * @return TaskFileEntity Updated file entity
     */
    private function updateSandboxFile(
        TaskFileEntity $existingFile,
        SandboxFileNotificationDataValueObject $data,
        string $organizationCode
    ): TaskFileEntity {
        // Get file information from cloud storage
        $fileInfo = $this->getFileInfoFromCloudStorage($existingFile->getFileKey(), $organizationCode);

        // Update file entity
        $existingFile->setFileSize($fileInfo['size'] ?? $data->getFileSize());
        $existingFile->setUpdatedAt(date('Y-m-d H:i:s'));

        // Update file extension if changed
        $fileName = basename($existingFile->getFileKey());
        $fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
        $existingFile->setFileExtension($fileExtension);

        $this->taskFileRepository->updateById($existingFile);

        return $existingFile;
    }

    /**
     * Create new sandbox file.
     *
     * @param DataIsolation $dataIsolation Data isolation context
     * @param ProjectEntity $projectEntity Project entity
     * @param string $fileKey Complete file key
     * @param int $parentId Parent directory ID
     * @param SandboxFileNotificationDataValueObject $data File data
     * @return TaskFileEntity Created file entity
     */
    private function createSandboxFile(
        DataIsolation $dataIsolation,
        ProjectEntity $projectEntity,
        string $fileKey,
        int $parentId,
        int $taskId,
        SandboxFileNotificationDataValueObject $data,
    ): TaskFileEntity {
        $organizationCode = $dataIsolation->getCurrentOrganizationCode();

        // Get file information from cloud storage
        $fileInfo = $this->getFileInfoFromCloudStorage($fileKey, $organizationCode);

        // Create file entity
        $taskFileEntity = new TaskFileEntity();
        $taskFileEntity->setFileId(IdGenerator::getSnowId());
        $taskFileEntity->setProjectId($projectEntity->getId());
        $taskFileEntity->setUserId($dataIsolation->getCurrentUserId());
        $taskFileEntity->setOrganizationCode($organizationCode);
        $taskFileEntity->setFileKey($fileKey);

        $taskEntity = $this->taskRepository->getTaskById($taskId);
        if (! empty($taskEntity)) {
            $taskFileEntity->setTaskId($taskId);
            $taskFileEntity->setTopicId($taskEntity->getTopicId());
        }

        $isDirectory = WorkDirectoryUtil::isValidDirectoryName($fileKey);

        $fileName = basename($fileKey);
        $taskFileEntity->setFileName($fileName);
        $taskFileEntity->setFileSize($fileInfo['size'] ?? $data->getFileSize());
        if ($isDirectory) {
            $taskFileEntity->setFileType(FileType::DIRECTORY->value);
            $taskFileEntity->setFileExtension('');
        } else {
            $taskFileEntity->setFileType(FileType::SYSTEM_AUTO_UPLOAD->value);
            // Extract file extension
            $fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
            $taskFileEntity->setFileExtension($fileExtension);
        }
        $taskFileEntity->setIsDirectory($isDirectory);
        $taskFileEntity->setParentId($parentId === 0 ? null : $parentId);
        $taskFileEntity->setSource(TaskFileSource::AGENT);
        if (WorkDirectoryUtil::isSnapshotFile($fileKey)) {
            $taskFileEntity->setStorageType(StorageType::SNAPSHOT);
        } else {
            $taskFileEntity->setStorageType(StorageType::WORKSPACE);
        }
        $taskFileEntity->setIsHidden($this->isHiddenFile($fileKey));
        $taskFileEntity->setSort(0);
        // Set timestamps
        $now = date('Y-m-d H:i:s');
        $taskFileEntity->setCreatedAt($now);
        $taskFileEntity->setUpdatedAt($now);

        $this->insert($taskFileEntity);

        return $taskFileEntity;
    }

    /**
     * Get file information from cloud storage.
     *
     * @param string $fileKey File key
     * @param string $organizationCode Organization code
     * @return array File information
     */
    private function getFileInfoFromCloudStorage(string $fileKey, string $organizationCode): array
    {
        if (WorkDirectoryUtil::isValidDirectoryName($fileKey)) {
            return [
                'size' => 0,
                'last_modified' => date('Y-m-d H:i:s'),
            ];
        }

        try {
            $headObjectResult = $this->cloudFileRepository->getHeadObjectByCredential($organizationCode, $fileKey, StorageBucketType::SandBox);
            return [
                'size' => $headObjectResult['content_length'] ?? 0,
                'last_modified' => date('Y-m-d H:i:s'),
            ];
        } catch (Throwable $e) {
            // File not found or other cloud storage error
            $this->logger->warning(
                'Failed to get file info from cloud storage',
                [
                    'file_key' => $fileKey,
                    'organization_code' => $organizationCode,
                    'error' => $e->getMessage(),
                ]
            );
            return [
                'size' => 0,
                'last_modified' => date('Y-m-d H:i:s'),
            ];
        }
    }

    /**
     * Check if a target directory is a subdirectory of the file being moved.
     * This prevents circular directory moves (e.g., moving a parent directory into its own child).
     *
     * @param int $fileId The ID of the file being moved (should be a directory)
     * @param int $targetParentId The ID of the target parent directory
     * @return bool True if the target is a subdirectory of the file being moved, false otherwise
     */
    private function isSubdirectory(int $fileId, int $targetParentId): bool
    {
        // Use parent-child relationship traversal instead of string comparison
        // Start from target parent and traverse up to see if we reach the file being moved
        $currentParentId = $targetParentId;
        $visitedIds = []; // Prevent infinite loops

        while ($currentParentId !== null && ! in_array($currentParentId, $visitedIds, true)) {
            $visitedIds[] = $currentParentId;

            // If we reach the file being moved, it means target is a subdirectory
            if ($currentParentId === $fileId) {
                return true;
            }

            // Get the parent of current directory
            $currentEntity = $this->taskFileRepository->getById($currentParentId);
            if ($currentEntity === null) {
                break;
            }

            $currentParentId = $currentEntity->getParentId();
        }

        return false;
    }

    /**
     * Prepare URL options for file download/preview.
     *
     * @param string $filename File name
     * @param string $downloadMode Download mode (download, preview, inline)
     * @return array URL options array
     */
    private function prepareFileUrlOptions(string $filename, string $downloadMode): array
    {
        $urlOptions = [];

        // 设置Content-Type based on file extension
        $urlOptions['content_type'] = ContentTypeUtil::getContentType($filename);

        // 设置Content-Disposition based on download mode and HTTP standards
        switch (strtolower($downloadMode)) {
            case 'preview':
            case 'inline':
                // 预览模式：如果文件可预览则inline，否则强制下载
                if (ContentTypeUtil::isPreviewable($filename)) {
                    $urlOptions['custom_query']['response-content-disposition']
                        = ContentTypeUtil::buildContentDispositionHeader($filename, 'inline');
                } else {
                    $urlOptions['custom_query']['response-content-disposition']
                        = ContentTypeUtil::buildContentDispositionHeader($filename, 'attachment');
                }
                break;
            case 'download':
            default:
                // 下载模式：强制下载，使用标准的 attachment 格式
                $urlOptions['custom_query']['response-content-disposition']
                    = ContentTypeUtil::buildContentDispositionHeader($filename, 'attachment');
                break;
        }

        // 设置Content-Type响应头
        $urlOptions['custom_query']['response-content-type'] = $urlOptions['content_type'];

        // 设置filename用于预签名URL生成
        $urlOptions['filename'] = $filename;

        return $urlOptions;
    }

    /**
     * Generate file URL for a single file entity.
     *
     * @param DataIsolation $dataIsolation Data isolation context
     * @param TaskFileEntity $fileEntity File entity
     * @param string $downloadMode Download mode
     * @param string $fileId File ID for result array
     * @return array URL result array or null if failed
     */
    private function generateFileUrlForEntity(
        DataIsolation $dataIsolation,
        TaskFileEntity $fileEntity,
        string $downloadMode,
        string $fileId
    ): array {
        // 准备下载选项
        $filename = $fileEntity->getFileName();
        $urlOptions = $this->prepareFileUrlOptions($filename, $downloadMode);

        // 生成预签名URL
        $preSignedUrl = $this->getFilePreSignedUrl($dataIsolation, $fileEntity, $urlOptions);

        // 返回结果数组
        return [
            'file_id' => $fileId,
            'url' => $preSignedUrl,
        ];
    }

    /**
     * Check if file is hidden file.
     *
     * @param string $fileKey File path
     * @return bool Whether it's a hidden file: true-yes, false-no
     */
    private function isHiddenFile(string $fileKey): bool
    {
        // Remove leading slash, uniform processing
        $fileKey = ltrim($fileKey, '/');

        // Split path into parts
        $pathParts = explode('/', $fileKey);

        // Check if each path part starts with .
        foreach ($pathParts as $part) {
            if (! empty($part) && str_starts_with($part, '.')) {
                return true; // It's a hidden file
            }
        }

        return false; // It's not a hidden file
    }
}
