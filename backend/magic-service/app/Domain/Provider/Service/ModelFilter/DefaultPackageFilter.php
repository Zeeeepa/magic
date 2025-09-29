<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace App\Domain\Provider\Service\ModelFilter;

use App\Infrastructure\Core\DataIsolation\BaseDataIsolation;

class DefaultPackageFilter implements PackageFilterInterface
{
    /**
     * 默认实现：不进行任何过滤，直接返回原始模型列表.
     */
    public function getCurrentPackage(string $organizationCode): ?string
    {
        return null;
    }

    public function getCurrentSubscription(BaseDataIsolation $dataIsolation): array
    {
        return [
            'id' => '',
            'info' => [],
        ];
    }
}
