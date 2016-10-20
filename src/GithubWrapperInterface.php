<?php

namespace Github\Utils;

use Github;
use Psr\Log\LoggerInterface;

/**
 * Interface for a GithubWrapper.
 *
 * @package AwesomeHub
 */
interface GithubWrapperInterface
{
    /**
     * @return Github\Client
     */
    public function getClient();

    /**
     * @return Github\ResultPager
     */
    public function getPager();

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger);

    /**
     * @param GithubTokenPoolInterface $tokenPool
     */
    public function setTokenPool(GithubTokenPoolInterface $tokenPool);

    /**
     * @return GithubTokenPoolInterface
     */
    public function getTokenPool();

    /**
     * Gets the currently active token.
     *
     * @param GithubTokenInterface
     */
    public function getToken();

    /**
     * Sets a custom token to be used for authentication.
     *
     * @param Token\GithubTokenInterface|null $token
     */
    public function setToken(Token\GithubTokenInterface $token = null);

    /**
     * Checks whether or not a custom token has been defined.
     *
     * @param bool
     */
    public function hasCustomToken();

    /**
     * Checks whether or not the client has been authenticated.
     *
     *  Please note that this method will still return true if the client
     *  has been authenticated with a GithubTokenNull token. However, you could use
     *  getToken() to get the current token and check if it's an instance of GithubTokenNull.
     *
     * @param bool
     */
    public function isAuthenticated();

    /**
     * Calls an API method identified by a given path.
     *
     * @param string $path Path to an API method
     * @param array $args Arguments for that method
     * @param bool $full Causes the method to fetch all results not just the first page
     * @return mixed
     */
    public function api($path, array $args = [], $full = false);

    /**
     * Checks if there is a next page.
     *
     * @return bool
     */
    public function hasNext();

    /**
     * Fetches the next page.
     *
     * @return mixed
     */
    public function next();

    /**
     * Fetches the last page.
     *
     * @return mixed
     */
    public function last();
}