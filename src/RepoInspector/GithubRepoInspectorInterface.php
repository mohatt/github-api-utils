<?php

namespace Github\Utils\RepoInspector;

/**
 * Interface for a GithubRepoInspector.
 *
 * @package AwesomeHub
 */
interface GithubRepoInspectorInterface
{
    /**
     * Inspects a given repository and outputs an ASHPM score.
     *
     * @param string $author
     * @param string $name
     * @return array
     */
    public function inspect($author, $name);
}