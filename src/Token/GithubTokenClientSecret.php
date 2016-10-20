<?php

namespace Github\Utils\Token;

/**
 * Represents a client id/secret token.
 */
class GithubTokenClientSecret extends GithubTokenAbstract implements GithubTokenClientSecretInterface
{
    /**
     * @var string
     */
    protected $id;

    /**
     * @var string
     */
    protected $client;

    /**
     * @var string
     */
    protected $secret;

    /**
     * Constructor.
     *
     * @param string $client
     * @param string $secret
     */
    public function __construct($client, $secret)
    {
        $this->client = $client;
        $this->secret = $secret;
        $this->id = md5($this->client.$this->secret);
    }

    /**
     * {@inheritdoc}
     */
    public function getId($short = false)
    {
        return $short ? substr($this->id, 0, 8) : $this->id;
    }

    /**
     * {@inheritdoc}
     */
    public function getClientID()
    {
        return $this->client;
    }

    /**
     * {@inheritdoc}
     */
    public function getClientSecret()
    {
        return $this->secret;
    }
}
