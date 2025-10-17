<?php

declare(strict_types=1);

namespace Github\Utils;

/**
 * Creates Github tokens.
 */
class GithubTokenFactory implements GithubTokenFactoryInterface
{
    public const TOKEN_NULL = 'null';
    public const TOKEN_PERSONAL = 'pat';
    public const TOKEN_CLIENT_SECRET = 'client_secret';

    private static array $supports = [
        self::TOKEN_NULL,
        self::TOKEN_PERSONAL,
        self::TOKEN_CLIENT_SECRET,
    ];

    public static function create(array|string $type, ...$params): array|Token\GithubTokenInterface
    {
        if (empty($type)) {
            throw new \UnexpectedValueException('Expected non empty token type');
        }

        if (\is_array($type)) {
            $instances = [];
            foreach ($type as $i => $tokenArr) {
                if (!\is_array($tokenArr) || empty($tokenArr[0]) || !\is_string($tokenArr[0])) {
                    throw new \UnexpectedValueException(\sprintf(
                        'Expected an array with at least 1 string element, got %s',
                        var_export($tokenArr, true)
                    ));
                }

                $instances[$i] = self::create(array_shift($tokenArr), ...$tokenArr);
            }

            return $instances;
        }

        try {
            switch ($type) {
                case self::TOKEN_NULL:
                    return new Token\GithubTokenNull();

                case self::TOKEN_PERSONAL:
                    return new Token\GithubTokenPersonal(...$params);

                case self::TOKEN_CLIENT_SECRET:
                    return new Token\GithubTokenClientSecret(...$params);
            }
        } catch (\Exception $e) {
            throw new \RuntimeException(\sprintf("Failed creating new github token of type '%s'; %s", $type, $e->getMessage()), $e->getCode(), $e);
        }

        throw new \UnexpectedValueException(\sprintf("Unsupported github token type '%s'", $type));
    }

    public static function supports(?string $type = null): array|bool
    {
        if (0 === \func_num_args()) {
            return self::$supports;
        }

        return \in_array($type, self::$supports, true);
    }
}
