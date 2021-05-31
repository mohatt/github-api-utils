<?php

declare(strict_types=1);

namespace Github\Utils;

use Github\Utils\Token\GithubTokenInterface;

/**
 * Interface for a GithubTokenFactory.
 */
interface GithubTokenFactoryInterface
{
    /**
     * Creates a token or list of tokens.
     *
     * @param array|string $type   Token type or an array of token definitions
     * @param ...          $params Token params
     *
     * @return GithubTokenInterface|GithubTokenInterface[]
     */
    public static function create(string | array $type, ...$params): array | GithubTokenInterface;

    /**
     * Gets a list of supported token types or checks whether the given type is supported.
     *
     * @param null|string $type Token type to check against
     */
    public static function supports(string $type = null): bool | array;
}
