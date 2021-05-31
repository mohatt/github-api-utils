<?php

declare(strict_types=1);

namespace Github\Utils;

use Github\Utils\Token\GithubTokenInterface;
use Github\Utils\Token\GithubTokenNull;

/**
 * Stores and rotates Github tokens.
 */
class GithubTokenPool implements GithubTokenPoolInterface
{
    /**
     * Current active token for each scope.
     *
     * @var GithubTokenInterface[]
     */
    protected array $current = [];

    /**
     * Constructor.
     *
     * @param string                 $pool   Pool file path
     * @param GithubTokenInterface[] $tokens Initial tokens as list of token instance
     */
    public function __construct(protected string $pool, array $tokens = [])
    {
        if ([] !== $tokens) {
            $this->setTokens($tokens);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setTokens(array $tokens, bool $purge = false): void
    {
        if ($purge) {
            $this->write($tokens);

            return;
        }

        $this->merge($tokens);
    }

    /**
     * {@inheritdoc}
     */
    public function getTokens(): array
    {
        return $this->read();
    }

    /**
     * {@inheritdoc}
     */
    public function getToken(string $scope): GithubTokenInterface
    {
        $tokens = $this->read();
        if ([] === $tokens) {
            throw new \LogicException('No valid tokens were found in the token pool');
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
     */
    public function nextToken(string $scope, int $reset): GithubTokenInterface
    {
        if (time() >= $reset) {
            throw new \LogicException('Token reset time cannot be in the past.');
        }

        if (empty($this->current[$scope])) {
            throw new \LogicException(sprintf("No current token were found for scope '%s'; You need to call getToken() first", $scope));
        }

        $token = $this->current[$scope];
        $token->setReset($scope, $reset);
        $this->merge([$token], true);

        return $this->getToken($scope);
    }

    /**
     * Fetches the pool tokens.
     *
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     *
     * @return GithubTokenInterface[]
     */
    protected function read(): array
    {
        if (empty($this->pool) || !file_exists($this->pool)) {
            return [];
        }

        if (false === $pool = file_get_contents($this->pool)) {
            throw new \RuntimeException(sprintf('Unable to read token pool file; %s', $this->pool));
        }

        $pool = unserialize($pool);
        if (!\is_array($pool)) {
            throw new \UnexpectedValueException(sprintf('Unexpected token pool data; Expected array but got %s', \gettype($pool)));
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
    protected function write(array $tokens): void
    {
        foreach ($tokens as $index => $token) {
            if (!$token instanceof GithubTokenInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'The provided tokens for write has an invalid token at index#%s',
                    $index
                ));
            }
        }

        if (false === file_put_contents($this->pool, serialize($tokens), \LOCK_EX)) {
            throw new \RuntimeException(sprintf('Unable to write token pool file; %s', $this->pool));
        }
    }

    /**
     * Merges the given tokens into the pool while preserving the order
     *  of the given tokens.
     *
     * @param bool $overwrite Whether to overwrite existing tokens
     */
    protected function merge(array $tokens, bool $overwrite = false): void
    {
        if ([] === $tokens) {
            return;
        }

        $pool = $this->read();
        foreach ($tokens as $index => $token) {
            if (!$token instanceof GithubTokenInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'The provided tokens for merge has an invalid token at index#%s',
                    $index
                ));
            }

            $id = $token->getId();
            if (!isset($pool[$id]) || $overwrite) {
                $pool[$id] = $token;
            }
        }

        $this->write($pool);
    }
}
