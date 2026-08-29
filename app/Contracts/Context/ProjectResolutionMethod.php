<?php

namespace App\Contracts\Context;

/**
 * Records which precedence tier the Context Gateway used to resolve a repository directory or
 * explicit reference to exactly one registered AIOS Project.
 */
enum ProjectResolutionMethod: string
{
    case ExplicitProjectId = 'explicit_project_id';
    case CanonicalGitRemote = 'canonical_git_remote';
    case RegisteredRepositoryIdentity = 'registered_repository_identity';
    case WorkspacePathFallback = 'workspace_path_fallback';
}
