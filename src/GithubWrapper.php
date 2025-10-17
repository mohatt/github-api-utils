<?php

declare(strict_types=1);

namespace Github\Utils;

use Github;
use Github\Utils\Token\GithubTokenInterface;
use Psr\Log\LoggerInterface;

/**
 * Wrapper for Github\Client that adds some useful features like pagination and token switching.
 */
class GithubWrapper implements GithubWrapperInterface
{
    protected Github\Client $client;
    protected Github\ResultPager $pager;
    protected GithubTokenPoolInterface $tokenPool;
    protected GithubTokenInterface $token;
    protected string $scope;
    protected array $scopeTokens = [];
    protected bool $hasCustomToken = false;

    /**
     * Constructor.
     *
     * @param null|Github\Client                                 $client Github client instance
     * @param null|GithubTokenInterface|GithubTokenPoolInterface $token  Could be a single token or a tokenPool instance
     * @param null|LoggerInterface                               $logger An optional PSR-3 logger instance
     */
    public function __construct(?Github\Client $client = null, GithubTokenInterface|GithubTokenPoolInterface|null $token = null, protected ?LoggerInterface $logger = null)
    {
        $this->client = $client ?: new Github\Client();
        $this->pager = new Github\ResultPager($this->client);

        if ($token instanceof GithubTokenPoolInterface) {
            $this->setTokenPool($token);
        } else {
            $this->setToken($token);
        }
    }

    public function getClient(): Github\Client
    {
        return $this->client;
    }

    public function getPager(): Github\ResultPager
    {
        return $this->pager;
    }

    public function getTokenPool(): GithubTokenPoolInterface
    {
        return $this->tokenPool;
    }

    public function setTokenPool(GithubTokenPoolInterface $tokenPool): void
    {
        $this->tokenPool = $tokenPool;
    }

    public function getToken(): GithubTokenInterface
    {
        return $this->token;
    }

    public function hasCustomToken(): bool
    {
        return $this->hasCustomToken;
    }

    public function setToken(?GithubTokenInterface $token = null): void
    {
        $this->hasCustomToken = true;
        if (null === $token) {
            $token = new Token\GithubTokenNull();
        }

        $this->authenticate($token);
    }

    public function isAuthenticated(): bool
    {
        return $this->token instanceof GithubTokenInterface && !$this->token instanceof Token\GithubTokenNull;
    }

    public function api(string $path, array $args = [], bool $full = false): mixed
    {
        $segments = explode('/', $path);
        if (\count($segments) <= 1) {
            throw new \InvalidArgumentException(\sprintf("Invalid Github API path '%s'; No method is provided", $path));
        }

        $instance = $this->client->api(array_shift($segments));
        $method = array_pop($segments);
        foreach ($segments as $seg) {
            $instance = $instance->{$seg}();
        }

        $callback = fn () => $full
            ? $this->pager->fetchAll($instance, $method, $args)
            : $this->pager->fetch($instance, $method, $args);

        if ($this->hasCustomToken()) {
            return $this->invoke($callback);
        }

        if (!isset($this->tokenPool)) {
            throw new \LogicException('You must provide a GithubToken or a GithubTokenPool instance in order to invoke an API call');
        }

        // Set the token scope
        if ($instance instanceof Github\Api\Search) {
            $this->scope = 'search';
        } elseif ($instance instanceof Github\Api\RateLimit) {
            $this->scope = 'none';
        } else {
            $this->scope = 'core';
        }

        return $this->call($callback);
    }

    public function hasNext(): bool
    {
        return $this->pager->hasNext();
    }

    public function next(): mixed
    {
        if (!isset($this->tokenPool)) {
            return $this->pager->fetchNext();
        }

        return $this->call(fn () => $this->pager->fetchNext());
    }

    public function last(): mixed
    {
        if (!isset($this->tokenPool)) {
            return $this->pager->fetchLast();
        }

        return $this->call(fn () => $this->pager->fetchLast());
    }

