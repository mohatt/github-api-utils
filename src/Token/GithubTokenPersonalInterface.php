<?php

declare(strict_types=1);

namespace Github\Utils\Token;

/**
 * Interface for a GithubTokenBasic.
 */
interface GithubTokenPersonalInterface extends GithubTokenInterface
{
    /**
     * Gets the github token.
     */
    public function getToken(): string;
}
