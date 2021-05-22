<?php

namespace Github\Utils\Token;

/**
 * Represents a basic token with username and password.
 */
class GithubTokenPersonal extends GithubTokenAbstract implements GithubTokenPersonalInterface
{
    /**
     * @var string
     */
    protected $id;

    /**
     * @var string
     */
    protected $token;

    /**
     * Constructor.
     *
     * @param string $token
     */
    public function __construct($token)
    {
        $this->token = $token;
        $this->id = 'pat#' . md5($this->token);
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
    public function getToken()
    {
        return $this->token;
    }
}
