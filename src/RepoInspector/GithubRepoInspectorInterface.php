<?php

declare(strict_types=1);

namespace Github\Utils\RepoInspector;

/**
 * Interface for a GithubRepoInspector.
 */
interface GithubRepoInspectorInterface
{
    /**
     * Inspects a given repository and returns various repo details.
     *
     * @thorws Exception\RepoInspectorException
     */
    public function inspect(string $author, string $name): array;
}
