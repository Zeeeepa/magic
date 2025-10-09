<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace App\Application\Speech\Enum;

/**
 * ASR任务状态枚举.
 */
enum AsrTaskStatusEnum: string
{
    case COMPLETED = 'completed';            // 已完成
    case FAILED = 'failed';                  // 失败

    /**
     * 获取状态描述.
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::COMPLETED => '已完成',
            self::FAILED => '失败',
        };
    }

    /**
     * 检查是否为成功状态
     */
    public function isSuccess(): bool
    {
        return $this === self::COMPLETED;
    }

    /**
     * 检查任务是否已提交（基于状态判断）.
     */
    public function isTaskSubmitted(): bool
    {
        return $this === self::COMPLETED;
    }

    /**
     * 从字符串创建枚举.
     */
    public static function fromString(string $status): self
    {
        return self::tryFrom($status) ?? self::FAILED;
    }
}
