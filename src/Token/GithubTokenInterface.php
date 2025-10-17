<?php

declare(strict_types=1);

namespace Github\Utils\Token;

/**
 * Interface for a GithubToken.
 */
interface GithubTokenInterface
{
    /**
     * Gets the token unique identifier.
     *
     * @param bool $short Don't return the whole id
     */
    public function getId(bool $short = false): string;

    /**
     * Checks whether the token can be used for {$scope} api calls or not. Returns true if it can,
     * otherwise it returns the number of seconds remaining until reset.
     */
    public function canAccess(string $scope): bool|int;

    /**
     * Sets the token reset time and marks it as expired for {$scope} api calls.
     */
    public function setReset(string $scope, int $reset);
}
