<?php

namespace Github\Utils\RepoInspector;

/**
 * Interface for a GithubRepoInspector.
 */
interface GithubRepoInspectorInterface
{
    /**
     * Inspects a given repository and returns various repo details.
     *
     * @param string $author
     * @param string $name
     *
     * @return array
     */
    public function inspect($author, $name);
}
