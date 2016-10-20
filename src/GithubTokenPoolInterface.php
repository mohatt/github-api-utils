<?php

namespace Github\Utils;

/**
 * Interface for a GithubTokenPool.
 *
 * @package AwesomeHub
 */
interface GithubTokenPoolInterface
{
    /**
     * Sets the pool path.
     *
     * @param string $pool
     */
    public function setPool($pool);

    /**
     * Sets the pool tokens.
     *
     * @param Token\GithubTokenInterface[]|array[] $tokens
     * @param bool $purge
     */
    public function setTokens(array $tokens, $purge = false);

    /**
     * Fetches pool tokens.
     *
     * @return Token\GithubTokenInterface[]
     */
    public function getTokens();

    /**
     * Gets a token for the given {$scope}.
     *
     * @param string $scope
     * @return Token\GithubTokenInterface
     */
    public function getToken($scope);

    /**
     * Marks the current {$scope} token as expired and gets a new one from the pool.
     *
     * @param string $scope
     * @param int $reset Expired token reset time
     * @return Token\GithubTokenInterface
     */
    public function nextToken($scope, $reset);
}