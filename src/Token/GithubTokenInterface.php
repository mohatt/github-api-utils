<?php

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
     *
     * @return string
     */
    public function getId($short = false);

    /**
     * Checks whether the token can be used for {$scope} api calls or not. Returns true if it can,
     * otherwise it returns the number of seconds remaining until reset.
     *
     * @param string $scope
     *
     * @return true|int
     */
    public function canAccess($scope);

    /**
     * Sets the token reset time and marks it as expired for {$scope} api calls.
     *
     * @param string $scope
     * @param int    $reset
     */
    public function setReset($scope, $reset);
}
