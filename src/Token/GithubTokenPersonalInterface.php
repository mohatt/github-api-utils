<?php

namespace Github\Utils\Token;

/**
 * Interface for a GithubTokenBasic.
 */
interface GithubTokenPersonalInterface extends GithubTokenInterface
{
    /**
     * Gets the github token.
     *
     * @return string
     */
    public function getToken();
}
