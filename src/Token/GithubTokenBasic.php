<?php

namespace Github\Utils\Token;

/**
 * Represents a basic token with username and password.
 */
class GithubTokenBasic extends GithubTokenAbstract implements GithubTokenBasicInterface
{
    /**
     * @var string
     */
    protected $id;

    /**
     * @var string
     */
    protected $username;

    /**
     * @var string
     */
    protected $password;

    /**
     * Constructor.
     *
     * @param string $username
     * @param string $password
     */
    public function __construct($username, $password)
    {
        $this->username = $username;
        $this->password = $password;
        $this->id = md5($this->username.$this->password);
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
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * {@inheritdoc}
     */
    public function getPassword()
    {
        return $this->password;
    }
}
