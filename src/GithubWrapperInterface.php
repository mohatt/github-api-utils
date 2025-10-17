<?php

declare(strict_types=1);

namespace Github\Utils;

use Github;

/**
 * Interface for a GithubWrapper.
 */
interface GithubWrapperInterface
{
    /**
     * Gets Github client instance.
     */
    public function getClient(): Github\Client;

    /**
     * Gets Github pager instance.
     */
    public function getPager(): Github\ResultPager;

    /**
     * Gets tokenPool instance.
     */
    public function getTokenPool(): GithubTokenPoolInterface;

    /**
     * Gets the currently active token.
     */
    public function getToken(): Token\GithubTokenInterface;

    /**
     * Sets tokenPool instance.
     */
    public function setTokenPool(GithubTokenPoolInterface $tokenPool): void;

    /**
     * Sets a custom token to be used for authentication, if no token is provided it creates a GithubTokenNull token.
     */
    public function setToken(?Token\GithubTokenInterface $token = null): void;

    /**
     * Checks whether or not a custom token has been defined.
     */
    public function hasCustomToken(): bool;

    /**
     * Checks whether or not the client has been authenticated.
     */
    public function isAuthenticated(): bool;

    /**
     * Calls an API method identified by a given path.
     *
     * @param string $path Path to an API method
     * @param array  $args Arguments for that method
     * @param bool   $full Causes the method to fetch all results not just the first page
     */
    public function api(string $path, array $args = [], bool $full = false): mixed;

    /**
     * Checks if there is a next page.
     */
    public function hasNext(): bool;

    /**
     * Fetches the next page.
     */
    public function next(): mixed;

    /**
     * Fetches the last page.
     */
    public function last(): mixed;
}
