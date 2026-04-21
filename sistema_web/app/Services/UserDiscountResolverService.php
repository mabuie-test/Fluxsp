<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\UserDiscountRepository;

final class UserDiscountResolverService
{
    public function __construct(private readonly UserDiscountRepository $repo = new UserDiscountRepository()) {}

    public function resolve(int $userId, int $workTypeId): ?array
    {
        $discounts = $this->repo->findActiveForUser($userId, $workTypeId);
        return $discounts[0] ?? null;
    }
}
