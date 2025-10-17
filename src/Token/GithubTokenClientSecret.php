<?php

declare(strict_types=1);

namespace Github\Utils\Token;

/**
 * Represents a client id/secret token.
 */
class GithubTokenClientSecret extends GithubTokenAbstract implements GithubTokenClientSecretInterface
{
    protected string $id;

    public function __construct(protected string $client, protected string $secret)
    {
        $this->id = 'cst#'.md5($client.$secret);
    }

    public function getId(bool $short = false): string
    {
        return $short ? substr($this->id, 0, 8) : $this->id;
    }

    public function getClientID(): string
    {
        return $this->client;
    }

    public function getClientSecret(): string
    {
        return $this->secret;
    }
}
