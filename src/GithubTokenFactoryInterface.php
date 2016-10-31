<?php

namespace Github\Utils;

/**
 * Interface for a GithubTokenFactory.
 */
interface GithubTokenFactoryInterface
{
    /**
     * Creates a token ot list of tokens.
     *
     * @param string|array $type   Token type or an array of token definitions
     * @param array        $params Token paramas
     *
     * @return Token\GithubTokenInterface|Token\GithubTokenInterface[]
     */
    public static function create($type, ...$params);

    /**
     * Gets a list of supported input types or checks whether the given type is supported.
     *
     * @param string $type Token type to check against
     *
     * @return bool|array
     */
    public static function supports($type = null);
}
