<?php

declare(strict_types=1);

namespace App\UI\Http\Security;

/**
 * Outcome of authorizing a Git/LFS HTTP request, mirroring Go
 * `pkg/web/git.go` (`verdict`).
 */
enum GitVerdict
{
    /** Let the request through. */
    case Serve;
    /** Create the missing repository, then serve (push-to-create). */
    case CreateThenServe;
    /** Caller may not read the repo; missing and private look identical. */
    case DenyUnreadable;
    /** 403: bad credentials, or a signed-in reader who may not write. */
    case Forbid;
    /** 401 with a credentials challenge: an anonymous reader who may not write. */
    case Unauthorized;
    /** The LFS "write access required" error. */
    case ForbidLfsWrite;
}
