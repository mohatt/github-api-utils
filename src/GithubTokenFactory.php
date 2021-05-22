<?php

namespace Github\Utils;

/**
 * Creates Github tokens.
 */
class GithubTokenFactory implements GithubTokenFactoryInterface
{
    const TOKEN_NULL = 'null';
    const TOKEN_PERSONAL = 'pat';
    const TOKEN_CLIENT_SECRET = 'client_secret';

    private static $supports = [
        self::TOKEN_NULL,
        self::TOKEN_PERSONAL,
        self::TOKEN_CLIENT_SECRET,
    ];

    /**
     * {@inheritdoc}
     */
    public static function create($type, ...$params)
    {
        if (empty($type)) {
            throw new \UnexpectedValueException('Expected non empty token type');
        }

        if (is_array($type)) {
            $instances = [];
            foreach ($type as $i => $tokenArr) {
                if (!is_array($tokenArr) || empty($tokenArr[0]) || !is_string($tokenArr[0])) {
                    throw new \UnexpectedValueException(sprintf(
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
        }
        catch (\Exception $e){
            throw new \RuntimeException(sprintf("Failed creating new github token of type '%s'; %s", $type, $e->getMessage()), 0, $e);
        }

        throw new \UnexpectedValueException(sprintf("Unsupported github token type '%s'", $type));
    }

    /**
     * {@inheritdoc}
     */
    public static function supports($type = null)
    {
        if (null === $type) {
            return self::$supports;
        }

        return in_array($type, self::$supports, true);
    }
}
