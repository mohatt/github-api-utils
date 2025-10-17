<?php

declare(strict_types=1);

namespace Github\Utils\Token;

abstract class GithubTokenAbstract implements GithubTokenInterface
{
    protected array $reset = [];

    public function canAccess(string $scope): bool|int
    {
        if (!isset($this->reset[$scope])) {
            return true;
        }

        $diff = $this->reset[$scope] - time();

        return $diff > 0 ? $diff : true;
    }

    public function setReset(string $scope, int $reset): void
    {
        $this->reset[$scope] = $reset;
    }
}
