<?php

namespace Github\Utils\Token;

/**
 * Represents an empty token for making unauthenticated requests.
 *
 * @package AwesomeHub
 */
class GithubTokenNull extends GithubTokenAbstract implements GithubTokenInterface
{
    /**
     * @inheritdoc
     */
    public function getId($short = false)
    {
        return 'null';
    }
}
