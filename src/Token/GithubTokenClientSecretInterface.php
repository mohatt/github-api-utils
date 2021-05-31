<?php

declare(strict_types=1);

namespace Github\Utils\Token;

/**
 * Interface for a GithubTokenClientSecret.
 */
interface GithubTokenClientSecretInterface extends GithubTokenInterface
{
    /**
     * Gets the client id.
     */
    public function getClientID(): string;

    /**
     * Gets the client secret.
     */
    public function getClientSecret(): string;
}