    /**
     * Invokes the api call while taking care of rate limiting and token switching.
     */
    protected function call(\Closure $callback, int $retries = 0): mixed
    {
        if (!isset($this->tokenPool)) {
            throw new \LogicException('This method should not be invoked without a TokenPool instance defined');
        }

        if ($retries > 5) {
            throw new \RuntimeException('Maximum retry count for call() has been reached');
        }

        $scope = $this->scope;
        if (empty($this->scopeTokens[$scope])) {
            $this->scopeTokens[$scope] = $this->tokenPool->getToken($scope);
        }

        /** @var GithubTokenInterface $token */
        $token = $this->scopeTokens[$scope];
        $tokenAllowed = $token->canAccess($scope);

        if (\is_int($tokenAllowed)) {
            // Wait for the token to reset
            $this->logger
                && $this->logger->warning(\sprintf(
                    'Current Github token [%s: %s] will reset in %d minutes, sleeping...',
                    $scope,
                    $token->getId(true),
                    round($tokenAllowed / 60)
                ));

            sleep($tokenAllowed);

            return $this->call($callback, ++$retries);
        }

        $rateLimitReached = 0;

        try {
            // Apply the token to the client
            $this->authenticate($token);
            // Invoke the api call
            $result = $this->invoke($callback);
        } catch (Github\Exception\ApiLimitExceedException $e) {
            $rateLimitReached = $e->getResetTime();
        } catch (Github\Exception\RuntimeException $e) {
            // Sometimes knplabs/github-api fails to detect Github's Rate limiting headers so we'll handle this manually
            if (!str_contains(strtolower($e->getMessage()), 'rate limit exceeded')) {
                throw $e;
            }

            // We dont know the actual reset time in this case so we'll set it 10 minutes from now
            $rateLimitReached = time() + 600;
        }

        if ($rateLimitReached) {
            // Token expired
            $this->logger
            && $this->logger->warning(\sprintf(
                'Github token %s rate limit reached for scope %s! Getting a new token from the pool',
                $token->getId(true),
                $scope
            ));

            // Get the next token in the pool
            $this->scopeTokens[$scope] = $this->tokenPool->nextToken($scope, $rateLimitReached);
            $this->logger
            && $this->logger->debug(
                \sprintf(
                    'Switched Github %s token to %s',
                    $scope,
                    $this->scopeTokens[$scope]->getId(true)
                )
            );

            return $this->call($callback, ++$retries);
        }

        return $result;
    }

    /**
     * Invokes the api call with the current active token.
     */
    protected function invoke(\Closure $callback, int $retries = 0): mixed
    {
        if ($retries > 5) {
            throw new \RuntimeException('Maximum retry count for invoke() has been reached');
        }

        $result = $callback();
        if (\is_object($result)) {
            throw new \UnexpectedValueException(\sprintf("Expected API response but got an '%s' object", \gettype($result)));
        }

        $code = $this->client->getLastResponse()->getStatusCode();
        // Github accepted the request but it's still processing it
        if (202 === $code) {
            // Sleep for a few moments until Github processing is complete
            sleep(1);

            return $this->invoke($callback, ++$retries);
        }

        return $result;
    }

    /**
     * Authenticate the client with the given token.
     */
    protected function authenticate(GithubTokenInterface $token): void
    {
        // Set the current active token
        $this->token = $token;

        if ($token instanceof Token\GithubTokenPersonalInterface) {
            $this->client->authenticate(
                $token->getToken(),
                null,
                Github\Client::AUTH_ACCESS_TOKEN
            );

            return;
        }

        if ($token instanceof Token\GithubTokenClientSecretInterface) {
            $this->client->authenticate(
                $token->getClientID(),
                $token->getClientSecret(),
                Github\Client::AUTH_CLIENT_ID
            );

            return;
        }

        if ($token instanceof Token\GithubTokenNull) {
            $this->client->authenticate('', '', Github\Client::AUTH_CLIENT_ID);

            return;
        }

        throw new \UnexpectedValueException(\sprintf("Unsupported token provided of type '%s'", $token::class));
    }
}
