<?php

namespace Github\Utils;

use Github\Utils\Token\GithubTokenInterface;
use Github\Utils\Token\GithubTokenNull;
use Github\Utils\Token\GithubTokenBasic;
use Github\Utils\Token\GithubTokenClientSecret;

/**
 * Stores and rotates Github tokens.
 *
 * @package AwesomeHub
 */
class GithubTokenPool implements GithubTokenPoolInterface
{
    const TOKEN_NULL        = 'null';
    const TOKEN_BASIC       = 'basic';
    const TOKEN_OAUTH_URL   = 'oauth_url';

    const SUPPORTS          = [
        self::TOKEN_NULL,
        self::TOKEN_BASIC,
        self::TOKEN_OAUTH_URL
    ];

    /**
     * @var string
     */
    protected $pool;

    /**
     * @var array[GithubTokenInterface]
     */
    protected $current = [];

    /**
     * Constructor.
     *
     * @param string $pool
     * @param GithubTokenInterface[] $tokens
     * @param bool $null Whether or not to add a 'Null' token at the end of the chain,
     *                      this will usually allow unauthenticated requests
     */
    public function __construct($pool, array $tokens = [], $null = true)
    {
        $this->pool = $pool;

        if($null){
            $tokens[] = [
                self::TOKEN_NULL
            ];
        }

        if(count($tokens) > 0){
            $this->setTokens($tokens, false);
        }
    }

    /**
     * @inheritdoc
     */
    public function setPool($pool)
    {
        $this->pool = $pool;
    }

    /**
     * @inheritdoc
     */
    public function setTokens(array $tokens, $purge = false)
    {
        if(!empty($tokens)){
            $tokens = $this->verify($tokens);
        }

        if($purge){
            $this->write($tokens);
            return;
        }

        $this->merge($tokens, false);
    }

    /**
     * @inheritdoc
     */
    public function getTokens()
    {
        return $this->read();
    }

    /**
     * @inheritdoc
     * @throws \LogicException
     */
    public function getToken($scope)
    {
        $tokens = $this->read();
        if(count($tokens) == 0){
            throw new \LogicException("No valid tokens found in the pool");
        }

        $best = $bestTime = null;
        foreach ($tokens as $token){
            $access = $token->canAccess($scope);
            if(true === $access){
                return $this->current[$scope] = $token;
            }

            if(null === $best || $access < $bestTime){
                $best = $token;
                $bestTime = $access;
            }
        }

        return $this->current[$scope] = $best;
    }

    /**
     * @inheritdoc
     * @throws \Exception
     */
    public function nextToken($scope, $reset)
    {
        if(time() >= $reset){
            throw new \LogicException("Token reset time cannot be in the past.");
        }

        if(empty($this->current[$scope])){
            throw new \Exception(sprintf("No current token found for scope '%s'; You need to call getToken() first", $scope));
        }

        $token = $this->current[$scope];
        $token->setReset($scope, $reset);
        $this->merge([$token], true);

        return $this->getToken($scope);
    }

    /**
     * Verifies input tokens.
     *
     * @param array $tokens
     * @return GithubTokenInterface[]
     */
    protected function verify(array $tokens)
    {
        $instances = [];
        foreach($tokens as $token){
            if($token instanceof GithubTokenInterface){
                $instances[$token->getId()] = $token;
                continue;
            }

            if(empty($token[0])){
                throw new \UnexpectedValueException(sprintf(
                    "Expected an instance of 'GithubTokenInterface' or an array with at least 1 elements, got '%s'",
                    var_export($token, true)
                ));
            }

            switch ($token[0]){
                case self::TOKEN_NULL;
                    $instance = new GithubTokenNull();
                    break;

                case self::TOKEN_BASIC:
                    $instance = new GithubTokenBasic($token[1], $token[2]);
                    break;

                case self::TOKEN_OAUTH_URL;
                    $instance = new GithubTokenClientSecret($token[1], $token[2]);
                    break;

                default:
                    throw new \UnexpectedValueException(sprintf("Unsupported github token type '%s'", $token[0]));
            }

            // Preventing duplicate instances
            $instances[$instance->getId()] = $instance;
        }

        return array_values($instances);
    }

    /**
     * Fetches the pool tokens.
     *
     * @return GithubTokenInterface[]
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    protected function read()
    {
        if(empty($this->pool) || !file_exists($this->pool)){
            return [];
        }

        if(false === $pool = file_get_contents($this->pool)){
            throw new \RuntimeException(sprintf("Unable to read tokens pool file; %s", $this->pool));
        }

        $pool = unserialize($pool);
        if(!is_array($pool)){
            throw new \UnexpectedValueException(sprintf("Unexpected pool data; Expected array but got %s", gettype($pool)));
        }

        $nullToken = null;
        foreach ($pool as $id => $token){
            if(!$token instanceof GithubTokenInterface){
                throw new \UnexpectedValueException(sprintf("The tokens pool has an invalid token instance at index#%s", $id));
            }

            if($token instanceof GithubTokenNull){
                $nullToken = $token;
                unset($pool[$id]);
            }
        }

        // Pushback null token
        if($nullToken){
            $pool[$nullToken->getId()] = $nullToken;
        }

        return $pool;
    }

    /**
     * Updates the pool tokens.
     *
     * @param GithubTokenInterface[] $tokens
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    protected function write(array $tokens)
    {
        foreach ($tokens as $index => $token){
            if(!$token instanceof GithubTokenInterface){
                throw new \InvalidArgumentException(sprintf(
                    "The provided tokens for write has an invalid token at index#%s", $index
                ));
            }
        }

        if(false === file_put_contents($this->pool, serialize($tokens), LOCK_EX)){
            throw new \RuntimeException(sprintf("Unable to write tokens pool file; %s", $this->pool));
        }
    }

    /**
     * Merges the given tokens into the pool while preserving the order
     *  of the given token.
     *
     * @param array $tokens
     * @param bool $overwrite Whether to overwrite existing tokens.
     */
    protected function merge(array $tokens, $overwrite = false)
    {
        if(empty($tokens)){
            return;
        }

        $pool = $this->read();
        foreach ($tokens as $index => $token){
            if(!$token instanceof GithubTokenInterface){
                throw new \InvalidArgumentException(sprintf(
                    "The provided tokens for merge has an invalid token at index#%s", $index
                ));
            }

            $id = $token->getId();
            if(!isset($pool[$id]) || $overwrite){
                $pool[$id] = $token;
                continue;
            }
        }

        $this->write($pool);
    }
}
