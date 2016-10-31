<?php

namespace Github\Utils\Token;

/**
 * Interface for a GithubTokenClientSecret.
 */
interface GithubTokenClientSecretInterface extends GithubTokenInterface
{
    /**
     * Gets the client id.
     *
     * @return string
     */
    public function getClientID();

    /**
     * Gets the client secret.
     *
     * @return string
     */
    public function getClientSecret();
}