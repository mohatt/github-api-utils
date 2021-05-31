<?php

declare(strict_types=1);

namespace Github\Utils;

use Github\Utils\Token\GithubTokenInterface;

/**
 * Interface for a GithubTokenPool.
 */
interface GithubTokenPoolInterface
{
    /**
     * Sets the pool tokens.
     *
     * @param GithubTokenInterface[] $tokens
     */
    public function setTokens(array $tokens, bool $purge = false);

    /**
     * Fetches pool tokens.
     *
     * @return GithubTokenInterface[]
     */
    public function getTokens(): array;

    /**
     * Gets a token for the given {$scope}.
     *
     * @throws \LogicException
     */
    public function getToken(string $scope): GithubTokenInterface;

    /**
     * Marks the current {$scope} token as expired and gets a new one from the pool.
     *
     * @param int $reset Expired token reset time
     *
     * @throws \LogicException
     */
    public function nextToken(string $scope, int $reset): GithubTokenInterface;
}
