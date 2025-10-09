<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace App\Application\Speech\Service;

use App\Application\File\Service\FileAppService;
use App\Application\Speech\Assembler\MarkdownAssembler;
use App\Application\Speech\Assembler\SpeakerSegmentAssembler;
use App\Application\Speech\DTO\HandleQueryResultDTO;
use App\Application\Speech\DTO\ProcessSummaryTaskDTO;
use App\Application\Speech\DTO\SaveFileRecordToProjectDTO;
use App\Application\Speech\DTO\UploadFilesToProjectWorkspaceDTO;
use App\Application\Speech\Enum\AsrTaskStatusEnum;
use App\Domain\Contact\Entity\ValueObject\DataIsolation;
use App\Domain\Contact\Service\MagicDepartmentUserDomainService;
use App\Domain\File\DTO\CloudFileInfoDTO;
use App\Domain\File\Service\FileDomainService;
use App\Domain\Speech\Entity\Dto\LargeModelSpeechSubmitDTO;
use App\Domain\Speech\Entity\Dto\SpeechAudioDTO;
use App\Domain\Speech\Entity\Dto\SpeechQueryDTO;
use App\Domain\Speech\Entity\Dto\SpeechUserDTO;
use App\Infrastructure\Core\Exception\BusinessException;
use App\Infrastructure\Core\Exception\ExceptionBuilder;
use App\Infrastructure\Core\ValueObject\StorageBucketType;
use App\Infrastructure\ExternalAPI\Volcengine\DTO\AsrTaskStatusDTO;
use App\Infrastructure\ExternalAPI\Volcengine\DTO\SpeechRecognitionResultDTO;
use App\Infrastructure\ExternalAPI\Volcengine\ValueObject\VolcengineStatusCode;
use Dtyq\CloudFile\Kernel\Struct\UploadFile;
use Dtyq\SuperMagic\Domain\SuperAgent\Entity\ProjectEntity;
use Dtyq\SuperMagic\Domain\SuperAgent\Entity\TaskFileEntity;
use Dtyq\SuperMagic\Domain\SuperAgent\Repository\Facade\TaskFileRepositoryInterface;
use Dtyq\SuperMagic\Domain\SuperAgent\Service\ProjectDomainService;
use Dtyq\SuperMagic\Domain\SuperAgent\Service\ProjectMemberDomainService;
use Dtyq\SuperMagic\Domain\SuperAgent\Service\TaskFileDomainService;
use Dtyq\SuperMagic\ErrorCode\SuperAgentErrorCode;
use Hyperf\Codec\Json;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Redis\Redis;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

use function Hyperf\Translation\trans;

/**
 * ASR文件管理应用服务 - 负责ASR相关的所有业务逻辑.
 */
