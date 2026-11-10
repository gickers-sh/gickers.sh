<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Identity\Domain\Model\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Repo\Domain\Release\ReleaseStore;
use App\Repo\Domain\RepoName;
use App\UI\Http\GitResponder;
use App\UI\Http\Security\GitAccessAuthorizer;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves a release artifact: `GET /{repo}/releases/{tag}/{filename}`. Read access
 * is required, a missing tag/artifact answers 404, and bytes are served as an
 * attachment. A tag name can never escape the repository's releases directory.
 */
final readonly class ReleaseDownloadController
{
    public function __construct(
        private GitAccessAuthorizer $authorizer,
        private GitResponder $responder,
        private ReleaseStore $releases,
        private Security $security,
    ) {
    }

    #[Route(
        '/{repo}/releases/{tag}/{filename}',
        requirements: ['repo' => '.+?', 'tag' => '[^/]+', 'filename' => '[^/]+'],
        methods: ['GET'],
        priority: 10,
    )]
    public function download(Request $request, string $repo, string $tag, string $filename): Response
    {
        $repoName = RepoName::fromInput($repo);
        $user = $this->domainUser();
        $badCreds = (bool) $request->attributes->get('_bad_credentials', false);

        $auth = $this->authorizer->decide($user, $repoName, '', 'releases', 'GET', $badCreds);
        $response = $this->responder->respond($auth, $request, $repoName, $user, false);
        if (null !== $response) {
            return $response;
        }

        $path = $this->releases->pathFor($repoName, $tag, $filename);
        if (null === $path) {
            return new Response("Not Found\n", Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain']);
        }

        $res = new BinaryFileResponse($path);
        $res->headers->set('Content-Type', 'application/octet-stream');
        $res->headers->set('Cache-Control', 'no-cache, max-age=0, must-revalidate');
        $res->setContentDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $filename,
            preg_replace('/[^\x20-\x7e]/', '_', $filename) ?? 'download',
        );

        return $res;
    }

    private function domainUser(): ?User
    {
        $securityUser = $this->security->getUser();

        return $securityUser instanceof SecurityUser ? $securityUser->user() : null;
    }
}
