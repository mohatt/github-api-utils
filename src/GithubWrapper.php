<?php

namespace Github\Utils;

use Github;
use Psr\Log\LoggerInterface;

/**
 * Wrapper for Github\Client that adds some useful features like pagination and token switching.
 */
class GithubWrapper implements GithubWrapperInterface
{
    /**
     * @var Github\Client
     */
    protected $client;

    /**
     * @var Github\ResultPager
     */
    protected $pager;

    /**
     * @var GithubTokenPoolInterface
     */
    protected $tokenPool;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var Token\GithubTokenInterface
     */
    protected $token;

    /**
     * @var string
     */
    protected $scope;

    /**
     * @var array
     */
    protected $scopeTokens = [];

    /**
     * @var bool
     */
    protected $hasCustomToken = false;

    /**
     * Constructor.
     *
     * @param Github\Client                                       $client Github client
     * @param GithubTokenPoolInterface|Token\GithubTokenInterface $token  Could be a single token or a tokenPool instance
     * @param LoggerInterface                                     $logger An optional logger instance
     */
    public function __construct(Github\Client $client = null, $token = null, LoggerInterface $logger = null)
    {
        $this->client = $client ?: new Github\Client();
        $this->pager = new Github\ResultPager($this->client);

        if (null !== $token) {
            if ($token instanceof GithubTokenPoolInterface) {
                $this->setTokenPool($token);
            } else {
                $this->setToken($token);
            }
        }

        if (null !== $logger) {
            $this->setLogger($logger);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * {@inheritdoc}
     */
    public function getPager()
    {
        return $this->pager;
    }

    /**
     * {@inheritdoc}
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function setTokenPool(GithubTokenPoolInterface $tokenPool)
    {
        $this->tokenPool = $tokenPool;
    }

    /**
     * {@inheritdoc}
     */
    public function getTokenPool()
    {
        return $this->tokenPool;
    }

    /**
     * {@inheritdoc}
     */
    public function getToken()
    {
        return $this->token;
    }

    /**
     * {@inheritdoc}
     */
    public function setToken(Token\GithubTokenInterface $token = null)
    {
        $this->hasCustomToken = true;
        $this->authenticate($token);
    }

    /**
     * {@inheritdoc}
     */
    public function hasCustomToken()
    {
        return $this->hasCustomToken;
    }

    /**
     * {@inheritdoc}
     */
    public function isAuthenticated()
    {
        return $this->token instanceof Token\GithubTokenInterface;
    }

    /**
     * {@inheritdoc}
     */
    public function api($path, array $args = [], $full = false)
    {
        $segs = explode('/', $path);
        if (count($segs) <= 1) {
            throw new \InvalidArgumentException(sprintf("Invalid Github API path provided '%s'; No method is provided", $path));
        }

        $instance = $this->client->api(array_shift($segs));
        $method = array_pop($segs);
        foreach ($segs as $seg) {
            $instance = $instance->{$seg}();
        }

        $callback = function () use ($instance, $method, $args, $full) {
            if ($full) {
                return $this->pager->fetchAll($instance, $method, $args);
            }

            return $this->pager->fetch($instance, $method, $args);
        };

        if ($this->hasCustomToken()) {
            return $this->callApi($callback);
        }

        if (empty($this->tokenPool)) {
            throw new \LogicException('You must provide a GithubToken or a TokenPool instance in order to invoke an API call');
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

    /**
     * {@inheritdoc}
     */
    public function hasNext()
    {
        return $this->pager->hasNext();
    }

    /**
     * {@inheritdoc}
     */
    public function next()
    {
        if (empty($this->tokenPool)) {
            return $this->pager->fetchNext();
        }

        return $this->call(function () {
            return $this->pager->fetchNext();
        });
    }

    /**
     * {@inheritdoc}
     */
    public function last()
    {
        if (empty($this->tokenPool)) {
            return $this->pager->fetchLast();
        }

        return $this->call(function () {
            return $this->pager->fetchLast();
        });
    }

    /**
     * Invokes the api call while taking care of rate limiting and token switching.
     *
     * @param \Closure $callback
     *
     * @return mixed
     */
    protected function call(\Closure $callback)
    {
        if (empty($this->tokenPool)) {
            throw new \LogicException('This method should not be invoked without a TokenPool defined');
        }

        $scope = $this->scope;
        if (empty($this->scopeTokens[$scope])) {
            $this->scopeTokens[$scope] = $this->tokenPool->getToken($scope);
        }

        /** @var Token\GithubTokenInterface $token */
        $token = $this->scopeTokens[$scope];
        $tokenAllowed = $token->canAccess($scope);

        if (is_int($tokenAllowed)) {
            // Wait for the token to reset
            $this->logger &&
                $this->logger->warning(sprintf(
                    'Current Github %s token %s will reset in %d minutes, sleeping...',
                    $scope,
                    $token->getId(),
                    round($tokenAllowed / 60)
                ));

            sleep($tokenAllowed);

            //@todo: prevent possible endless invoke loop
            return $this->call($callback);
        }

        try {
            // Apply the token to the client
            $this->authenticate($token);
            // Invoke the api call
            $result = $this->callApi($callback);
        } catch (Github\Exception\ApiLimitExceedException $e) {
            // Token expired
            $this->logger &&
                $this->logger->warning(sprintf(
                    'Github %s rate limit reached for token %s! Getting a new token from the pool',
                    $scope,
                    $token->getId(true)
                ));

            // Get the next token in the pool
            $this->scopeTokens[$scope] = $this->tokenPool->nextToken($scope, $e->getResetTime());
            $this->logger &&
                $this->logger->debug(sprintf(
                    'Switched Github %s token to %s',
                    $scope,
                    $this->scopeTokens[$scope]->getId(true))
                );

            return $this->call($callback);
        }

        return $result;
    }

    /**
     * Invokes the api call with a specific token.
     *
     * @param \Closure $callback
     *
     * @return mixed
     */
    protected function callApi(\Closure $callback)
    {
        $result = $callback();
        if (is_object($result)) {
            throw new \UnexpectedValueException(sprintf("Expected API response but got an '%s' object", gettype($result)));
        }

        $code = $this->client->getLastResponse()->getStatusCode();
        // Github accepted the request but it's still processing it
        if (202 === $code) {
            // Sleep for a few moments until Github processing is complete
            sleep(1);

            // @todo: prevent possible endless invoke loop
            return $this->callApi($callback);
        }

        return $result;
    }

    /**
     * Authenticate the client with the given token.
     *
     * @param Token\GithubTokenInterface $token
     */
    protected function authenticate(Token\GithubTokenInterface $token)
    {
        // Set the current active token
        $this->token = $token;

        if ($token instanceof Token\GithubTokenBasicInterface) {
            $this->client->authenticate(
                $token->getUsername(),
                $token->getPassword(),
                Github\Client::AUTH_HTTP_PASSWORD
            );

            return;
        }

        if ($token instanceof Token\GithubTokenClientSecretInterface) {
            $this->client->authenticate(
                $token->getClientID(),
                $token->getClientSecret(),
                Github\Client::AUTH_URL_CLIENT_ID
            );

            return;
        }

        if ($token instanceof Token\GithubTokenNull) {
            $this->client->removePlugin(
                Github\HttpClient\Plugin\Authentication::class
            );

            return;
        }

        throw new \UnexpectedValueException(sprintf("Unsupported token provided of type '%s'", get_class($token)));
    }
}