readonly class AsrFileAppService
{
    private LoggerInterface $logger;

    public function __construct(
        private FileDomainService $fileDomainService,
        private FileAppService $fileAppService,
        private SpeechToTextStandardAppService $speechToTextService,
        private TaskFileRepositoryInterface $taskFileRepository,
        private ProjectDomainService $projectDomainService,
        private ProjectMemberDomainService $projectMemberDomainService,
        private TaskFileDomainService $taskFileDomainService,
        private MagicDepartmentUserDomainService $magicDepartmentUserDomainService,
        private SpeakerSegmentAssembler $speakerSegmentAssembler,
        private MarkdownAssembler $markdownAssembler,
        private Redis $redis,
        LoggerFactory $loggerFactory
    ) {
        $this->logger = $loggerFactory->get('AsrFileAppService');
    }

    /**
     * 下载指定目录下的音频文件.
     *
     * @param string $organizationCode 组织编码
     * @param string $remoteDirectory 远程目录路径
     * @param string $taskKey 任务键
     * @return string[] 本地文件路径列表
     * @throws InvalidArgumentException
     */
    public function downloadAudioFiles(string $organizationCode, string $remoteDirectory, string $taskKey): array
    {
        // 创建本地运行时目录
        $runtimeDir = sprintf('%s/runtime/asr/%s', BASE_PATH, $taskKey);
        if (! is_dir($runtimeDir) && ! mkdir($runtimeDir, 0755, true) && ! is_dir($runtimeDir)) {
            throw new InvalidArgumentException('创建本地目录失败');
        }

        $localFiles = [];

        try {
            // 🔧 保持原始组织编码（不转换大小写，确保与文件存储时的编码一致）
            $organizationCode = trim($organizationCode);

            $this->logger->debug('ASR音频文件下载开始', [
                'organization_code' => $organizationCode,
                'remote_directory' => $remoteDirectory,
                'task_key' => $taskKey,
            ]);

            // 复用getAudioFileList获取所有音频文件，然后过滤出数字命名的分片文件
            $allAudioFiles = $this->getAudioFileList($organizationCode, $remoteDirectory);

            // 过滤出数字命名的音频文件（临时分片文件）
            $audioFiles = array_filter($allAudioFiles, static function (CloudFileInfoDTO $file) {
                $filename = $file->getFilename();
                return preg_match('/^\d+\..+$/', $filename);
            });

            if (empty($audioFiles)) {
                throw new InvalidArgumentException(sprintf(
                    '在目录中未找到音频文件: %s (组织编码: %s)',
                    $remoteDirectory,
                    $organizationCode
                ));
            }

            // 下载所有音频文件
            foreach ($audioFiles as $audioFile) {
                $objectKey = $audioFile->getKey();
                $filename = $audioFile->getFilename();
                $localFilePath = sprintf('%s/%s', $runtimeDir, $filename);

                try {
                    // 使用fileAppService下载文件
                    $this->fileAppService->downloadByChunks(
                        $organizationCode,
                        $objectKey,
                        $localFilePath,
                        StorageBucketType::SandBox->value
                    );

                    // 验证文件下载成功且不为空
                    if (file_exists($localFilePath) && filesize($localFilePath) > 0) {
                        $localFiles[] = $localFilePath;
                    } else {
                        throw new InvalidArgumentException(sprintf('下载的文件为空: %s', $filename));
                    }
                } catch (Throwable $downloadError) {
                    throw new InvalidArgumentException(
                        sprintf('下载音频文件失败 %s: %s', $filename, $downloadError->getMessage())
                    );
                }
            }

            return $localFiles;
        } catch (Throwable $e) {
            throw new InvalidArgumentException(sprintf('下载音频文件失败: %s', $e->getMessage()));
        }
    }

    /**
     * 获取ASR录音目录下的音频文件列表.
     *
     * @param string $organizationCode 组织编码
     * @param string $businessDirectory 业务目录
     * @return CloudFileInfoDTO[] 音频文件列表
     */
    public function getAudioFileList(string $organizationCode, string $businessDirectory): array
    {
        try {
            // 🔧 保持原始组织编码（不转换大小写，确保与文件存储时的编码一致）
            $organizationCode = trim($organizationCode);
            $directoryPrefix = trim($businessDirectory, '/');

            $this->logger->debug('ASR文件列表查询', [
                'organization_code' => $organizationCode,
                'directory_prefix' => $directoryPrefix,
            ]);

            // 获取目录下的所有文件
            $allFiles = $this->fileDomainService->getFilesFromCloudStorage(
                $organizationCode,
                $directoryPrefix,
                StorageBucketType::SandBox
            );

            // 过滤出音频文件（支持常见音频格式）
            /* @var CloudFileInfoDTO[] $audioFiles */
            $audioFiles = array_filter($allFiles, static function (CloudFileInfoDTO $file) {
                $filename = $file->getFilename();
                return preg_match('/\.(webm|mp3|wav|m4a|ogg|aac|flac)$/i', $filename);
            });

            $this->logger->debug('ASR文件列表查询结果', [
                'total_files' => count($allFiles),
                'audio_files' => count($audioFiles),
                'audio_filenames' => array_map(static fn ($file) => $file->getFilename(), $audioFiles),
            ]);

            return $audioFiles;
        } catch (Throwable $e) {
            $this->logger->warning('ASR文件列表查询失败', [
                'organization_code' => $organizationCode,
                'business_directory' => $businessDirectory,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * ASR专用文件上传方法.
     *
     * @param string $organizationCode 组织编码
     * @param UploadFile $uploadFile 上传文件对象
     */
    public function uploadFile(string $organizationCode, UploadFile $uploadFile): void
    {
        $this->fileAppService->upload($organizationCode, $uploadFile, StorageBucketType::SandBox, false);
    }

    /**
     * 下载已合并的音频文件到本地.
     *
     * @param string $organizationCode 组织编码
     * @param string $mergedAudioFileKey 合并音频文件的key
     * @param string $taskKey 任务键
     * @return string 本地文件路径
     * @throws InvalidArgumentException
     */
    public function downloadMergedAudioFile(string $organizationCode, string $mergedAudioFileKey, string $taskKey): string
    {
        // 创建本地运行时目录
        $runtimeDir = sprintf('%s/runtime/asr/%s', BASE_PATH, $taskKey);
        if (! is_dir($runtimeDir) && ! mkdir($runtimeDir, 0755, true) && ! is_dir($runtimeDir)) {
            throw new InvalidArgumentException('创建本地目录失败');
        }

        // 确定本地文件路径
        $localFilePath = sprintf('%s/merged_audio.webm', $runtimeDir);

        try {
            // 下载合并的音频文件
            $this->fileAppService->downloadByChunks(
                $organizationCode,
                $mergedAudioFileKey,
                $localFilePath,
                StorageBucketType::SandBox->value
            );

            // 验证文件下载成功且不为空
            if (! file_exists($localFilePath) || filesize($localFilePath) === 0) {
                throw new InvalidArgumentException('下载的合并音频文件为空');
            }

            return $localFilePath;
        } catch (Throwable $e) {
            throw new InvalidArgumentException(sprintf('下载合并音频文件失败: %s', $e->getMessage()));
        }
    }

    /**
     * 处理ASR音频文件：下载 -> 合并 -> 上传.
     *
     * @param string $organizationCode 组织编码
     * @param string $businessDirectory 业务目录
     * @param string $taskKey 任务键
     * @param bool $cleanupRemoteFiles 是否清理远程原始文件，默认true
     * @return array 包含音频文件URL和文件key的数组 ['url' => string, 'file_key' => string]
     * @throws InvalidArgumentException
     */
    public function processAudioForAsr(string $organizationCode, string $businessDirectory, string $taskKey, bool $cleanupRemoteFiles = true): array
    {
        try {
            // 1. 下载所有音频文件到本地
            $localAudioFiles = $this->downloadAudioFiles($organizationCode, $businessDirectory, $taskKey);
            if (empty($localAudioFiles)) {
                throw new InvalidArgumentException('未找到音频文件');
            }

            // 2. 合并音频文件
            $mergedAudioFile = $this->mergeAudioFiles($localAudioFiles, $taskKey);

            // 3. 上传合并后的音频文件并获取URL和文件key
            $uploadResult = $this->uploadMergedAudioAndGetUrl($organizationCode, $mergedAudioFile, $taskKey, $businessDirectory);

            // 4. 清理临时文件
            if ($cleanupRemoteFiles) {
                $this->cleanupTaskFiles($taskKey, $organizationCode, $businessDirectory);
            } else {
                $this->cleanupTaskFiles($taskKey); // 只清理本地文件
            }

            return $uploadResult;
        } catch (Throwable $e) {
            // 异常时只清理本地临时文件
            try {
                $this->cleanupTaskFiles($taskKey);
            } catch (Throwable) {
                // 静默处理清理失败
            }
            throw new InvalidArgumentException(sprintf('ASR音频文件处理失败: %s', $e->getMessage()));
        }
    }

    /**
     * 上传合并后的音频文件并获取可访问的URL.
     *
     * @param string $organizationCode 组织编码
     * @param string $localAudioFile 本地音频文件路径
     * @param string $taskKey 任务键
     * @param string $businessDirectory 业务目录，合并文件将上传到此目录下
     * @return array 包含音频文件URL和文件key的数组 ['url' => string, 'file_key' => string]
     * @throws InvalidArgumentException
     */
    public function uploadMergedAudioAndGetUrl(string $organizationCode, string $localAudioFile, string $taskKey, string $businessDirectory): array
    {
        try {
            if (! file_exists($localAudioFile)) {
                throw new InvalidArgumentException(sprintf('本地音频文件不存在: %s', $localAudioFile));
            }

            // 生成云存储中的文件键 - 与原始录音文件在同一目录下
            $filename = sprintf('merged_%s.webm', $taskKey);
            // 确保 businessDirectory 以 / 结尾
            $businessDirectory = sprintf('%s/', rtrim($businessDirectory, '/'));
            $remoteKey = sprintf('%s%s', ltrim($businessDirectory, '/'), $filename);

            // 创建上传文件对象
            $uploadFile = new UploadFile($localAudioFile, '', $remoteKey, false);

            // ASR相关操作统一使用SandBox存储桶
            $this->fileAppService->upload($organizationCode, $uploadFile, StorageBucketType::SandBox, false);

            // 获取上传后的实际文件键
            $actualFileKey = $uploadFile->getKey();
            // ASR相关操作统一使用SandBox存储桶获取链接
            $fileLink = $this->fileAppService->getLink($organizationCode, $actualFileKey, StorageBucketType::SandBox);

            if (! $fileLink) {
                throw new InvalidArgumentException('无法获取音频文件访问链接');
            }

            return [
                'url' => $fileLink->getUrl(),
                'file_key' => $actualFileKey,
            ];
        } catch (Throwable $e) {
            throw new InvalidArgumentException(sprintf('上传合并音频文件失败: %s', $e->getMessage()));
        }
    }

    /**
     * 合并音频文件为一个完整文件.
     *
     * @param array $audioFiles 音频文件路径列表
     * @param string $taskKey 任务键
     * @return string 合并后文件路径
     * @throws InvalidArgumentException
     */
    public function mergeAudioFiles(array $audioFiles, string $taskKey): string
    {
        if (empty($audioFiles)) {
            throw new InvalidArgumentException('没有音频文件可合并');
        }

        $runtimeDir = sprintf('%s/runtime/asr/%s', BASE_PATH, $taskKey);
        $outputFile = sprintf('%s/merged_audio.webm', $runtimeDir);

        // 如果只有一个文件，直接返回该文件路径
        if (count($audioFiles) === 1) {
            // 复制文件到目标位置
            if (! copy($audioFiles[0], $outputFile)) {
                throw new InvalidArgumentException('复制单个音频文件失败');
            }
            return $outputFile;
        }

        // 多个文件需要合并 - 使用FFmpeg
        $ffmpegPath = $this->findFFmpegPath();
        if (! $ffmpegPath) {
            throw new InvalidArgumentException('未找到FFmpeg，无法合并音频文件。请安装FFmpeg: brew install ffmpeg (macOS) 或 apt-get install ffmpeg (Ubuntu)');
        }

        return $this->mergeAudioWithFFmpeg($audioFiles, $taskKey, $ffmpegPath, $outputFile);
    }

    /**
     * 清理任务相关的临时文件.
     *
     * @param string $taskKey 任务键
     * @param null|string $organizationCode 组织编码，用于删除OSS上的临时文件
     * @param null|string $businessDirectory 业务目录，用于删除OSS上的临时文件
     */
    public function cleanupTaskFiles(string $taskKey, ?string $organizationCode = null, ?string $businessDirectory = null): void
    {
        // 1. 清理OSS上的临时小文件
        if ($organizationCode && $businessDirectory) {
            $this->cleanupRemoteAudioFiles($organizationCode, $businessDirectory);
        }

        // 2. 清理本地临时文件
        $runtimeDir = sprintf('%s/runtime/asr/%s', BASE_PATH, $taskKey);
        if (is_dir($runtimeDir)) {
            try {
                // 删除目录中的所有文件
                $files = glob(sprintf('%s/*', $runtimeDir));
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }

                // 删除目录
                rmdir($runtimeDir);
            } catch (Throwable $e) {
                $this->logger->warning('本地临时文件清理失败', [
                    'task_key' => $taskKey,
                    'runtime_dir' => $runtimeDir,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    // ==================== 语音识别任务管理 ====================

    /**
     * 验证项目权限 - 确保项目属于当前用户和组织.
     *
     * @param string $projectId 项目ID
     * @param string $userId 用户ID
     * @param string $organizationCode 组织编码
     * @return ProjectEntity 项目实体
     * @throws InvalidArgumentException 当项目不存在或无权限时抛出异常
     */
    public function validateProjectAccess(string $projectId, string $userId, string $organizationCode): ProjectEntity
    {
        try {
            // 获取项目信息
            $projectEntity = $this->projectDomainService->getProjectNotUserId((int) $projectId);
            if ($projectEntity === null) {
                ExceptionBuilder::throw(SuperAgentErrorCode::PROJECT_NOT_FOUND);
            }
            // 校验项目是否属于当前组织
            if ($projectEntity->getUserOrganizationCode() !== $organizationCode) {
                throw new InvalidArgumentException(trans('asr.api.validation.project_access_denied_organization'));
            }

            // 校验项目是否属于当前用户
            if ($projectEntity->getUserId() === $userId) {
                return $projectEntity;
            }

            // 检查用户是否是项目成员
            if ($this->projectMemberDomainService->isProjectMemberByUser((int) $projectId, $userId)) {
                return $projectEntity;
            }

            // 检查用户所在部门是否有项目权限
            $dataIsolation = DataIsolation::create($organizationCode, $userId);
            $departmentIds = $this->magicDepartmentUserDomainService->getDepartmentIdsByUserId($dataIsolation, $userId, true);

            if (! empty($departmentIds) && $this->projectMemberDomainService->isProjectMemberByDepartments((int) $projectId, $departmentIds)) {
                return $projectEntity;
            }

            // 所有权限检查都失败
            throw new InvalidArgumentException(trans('asr.api.validation.project_access_denied_user'));
        } catch (BusinessException $e) {
            // 处理 ExceptionBuilder::throw 抛出的业务异常
            if ($e->getCode() === SuperAgentErrorCode::PROJECT_NOT_FOUND->value) {
                throw new InvalidArgumentException(trans('asr.api.validation.project_not_found'));
            }

            // 其他业务异常转换为权限验证失败
            throw new InvalidArgumentException(trans('asr.api.validation.project_access_validation_failed', ['error' => $e->getMessage()]));
        } catch (InvalidArgumentException $e) {
            // 重新抛出权限相关异常
            throw $e;
        } catch (Throwable $e) {
            // 其他异常统一处理为权限验证失败
            throw new InvalidArgumentException(trans('asr.api.validation.project_access_validation_failed', ['error' => $e->getMessage()]));
        }
    }

    /**
     * 处理ASR总结任务的完整流程.
     */
    public function processSummaryTask(ProcessSummaryTaskDTO $dto): AsrTaskStatusDTO
    {
        // 🔒 首先验证项目权限 - 确保项目属于当前用户和组织
        $this->validateProjectAccess($dto->projectId, $dto->userId, $dto->organizationCode);

        if (! $dto->taskStatus->isTaskSubmitted()) {
            // 第一次查询，处理音频文件并提交转换任务
            $this->handleFirstTimeSubmission($dto->taskStatus, $dto->organizationCode, $dto->projectId, $dto->userId);
        } elseif ($dto->taskStatus->status === AsrTaskStatusEnum::COMPLETED && $dto->retry === 1) {
            // 任务已完成但请求重新上传文件到项目工作区
            if (! empty($dto->taskStatus->summaryContent)) {
                $uploadDto = new UploadFilesToProjectWorkspaceDTO(
                    $dto->organizationCode,
                    $dto->taskStatus,
                    $dto->projectId,
                    $dto->taskStatus->summaryContent,
                    true
                );
                $this->uploadFilesToProjectWorkspace($uploadDto);
            }
        } elseif ($dto->taskStatus->status === AsrTaskStatusEnum::PROCESSING) {
            // 已提交任务，查询转换进度
            $this->handleTaskProgressQuery($dto->taskStatus, $dto->organizationCode, $dto->projectId, $dto->retry);
        }

        return $dto->taskStatus;
    }

    /**
     * 上传文件到项目工作区
     * 包括：录音合并后的原始文件、录音转文字后的原始文件(markdown格式)、AI大模型总结(markdown格式)
     * 重复请求时会先检查文件是否已存在，如果存在则跳过上传
     * 如果 retry=true，则清理缓存并强制重新上传所有文件.
     */
    public function uploadFilesToProjectWorkspace(UploadFilesToProjectWorkspaceDTO $dto): void
    {
        try {
            $timestamp = date('Y-m-d_H-i-s');
            $taskKey = $dto->taskStatus->taskKey;

            // 检查文件存在状态
            $existingFiles = $dto->forceRetry
                ? ['merged_audio' => false, 'transcription' => false, 'summary' => false]
                : $this->checkProjectFilesExist($dto->projectId, $taskKey);

            if ($dto->forceRetry) {
                $this->clearProjectFileCache($dto->projectId, $taskKey);
            }

            // 直接内联上传逻辑，减少方法调用层级

            // 1. 上传合并音频文件（仅在强制重试时）
            if (! empty($dto->taskStatus->mergedAudioFileKey) && ! $existingFiles['merged_audio'] && $dto->forceRetry) {
                $fileLink = $this->fileAppService->getLink($dto->organizationCode, $dto->taskStatus->mergedAudioFileKey, StorageBucketType::SandBox);
                if ($fileLink) {
                    $audioContent = file_get_contents($fileLink->getUrl());
                    if ($audioContent !== false) {
                        $fileName = sprintf('%s_%s.webm', trans('asr.file_names.merged_audio_prefix'), $timestamp);
                        $this->uploadContentToProjectWorkspace($dto->organizationCode, $dto->projectId, $fileName, $audioContent, 'webm', $dto->taskStatus->userId);
                    }
                }
            }

            // 2. 上传转录文件
            if (! $existingFiles['transcription'] && ! empty($dto->transcriptionContent)) {
                $markdownContent = $this->markdownAssembler->buildTranscriptionMarkdown(
                    $taskKey,
                    $dto->transcriptionContent,
                    trans('asr.markdown.transcription_title'),
                    trans('asr.markdown.task_id_label'),
                    trans('asr.markdown.generate_time_label'),
                    trans('asr.markdown.transcription_content_title')
                );
                $fileName = sprintf('%s_%s.md', trans('asr.file_names.transcription_prefix'), $timestamp);
                $this->uploadContentToProjectWorkspace($dto->organizationCode, $dto->projectId, $fileName, $markdownContent, 'md', $dto->taskStatus->userId);
            }

            // 3. 上传总结文件
            if (! $existingFiles['summary'] && ! empty($dto->taskStatus->summaryContent)) {
                $markdownContent = $this->markdownAssembler->buildSummaryMarkdown(
                    $taskKey,
                    $dto->taskStatus->summaryContent,
                    trans('asr.markdown.summary_title'),
                    trans('asr.markdown.task_id_label'),
                    trans('asr.markdown.generate_time_label'),
                    trans('asr.markdown.summary_content_title')
                );
                $fileName = sprintf('%s_%s.md', trans('asr.file_names.summary_prefix'), $timestamp);
                $this->uploadContentToProjectWorkspace($dto->organizationCode, $dto->projectId, $fileName, $markdownContent, 'md', $dto->taskStatus->userId);
            }

            $this->logger->info('文件上传到项目工作区成功', [
                'organization_code' => $dto->organizationCode,
                'task_key' => $taskKey,
                'project_id' => $dto->projectId,
            ]);
        } catch (Throwable $e) {
            $this->logger->warning('Failed to upload files to project workspace', [
                'project_id' => $dto->projectId,
                'task_key' => $dto->taskStatus->taskKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 构建包含文件列表的响应.
     */
    public function buildFileListResponse(string $organizationCode, string $businessDirectory): array
    {
        $uploadedFiles = [];
        try {
            // 使用ASR文件服务查询音频文件
            $files = $this->getAudioFileList($organizationCode, $businessDirectory);

            foreach ($files as $file) {
                $uploadedFiles[] = [
                    'filename' => $file->getFilename() ?: basename($file->getKey()),
                    'key' => $file->getKey(),
                    'size' => $file->getSize(),
                    'modified' => $file->getLastModified(),
                ];
            }
        } catch (Throwable) {
            // 静默处理，不影响主要功能
        }

        return [
            'files' => $uploadedFiles,
            'file_count' => count($uploadedFiles),
        ];
    }

    // ==================== 任务状态管理 ====================

    /**
     * 从Redis获取任务状态
     *
     * @param string $taskKey 任务键
     * @param string $userId 用户ID
     * @return AsrTaskStatusDTO 任务状态DTO
     */
    public function getTaskStatusFromRedis(string $taskKey, string $userId): AsrTaskStatusDTO
    {
        $redisKey = sprintf('asr:task:%s', md5(sprintf('%s:%s', $userId, $taskKey)));

        try {
            $taskData = $this->redis->hGetAll($redisKey);

            if (empty($taskData)) {
                return new AsrTaskStatusDTO();
            }

            return AsrTaskStatusDTO::fromArray($taskData);
        } catch (Throwable) {
            return new AsrTaskStatusDTO();
        }
    }

    /**
     * 保存任务状态到Redis.
     *
     * @param AsrTaskStatusDTO $taskStatus 任务状态DTO
     * @param int $ttl 缓存过期时间（秒），默认12小时
     */
    public function saveTaskStatusToRedis(AsrTaskStatusDTO $taskStatus, int $ttl = 43200): void
    {
        try {
            $redisKey = sprintf('asr:task:%s', md5(sprintf('%s:%s', $taskStatus->userId, $taskStatus->taskKey)));

            // 保存任务状态数据
            $this->redis->hMSet($redisKey, $taskStatus->toArray());

            // 设置过期时间
            $this->redis->expire($redisKey, $ttl);
        } catch (Throwable $e) {
            // Redis操作失败时记录但不抛出异常
            $this->logger->warning(trans('asr.api.redis.save_task_status_failed'), [
                'task_key' => $taskStatus->taskKey ?? 'unknown',
                'user_id' => $taskStatus->userId ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 获取并验证任务状态 - 包含安全检查.
     *
     * @throws InvalidArgumentException
     */
    public function getAndValidateTaskStatus(string $taskKey, string $userId): AsrTaskStatusDTO
    {
        // 从Redis获取任务状态
        $taskStatus = $this->getTaskStatusFromRedis($taskKey, $userId);

        if ($taskStatus->isEmpty()) {
            throw new InvalidArgumentException(trans('asr.api.validation.upload_audio_first'));
        }

        // 校验目录是否属于当前用户（额外的安全检查）- 使用STS完整目录进行验证
        $this->validateDirectoryOwnership($taskStatus->stsFullDirectory, $userId);

        return $taskStatus;
    }

    /**
     * 获取工作区文件状态详情.
     *
     * @param string $projectId 项目ID
     * @param string $taskKey 任务Key
     * @param AsrTaskStatusEnum $taskStatus 任务状态
     * @return array 工作区文件状态
     */
    public function getWorkspaceFilesStatus(string $projectId, string $taskKey, AsrTaskStatusEnum $taskStatus): array
    {
        $status = [
            'merged_audio' => false,
            'transcription' => false,
            'summary' => false,
            'total_uploaded' => 0,
            'upload_pending' => false,
        ];

        if ($taskStatus === AsrTaskStatusEnum::COMPLETED) {
            // 任务完成时检查文件上传状态
            $existingFiles = $this->checkProjectFilesExist($projectId, $taskKey);
            $status = array_merge($status, $existingFiles);
            $status['total_uploaded'] = array_sum([
                $status['merged_audio'],
                $status['transcription'],
                $status['summary'],
            ]);
            $status['upload_pending'] = $status['total_uploaded'] < 3;
        } else {
            $status['upload_pending'] = true;
        }

        return $status;
    }

    /**
     * 简化的上传内容到项目工作区方法.
     */
    private function uploadContentToProjectWorkspace(string $organizationCode, string $projectId, string $fileName, string $content, string $fileExtension, string $userId): void
    {
        $tempFilePath = sprintf('%s/%s.%s', sys_get_temp_dir(), uniqid('asr_upload_', true), $fileExtension);
        file_put_contents($tempFilePath, $content);

        try {
            $workspaceFileKey = $this->buildWorkspaceFileKey($userId, $projectId, $fileName);
            $uploadFile = new UploadFile($tempFilePath, '', $workspaceFileKey, false);
            $this->fileAppService->upload($organizationCode, $uploadFile, StorageBucketType::SandBox, false);

            // 保存文件记录
            $this->saveFileRecordToProject(new SaveFileRecordToProjectDTO(
                $organizationCode,
                $projectId,
                $uploadFile->getKey(),
                $fileName,
                filesize($tempFilePath),
                $fileExtension,
                $userId
            ));
        } finally {
            if (file_exists($tempFilePath)) {
                unlink($tempFilePath);
            }
        }
    }

    /**
     * 使用FFmpeg合并音频文件.
     *
     * @param array $audioFiles 音频文件路径列表
     * @param string $taskKey 任务键
     * @param string $ffmpegPath FFmpeg可执行路径
     * @param string $outputFile 输出文件路径
     * @return string 合并后文件路径
     * @throws InvalidArgumentException
     */
    private function mergeAudioWithFFmpeg(array $audioFiles, string $taskKey, string $ffmpegPath, string $outputFile): string
    {
        // 创建文件列表
        $listFile = sprintf('%s/runtime/asr/%s/file_list.txt', BASE_PATH, $taskKey);
        $listContent = '';
        foreach ($audioFiles as $file) {
            $listContent .= sprintf("file '%s'\n", str_replace("'", "'\"'\"'", $file));
        }

        if (! file_put_contents($listFile, $listContent)) {
            throw new InvalidArgumentException('创建文件列表失败');
        }

        // 删除可能存在的输出文件，避免FFmpeg询问覆盖
        if (file_exists($outputFile)) {
            unlink($outputFile);
        }

        // 执行合并命令（添加-y参数自动覆盖文件）
        $command = sprintf(
            '%s -y -f concat -safe 0 -i %s -c copy %s 2>&1',
            escapeshellcmd($ffmpegPath),
            escapeshellarg($listFile),
            escapeshellarg($outputFile)
        );

        $output = shell_exec($command);

        if (! file_exists($outputFile) || filesize($outputFile) === 0) {
            $this->logger->error('FFmpeg合并失败', [
                'command' => $command,
                'output' => $output,
                'output_file' => $outputFile,
            ]);
            throw new InvalidArgumentException(sprintf('音频文件合并失败: %s', $output ?? '未知错误'));
        }

        // 清理临时文件列表
        if (file_exists($listFile)) {
            unlink($listFile);
        }

        return $outputFile;
    }

    /**
     * 清理远程存储中的临时音频文件.
     *
     * 使用批量删除提高效率，静默处理删除失败的情况，不会影响主流程
     *
     * @param string $organizationCode 组织编码
     * @param string $businessDirectory 业务目录
     */
    private function cleanupRemoteAudioFiles(string $organizationCode, string $businessDirectory): void
    {
        // 获取目录下的音频文件列表
        $audioFiles = $this->getAudioFileList($organizationCode, $businessDirectory);
        if (empty($audioFiles)) {
            return;
        }

        // 收集需要删除的临时音频文件（分片文件和合并文件）
        $filesToDelete = [];
        foreach ($audioFiles as $audioFile) {
            $filename = $audioFile->getFilename();
            // 匹配数字命名的分片文件（如：1.webm, 2.webm）和合并文件（如：merged_1.webm）
            if (preg_match('/^(\d+|merged_\d+)\..+$/', $filename)) {
                $filesToDelete[] = $audioFile->getKey();
            }
        }

        // 使用通用删除方法
        $this->deleteRemoteFiles($organizationCode, $businessDirectory, $filesToDelete, '批量删除OSS临时音频文件');
    }

    /**
     * 查找FFmpeg路径.
     */
    private function findFFmpegPath(): ?string
    {
        $possiblePaths = [
            '/usr/local/bin/ffmpeg',
            '/usr/bin/ffmpeg',
            '/opt/homebrew/bin/ffmpeg',
            '/opt/local/bin/ffmpeg', // MacPorts
            '/snap/bin/ffmpeg', // Ubuntu Snap
            'ffmpeg', // 系统PATH中
        ];

        foreach ($possiblePaths as $path) {
            if ($path === 'ffmpeg') {
                // 检查系统PATH
                $result = shell_exec('which ffmpeg 2>/dev/null || where ffmpeg 2>/dev/null');
                if ($result && trim($result)) {
                    return trim($result);
                }
            } elseif (is_executable($path)) {
                return $path;
            }
        }

        $this->logger->error('FFmpeg未找到，音频合并将失败', [
            'searched_paths' => $possiblePaths,
            'install_commands' => [
                'macOS' => 'brew install ffmpeg',
                'Ubuntu/Debian' => 'sudo apt-get install ffmpeg',
                'CentOS/RHEL' => 'sudo yum install ffmpeg',
                'Docker' => 'RUN apt-get update && apt-get install -y ffmpeg',
            ],
        ]);

        return null;
    }

    /**
     * 处理首次任务提交.
     */
    private function handleFirstTimeSubmission(AsrTaskStatusDTO $taskStatus, string $organizationCode, string $projectId, string $userId): void
    {
        try {
            // 1. 处理音频文件（下载、合并），保留原始文件
            $audioResult = $this->processAudioForAsr($organizationCode, $taskStatus->businessDirectory, $taskStatus->taskKey, false);

            // 2. 上传合并文件到项目工作区
            $tempFilePath = sprintf('%s/runtime/asr/temp_%s.webm', BASE_PATH, $taskStatus->taskKey);
            $this->fileAppService->downloadByChunks($organizationCode, $audioResult['file_key'], $tempFilePath, StorageBucketType::SandBox->value);

            try {
                // 生成工作区文件路径和上传
                $timestamp = date('Y-m-d_H-i-s');
                $audioPrefix = trans('asr.file_names.merged_audio_prefix');
                $fileName = sprintf('%s_%s.webm', $audioPrefix, $timestamp);
                $workspaceFileKey = $this->buildWorkspaceFileKey($userId, $projectId, $fileName);

                $uploadFile = new UploadFile($tempFilePath, '', $workspaceFileKey, false);
                $this->fileAppService->upload($organizationCode, $uploadFile, StorageBucketType::SandBox, false);

                // 保存文件记录
                $this->saveFileRecordToProject(new SaveFileRecordToProjectDTO(
                    $organizationCode,
                    $projectId,
                    $uploadFile->getKey(),
                    $fileName,
                    filesize($tempFilePath),
                    'webm',
                    $userId
                ));

                $workspaceFileKey = $uploadFile->getKey();
            } finally {
                if (file_exists($tempFilePath)) {
                    unlink($tempFilePath);
                }
            }

            // 3. 删除原始目录下的所有文件
            $this->cleanupRemoteAudioFiles($organizationCode, $taskStatus->businessDirectory);

            // 4. 获取工作区文件URL并提交语音识别任务
            $fileLink = $this->fileAppService->getLink($organizationCode, $workspaceFileKey, StorageBucketType::SandBox);
            if (! $fileLink) {
                throw new InvalidArgumentException('无法获取工作区文件访问链接');
            }
            $workspaceFileUrl = $fileLink->getUrl();

            // 5. 更新taskStatus并提交任务
            $taskStatus->mergedAudioFileKey = $audioResult['file_key'];
            $taskStatus->workspaceFileKey = $workspaceFileKey;
            $taskStatus->workspaceFileUrl = $workspaceFileUrl;
            $taskStatus->speechTaskId = $this->submitLargeModelTask($workspaceFileUrl, $taskStatus->userId);
            $taskStatus->setTaskSubmitted();
            $taskStatus->updateStatus(AsrTaskStatusEnum::PROCESSING);
        } catch (Throwable $e) {
            $taskStatus->updateStatus(AsrTaskStatusEnum::FAILED, sprintf('Failed to submit task: %s', $e->getMessage()));
            throw new InvalidArgumentException(trans('asr.api.speech_recognition.submit_failed', ['error' => $e->getMessage()]));
        }
    }

    /**
     * 提交大模型任务
     */
    private function submitLargeModelTask(string $audioUrl, string $userId): string
    {
        // 构建大模型语音识别DTO
        $submitDTO = new LargeModelSpeechSubmitDTO();

        // 设置音频信息
        $audioDTO = new SpeechAudioDTO([
            'url' => $audioUrl,
            'format' => 'webm',
        ]);
        $submitDTO->setAudio($audioDTO);

        // 设置用户信息（可选）
        $userDTO = new SpeechUserDTO(['uid' => $userId]);
        $submitDTO->setUser($userDTO);

        // 设置请求配置参数（根据火山引擎文档优化）
        $requestConfig = config('asr.volcengine.request_config', []);
        $submitDTO->setRequest($requestConfig);
        /* @phpstan-ignore-next-line  */
        $submitDTO->setAccessToken(MAGIC_ACCESS_TOKEN);

        // 提交大模型任务
        $submitResult = $this->speechToTextService->submitLargeModelTask($submitDTO);

        // 保存语音识别服务返回的请求ID（大模型使用 request_id）
        $speechTaskId = $submitResult['request_id'] ?? null;
        if (! $speechTaskId) {
            throw new InvalidArgumentException(trans('asr.api.speech_recognition.request_id_missing'));
        }

        return $speechTaskId;
    }

    /**
     * 处理任务进度查询.
     */
    private function handleTaskProgressQuery(AsrTaskStatusDTO $taskStatus, string $organizationCode, string $projectId, int $retry): void
    {
        try {
            if (empty($taskStatus->speechTaskId)) {
                throw new InvalidArgumentException(trans('asr.api.speech_recognition.task_id_missing'));
            }

            // 构建查询DTO
            $queryDTO = new SpeechQueryDTO();
            $queryDTO->setTaskId($taskStatus->speechTaskId);

            // 设置认证信息
            /* @phpstan-ignore-next-line  */
            $asrAccessToken = MAGIC_ACCESS_TOKEN;
            if (empty($asrAccessToken)) {
                throw new InvalidArgumentException(trans('asr.api.token.access_token_not_configured'));
            }
            $queryDTO->setAccessToken($asrAccessToken);
            $queryDTO->setIps([]);
            $result = $this->speechToTextService->queryLargeModelResult($queryDTO);
            $queryResultDto = new HandleQueryResultDTO($result, $taskStatus, $organizationCode, $projectId, $retry);
            $this->handleQueryResult($queryResultDto);
        } catch (Throwable) {
            // 查询失败时不更新状态，保持processing状态等待下次查询
        }
    }

    /**
     * 处理查询结果.
     */
    private function handleQueryResult(HandleQueryResultDTO $dto): void
    {
        // 处理成功状态
        if ($dto->result->isSuccess()) {
            $this->handleSuccessResult($dto);
            return;
        }

        // 处理正在处理中的状态 - 无需任何操作，保持当前状态
        if ($dto->result->isProcessing()) {
            return;
        }

        // 处理需要重新提交的状态
        if ($dto->result->needsResubmit()) {
            $dto->taskStatus->updateStatus(AsrTaskStatusEnum::FAILED, trans('asr.api.speech_recognition.silent_audio_error'));
            return;
        }

        // 处理各种错误状态
        $this->handleErrorResult($dto->result, $dto->taskStatus);
    }

    /**
     * 处理成功的识别结果.
     */
    private function handleSuccessResult(HandleQueryResultDTO $dto): void
    {
        // 检查是否应该使用分人分句格式
        $useSpeakerSegmentation = $this->shouldUseSpeakerSegmentation($dto->result);

        $content = $this->extractTranscriptionContent($dto->result, $useSpeakerSegmentation);

        if (empty($content)) {
            // 状态码显示成功但没有获取到转录内容，记录日志但不修改任务状态
            $this->logger->warning('Large model recognition completed but no transcription content found', [
                'task_key' => $dto->taskStatus->taskKey,
                'request_id' => $dto->taskStatus->speechTaskId,
                'result' => $dto->result->toArray(),
            ]);
            return;
        }

        $dto->taskStatus->updateStatus(AsrTaskStatusEnum::COMPLETED, $content);
        // 上传文件到项目工作区
        $uploadDto = new UploadFilesToProjectWorkspaceDTO(
            $dto->organizationCode,
            $dto->taskStatus,
            $dto->projectId,
            $content,
            $dto->retry === 1
        );
        $this->uploadFilesToProjectWorkspace($uploadDto);
    }

    /**
     * 处理错误的识别结果.
     */
    private function handleErrorResult(SpeechRecognitionResultDTO $result, AsrTaskStatusDTO $taskStatus): void
    {
        // 处理已知错误状态码
        if ($result->getVolcengineStatusCode()) {
            $description = $result->getStatusDescription();
            $statusMessage = $result->getVolcengineMessage();
            $errorMessage = ! empty($statusMessage) ? $statusMessage : $description;
            $taskStatus->updateStatus(AsrTaskStatusEnum::FAILED, $errorMessage);
            return;
        }

        $statusCodeString = $result->getVolcengineStatusCodeString();
        if (! $statusCodeString) {
            // 没有状态码，可能是网络问题或响应格式异常 - 保持当前状态等待下次查询
            $this->logger->warning('No status code found in large model query result', [
                'task_key' => $taskStatus->taskKey,
                'request_id' => $taskStatus->speechTaskId,
                'result' => $result->toArray(),
            ]);
            return;
        }

        $statusMessage = $result->getVolcengineMessage();

        if (VolcengineStatusCode::isInternalServerError($statusCodeString)) {
            // 服务内部错误（550xxxx系列）
            $errorMessage = ! empty($statusMessage) ? $statusMessage : trans('asr.api.speech_recognition.internal_server_error', ['code' => $statusCodeString]);
        } else {
            // 未知状态码
            $errorMessage = ! empty($statusMessage) ? $statusMessage : trans('asr.api.speech_recognition.unknown_status_error', ['code' => $statusCodeString]);
        }

        $taskStatus->updateStatus(AsrTaskStatusEnum::FAILED, $errorMessage);
    }

    /**
     * 提取转录内容.
     *
     * @param SpeechRecognitionResultDTO $result 语音识别结果
     * @param bool $useSpeakerSegmentation 是否使用分人分句格式
     * @return string 转录内容
     */
    private function extractTranscriptionContent(SpeechRecognitionResultDTO $result, bool $useSpeakerSegmentation = false): string
    {
        // 如果启用了分人分句格式且有utterances数据，使用分人分句格式
        if ($useSpeakerSegmentation && $result->getResult()?->getUtterances()) {
            $speakerSegmentedContent = $this->extractSpeakerSegmentedContent($result);
            if (! empty($speakerSegmentedContent)) {
                return $speakerSegmentedContent;
            }
        }

        $text = $result->getText();
        if (! empty(trim($text))) {
            // 方式1：直接使用 result.text（推荐方式）
            return trim($text);
        }

        if ($result->getResult()?->getUtterances()) {
            // 方式2：从 utterances 分段中拼接完整文本
            $utteranceTexts = [];
            foreach ($result->getResult()?->getUtterances() as $utterance) {
                $utteranceText = $utterance->getText();
                if (! empty(trim($utteranceText))) {
                    $utteranceTexts[] = trim($utteranceText);
                }
            }
            return implode('', $utteranceTexts);
        }

        return '';
    }

    /**
     * 按说话人分段提取转录内容（分人分句格式）.
     *
     * @param SpeechRecognitionResultDTO $result 语音识别结果
     * @return string 格式化后的分人分句内容
     */
    private function extractSpeakerSegmentedContent(SpeechRecognitionResultDTO $result): string
    {
        $utterances = $result->getResult()?->getUtterances();
        if (empty($utterances)) {
            return '';
        }

        // 使用assembler处理分人分句格式
        return $this->speakerSegmentAssembler->assembleSegmentedContent($utterances);
    }

    /**
     * 检查是否应该使用分人分句格式.
     *
     * @param SpeechRecognitionResultDTO $result 语音识别结果
     * @return bool 是否应该使用分人分句格式
     */
    private function shouldUseSpeakerSegmentation(SpeechRecognitionResultDTO $result): bool
    {
        $utterances = $result->getResult()?->getUtterances();
        if (empty($utterances)) {
            return false;
        }

        // 使用assembler检查是否应该使用分人分句格式
        return $this->speakerSegmentAssembler->shouldUseSpeakerSegmentation($utterances);
    }

    /**
     * 通用的远程文件删除方法（复用cleanupRemoteAudioFiles的删除逻辑）.
     *
     * @param string $organizationCode 组织编码
     * @param string $businessDirectory 业务目录
     * @param array $filesToDelete 要删除的文件key数组
     * @param string $logContext 日志上下文描述
     */
    private function deleteRemoteFiles(string $organizationCode, string $businessDirectory, array $filesToDelete, string $logContext): void
    {
        if (empty($filesToDelete)) {
            return;
        }

        try {
            // 使用批量删除提高效率（复用cleanupRemoteAudioFiles的逻辑）
            $prefix = ltrim($businessDirectory, '/');
            $result = $this->fileDomainService->deleteObjectsByCredential(
                $prefix,
                $organizationCode,
                $filesToDelete,
                StorageBucketType::SandBox
            );

            // 记录删除结果
            $deletedCount = count($result['deleted'] ?? []);
            $errorCount = count($result['errors'] ?? []);

            if ($errorCount > 0) {
                $this->logger->warning("{$logContext}失败", [
                    'organization_code' => $organizationCode,
                    'business_directory' => $businessDirectory,
                    'files_to_delete' => $filesToDelete,
                    'deleted_count' => $deletedCount,
                    'error_count' => $errorCount,
                    'errors' => $result['errors'] ?? [],
                ]);
            }
        } catch (Throwable $e) {
            // 静默处理删除失败，不影响主流程
            $this->logger->warning("{$logContext}异常", [
                'organization_code' => $organizationCode,
                'business_directory' => $businessDirectory,
                'files_to_delete' => $filesToDelete,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 构建工作区文件键 - 通过项目实体获取正确的工作区目录.
     */
    private function buildWorkspaceFileKey(string $userId, string $projectId, string $fileName): string
    {
        // 获取项目实体 (如果项目不存在会自动抛出 PROJECT_NOT_FOUND 异常)
        $projectEntity = $this->projectDomainService->getProject((int) $projectId, $userId);
        // 从项目实体获取工作区目录
        $workDir = $projectEntity->getWorkDir();
        if (empty($workDir)) {
            throw new InvalidArgumentException(sprintf('项目 %s 的工作区目录为空', $projectId));
        }

        $relativePath = sprintf('%s/%s', 'asr-recordings', $fileName);
        return sprintf('%s/%s', trim($workDir, '/'), $relativePath);
    }

    // ==================== 辅助方法 ====================

    /**
     * 检查项目文件是否已存在
     * 支持缓存检查和数据库检查.
     *
     * @param string $projectId 项目ID
     * @param string $taskKey 任务Key
     * @return array 文件存在状态
     */
    private function checkProjectFilesExist(string $projectId, string $taskKey): array
    {
        $result = [
            'merged_audio' => false,
            'transcription' => false,
            'summary' => false,
        ];

        try {
            // 1. 先从Redis缓存检查
            $cacheKey = sprintf('asr:project_files:%s:%s', $projectId, $taskKey);
            $cachedResult = $this->redis->get($cacheKey);

            if ($cachedResult) {
                $cachedData = Json::decode($cachedResult);
                if (is_array($cachedData)) {
                    return array_merge($result, $cachedData);
                }
            }

            // 2. 从数据库检查项目文件表
            // 查询项目下 asr-recordings 目录中包含当前 task_key 的文件
            $projectFiles = $this->taskFileRepository->findFilesByDirectoryPath(
                (int) $projectId,
                sprintf('projects/%s/asr-recordings', $projectId),
                100
            );

            foreach ($projectFiles as $fileEntity) {
                $fileName = $fileEntity->getFileName();

                // 使用国际化的文件名前缀进行检查
                $audioPrefix = trans('asr.file_names.merged_audio_prefix');
                $transcriptionPrefix = trans('asr.file_names.transcription_prefix');
                $summaryPrefix = trans('asr.file_names.summary_prefix');

                if (str_contains($fileName, $audioPrefix) && str_ends_with($fileName, '.webm')) {
                    $result['merged_audio'] = true;
                } elseif (str_contains($fileName, $transcriptionPrefix) && str_ends_with($fileName, '.md')) {
                    $result['transcription'] = true;
                } elseif (str_contains($fileName, $summaryPrefix) && str_ends_with($fileName, '.md')) {
                    $result['summary'] = true;
                }
            }

            // 3. 缓存结果（5分钟有效期）
            $this->redis->setex($cacheKey, 300, Json::encode($result));
        } catch (Throwable $e) {
            // 检查失败时记录日志但不抛异常
            $this->logger->warning('Failed to check project files existence', [
                'project_id' => $projectId,
                'task_key' => $taskKey,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * 清理项目文件缓存
     * 当 retry=1 时调用，确保强制重新检查和上传文件.
     *
     * @param string $projectId 项目ID
     * @param string $taskKey 任务Key
     */
    private function clearProjectFileCache(string $projectId, string $taskKey): void
    {
        try {
            // 清理文件存在性检查的缓存
            $cacheKey = sprintf('asr:project_files:%s:%s', $projectId, $taskKey);
            $this->redis->del($cacheKey);
        } catch (Throwable $e) {
            // 清理缓存失败只记录日志，不影响主流程
            $this->logger->warning('Failed to clear project file cache', [
                'project_id' => $projectId,
                'task_key' => $taskKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 保存文件记录到项目文件表.
     */
    private function saveFileRecordToProject(SaveFileRecordToProjectDTO $dto): void
    {
        try {
            // 每次上传前检查并确保ASR目录存在
            $parentId = $this->ensureAsrDirectoryExists($dto->organizationCode, $dto->projectId, $dto->userId);

            if (! $parentId) {
                $this->logger->warning('ASR目录创建失败，文件将保存在根目录', [
                    'file_name' => $dto->fileName,
                    'project_id' => $dto->projectId,
                ]);
            }

            // 创建文件实体
            $taskFileEntity = new TaskFileEntity([
                'user_id' => $dto->userId,
                'organization_code' => $dto->organizationCode,
                'project_id' => (int) $dto->projectId,
                'topic_id' => 0,
                'task_id' => 0,
                'file_type' => 'user_upload',
                'file_name' => $dto->fileName,
                'file_extension' => $dto->fileExtension,
                'file_key' => $dto->fileKey,
                'file_size' => $dto->fileSize,
                'external_url' => '',
                'storage_type' => 'workspace', // 工作区存储类型
                'is_hidden' => false,
                'is_directory' => false,
                'sort' => 0,
                'parent_id' => $parentId, // 设置父目录ID
                'source' => 2, // 2-项目目录
                'metadata' => Json::encode([
                    'asr_task' => true,
                    'created_by' => 'asr_summary_api',
                    'created_at' => date('Y-m-d H:i:s'),
                ]),
            ]);

            // 插入或忽略（防重复）
            $savedEntity = $this->taskFileRepository->insertOrIgnore($taskFileEntity);

            if (! $savedEntity) {
                $this->logger->warning('文件记录可能已存在，跳过插入', [
                    'file_name' => $dto->fileName,
                    'project_id' => $dto->projectId,
                ]);
            }
        } catch (Throwable $e) {
            // 保存文件记录失败只记录日志，不影响主流程
            $this->logger->error('保存文件记录到项目失败', [
                'project_id' => $dto->projectId,
                'file_key' => $dto->fileKey,
                'file_name' => $dto->fileName,
                'error' => $e->getMessage(),
                'stack_trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * 确保ASR目录存在，如果不存在则创建.
     *
     * @param string $organizationCode 组织代码
     * @param string $projectId 项目ID
     * @param string $userId 用户ID
     * @return null|int 目录的文件ID，如果创建失败返回null
     */
    private function ensureAsrDirectoryExists(string $organizationCode, string $projectId, string $userId): ?int
    {
        try {
            // 构建目录key来查找现有目录
            $directoryKeyPattern = sprintf('%s/', trim($this->buildWorkspaceFileKey($userId, $projectId, ''), '/'));

            $this->logger->debug('构建ASR目录路径', [
                'directory_key' => $directoryKeyPattern,
                'project_id' => $projectId,
            ]);

            // 检查ASR目录是否已存在
            $existingDirectory = $this->taskFileRepository->getByProjectIdAndFileKey((int) $projectId, $directoryKeyPattern);

            if ($existingDirectory && $existingDirectory->getIsDirectory()) {
                return $existingDirectory->getFileId();
            }

            // 确保工作区根目录存在，作为ASR目录的父目录
            $workspaceRootId = $this->ensureWorkspaceRootDirectoryExists($organizationCode, $projectId, $userId);

            // 获取国际化的目录名称
            $directoryName = trans('asr.directory.recordings_summary_folder');
            if (empty($directoryName) || $directoryName === 'asr.directory.recordings_summary_folder') {
                // 如果翻译失败，使用默认名称
                $directoryName = '录音总结';
                $this->logger->warning('ASR目录国际化翻译失败，使用默认名称', [
                    'default_name' => $directoryName,
                    'project_id' => $projectId,
                ]);
            } else {
                $this->logger->debug('获取ASR目录国际化名称成功', [
                    'directory_name' => $directoryName,
                    'project_id' => $projectId,
                ]);
            }

            // 创建目录实体，使用工作区根目录作为父目录
            $directoryEntity = new TaskFileEntity([
                'user_id' => $userId,
                'organization_code' => $organizationCode,
                'project_id' => (int) $projectId,
                'topic_id' => 0,
                'task_id' => 0,
                'file_type' => 'directory',
                'file_name' => $directoryName,
                'file_extension' => '',
                'file_key' => $directoryKeyPattern,
                'file_size' => 0,
                'external_url' => '',
                'storage_type' => 'workspace',
                'is_hidden' => false,
                'is_directory' => true,
                'sort' => 0,
                'parent_id' => $workspaceRootId, // 使用工作区根目录作为父目录
                'source' => 2, // 2-项目目录
                'metadata' => Json::encode([
                    'asr_directory' => true,
                    'created_by' => 'asr_summary_api',
                    'created_at' => date('Y-m-d H:i:s'),
                    'directory_type' => 'asr_recordings',
                ]),
            ]);

            // 插入目录记录
            $savedEntity = $this->taskFileRepository->insertOrIgnore($directoryEntity);

            if ($savedEntity) {
                return $savedEntity->getFileId();
            }

            // 如果插入失败，再次尝试查找（可能被其他进程创建了）
            $existingDirectory = $this->taskFileRepository->getByProjectIdAndFileKey((int) $projectId, $directoryKeyPattern);
            return $existingDirectory && $existingDirectory->getIsDirectory() ? $existingDirectory->getFileId() : null;
        } catch (Throwable $e) {
            $this->logger->error('Failed to ensure ASR directory exists', [
                'project_id' => $projectId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * 校验目录是否属于当前用户.
     *
     * @param string $directory 要校验的目录路径
     * @param string $userId 当前用户ID
     * @throws InvalidArgumentException 当目录不属于当前用户时抛出异常
     */
    private function validateDirectoryOwnership(string $directory, string $userId): void
    {
        // 去除首尾空白字符
        $directory = trim($directory);

        // 规范化路径格式
        if (! str_starts_with($directory, '/')) {
            $directory = sprintf('/%s', $directory);
        }

        // 🔧 支持两种路径格式：
        // 1. 简化路径: /asr/recordings/.../
        // 2. 完整路径: /DT001/.../asr/recordings/.../
        $isValidAsrPath = false;

        if (str_starts_with($directory, '/asr/recordings')) {
            // 简化路径格式
            $isValidAsrPath = true;
        } elseif (str_contains($directory, '/asr/recordings')) {
            // 完整路径格式，包含组织编码前缀
            $isValidAsrPath = true;
        }

        if (! $isValidAsrPath) {
            throw new InvalidArgumentException(trans('asr.api.directory.invalid_asr_path'));
        }

        // 安全检查：防止路径遍历攻击
        if (str_contains($directory, '..')) {
            throw new InvalidArgumentException(trans('asr.api.directory.security_path_error'));
        }

        // 关键检查：目录路径必须包含当前用户ID，确保用户只能操作自己的目录
        if (! str_contains($directory, $userId)) {
            throw new InvalidArgumentException(trans('asr.api.directory.ownership_error'));
        }

        // 进一步验证：检查用户ID是否在合适的位置
        // 支持两种目录结构:
        // 1. 简化路径: /asr/recordings/{date}/{user_id}/{task_key}/...
        // 2. 完整路径: /DT001/.../asr/recordings/{date}/{user_id}/{task_key}/...
        $pathParts = explode('/', trim($directory, '/'));

        // 找到asr/recordings的位置
        $asrIndex = -1;
        for ($i = 0; $i < count($pathParts) - 1; ++$i) {
            if ($pathParts[$i] === 'asr' && $pathParts[$i + 1] === 'recordings') {
                $asrIndex = $i;
                break;
            }
        }

        if ($asrIndex === -1) {
            throw new InvalidArgumentException(trans('asr.api.directory.invalid_structure'));
        }

        // 检查asr/recordings之后是否有足够的路径段: date, user_id, task_key
        $remainingParts = array_slice($pathParts, $asrIndex + 2);
        if (count($remainingParts) < 3) {
            throw new InvalidArgumentException(trans('asr.api.directory.invalid_structure_after_recordings'));
        }

        // 检查用户ID是否出现在路径中的合理位置
        $userIdFound = false;
        foreach ($pathParts as $part) {
            if ($part === $userId) {
                $userIdFound = true;
                break;
            }
        }

        if (! $userIdFound) {
            throw new InvalidArgumentException(trans('asr.api.directory.user_id_not_found'));
        }
    }

    /**
     * 确保工作区根目录存在，如果不存在则创建.
     *
     * 使用TaskFileDomainService的findOrCreateProjectRootDirectory方法
     * 获取项目实际的根目录ID，确保层级关系正确
     *
     * @param string $organizationCode 组织代码
     * @param string $projectId 项目ID
     * @param string $userId 用户ID
     * @return int 项目工作区根目录的实际file_id
     */
    private function ensureWorkspaceRootDirectoryExists(string $organizationCode, string $projectId, string $userId): int
    {
        // 获取项目实体以获取workDir
        $projectEntity = $this->projectDomainService->getProject((int) $projectId, $userId);
        $workDir = $projectEntity->getWorkDir();

        if (empty($workDir)) {
            throw new InvalidArgumentException(sprintf('项目 %s 的工作区目录为空', $projectId));
        }

        // 使用TaskFileDomainService查找或创建项目根目录
        return $this->taskFileDomainService->findOrCreateProjectRootDirectory(
            (int) $projectId,
            $workDir,
            $userId,
            $organizationCode
        );
    }
}
