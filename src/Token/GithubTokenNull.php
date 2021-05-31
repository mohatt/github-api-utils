<?php

declare(strict_types=1);

namespace Github\Utils\Token;

/**
 * Represents an empty token for making unauthenticated requests.
 */
class GithubTokenNull extends GithubTokenAbstract
{
    /**
     * {@inheritdoc}
     */
    public function getId(bool $short = false): string
    {
        return 'null';
    }
}
