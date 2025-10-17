<?php

declare(strict_types=1);

namespace Github\Utils\Token;

/**
 * Represents a basic token with username and password.
 */
class GithubTokenPersonal extends GithubTokenAbstract implements GithubTokenPersonalInterface
{
    protected string $id;

    public function __construct(protected string $token)
    {
        $this->id = 'pat#'.md5($token);
    }

    public function getId(bool $short = false): string
    {
        return $short ? substr($this->id, 0, 8) : $this->id;
    }

    public function getToken(): string
    {
        return $this->token;
    }
}
