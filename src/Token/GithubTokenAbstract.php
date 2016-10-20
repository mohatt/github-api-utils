<?php

namespace Github\Utils\Token;

abstract class GithubTokenAbstract implements GithubTokenInterface
{
    /**
     * @var int[]
     */
    protected $reset = [];

    /**
     * @inheritdoc
     */
    public function canAccess($scope)
    {
        if(!isset($this->reset[$scope])){
            return true;
        }

        $diff = $this->reset[$scope] - time();
        return $diff > 0 ? $diff : true;
    }

    /**
     * @inheritdoc
     */
    public function setReset($scope, $reset)
    {
        $this->reset[$scope] = (int) $reset;
    }
}
