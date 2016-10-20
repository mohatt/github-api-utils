<?php

namespace Github\Utils\Token;

/**
 * Interface for a GithubTokenBasic.
 *
 * @package AwesomeHub
 */
interface GithubTokenBasicInterface extends GithubTokenInterface
{
    /**
     * Gets the github username.
     *
     * @return string
     */
    public function getUsername();

    /**
     * Gets the github password/token.
     *
     * @return string
     */
    public function getPassword();
}
