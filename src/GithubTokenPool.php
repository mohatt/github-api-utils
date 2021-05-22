<?php

namespace Github\Utils;

use Github\Utils\Token\GithubTokenInterface;
use Github\Utils\Token\GithubTokenNull;

/**
 * Stores and rotates Github tokens.
 */
class GithubTokenPool implements GithubTokenPoolInterface
{
    /**
     * @var string
     */
    protected $pool;

    /**
     * @var array[$scope: Token\GithubTokenInterface]
     */
    protected $current = [];

    /**
     * Constructor.
     *
     * @param string                 $pool   Pool file path
     * @param GithubTokenInterface[] $tokens Initial tokens as list of token instance
     */
    public function __construct($pool, array $tokens = [])
    {
        $this->pool = $pool;

        if (count($tokens) > 0) {
            $this->setTokens($tokens, false);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setPool($pool)
    {
        $this->pool = $pool;
    }

    /**
     * {@inheritdoc}
     */
    public function setTokens(array $tokens, $purge = false)
    {
        if ($purge) {
            $this->write($tokens);

            return;
        }

        $this->merge($tokens, false);
    }

    /**
     * {@inheritdoc}
     */
    public function getTokens()
    {
        return $this->read();
    }

    /**
     * {@inheritdoc}
     *
     * @throws \LogicException
     */
    public function getToken($scope)
    {
        $tokens = $this->read();
        if (count($tokens) == 0) {
            throw new \LogicException('No valid tokens found in the pool');
        }

        $best = $bestTime = null;
        foreach ($tokens as $token) {
            $access = $token->canAccess($scope);
            if (true === $access) {
                return $this->current[$scope] = $token;
            }

            if (null === $best || $access < $bestTime) {
                $best = $token;
                $bestTime = $access;
            }
        }

        return $this->current[$scope] = $best;
    }

    /**
     * {@inheritdoc}
     *
     * @throws \Exception
     */
    public function nextToken($scope, $reset)
    {
        if (time() >= $reset) {
            throw new \LogicException('Token reset time cannot be in the past.');
        }

        if (empty($this->current[$scope])) {
            throw new \Exception(sprintf("No current token found for scope '%s'; You need to call getToken() first", $scope));
        }

        $token = $this->current[$scope];
        $token->setReset($scope, $reset);
        $this->merge([$token], true);

        return $this->getToken($scope);
    }

    /**
     * Fetches the pool tokens.
     *
     * @return GithubTokenInterface[]
     *
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    protected function read()
    {
        if (empty($this->pool) || !file_exists($this->pool)) {
            return [];
        }

        if (false === $pool = file_get_contents($this->pool)) {
            throw new \RuntimeException(sprintf('Unable to read tokens pool file; %s', $this->pool));
        }

        $pool = unserialize($pool);
        if (!is_array($pool)) {
            throw new \UnexpectedValueException(sprintf('Unexpected pool data; Expected array but got %s', gettype($pool)));
        }

        $nullToken = null;
        foreach ($pool as $id => $token) {
            if (!$token instanceof GithubTokenInterface) {
                throw new \UnexpectedValueException(sprintf('The tokens pool has an invalid token instance at index#%s', $id));
            }

            if ($token instanceof GithubTokenNull) {
                $nullToken = $token;
                unset($pool[$id]);
            }
        }

        // Pushback null token
        if ($nullToken) {
            $pool[$nullToken->getId()] = $nullToken;
        }

        return $pool;
    }

    /**
     * Updates the pool tokens.
     *
     * @param GithubTokenInterface[] $tokens
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    protected function write(array $tokens)
    {
        foreach ($tokens as $index => $token) {
            if (!$token instanceof GithubTokenInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'The provided tokens for write has an invalid token at index#%s', $index
                ));
            }
        }

        if (false === file_put_contents($this->pool, serialize($tokens), LOCK_EX)) {
            throw new \RuntimeException(sprintf('Unable to write tokens pool file; %s', $this->pool));
        }
    }

    /**
     * Merges the given tokens into the pool while preserving the order
     *  of the given token.
     *
     * @param array $tokens
     * @param bool  $overwrite Whether to overwrite existing tokens
     */
    protected function merge(array $tokens, $overwrite = false)
    {
        if (empty($tokens)) {
            return;
        }

        $pool = $this->read();
        foreach ($tokens as $index => $token) {
            if (!$token instanceof GithubTokenInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'The provided tokens for merge has an invalid token at index#%s', $index
                ));
            }

            $id = $token->getId();
            if (!isset($pool[$id]) || $overwrite) {
                $pool[$id] = $token;
                continue;
            }
        }

        $this->write($pool);
    }
}
