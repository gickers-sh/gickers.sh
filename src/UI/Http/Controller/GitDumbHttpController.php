<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Identity\Domain\Model\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Repo\Domain\RepoDirectory;
use App\Repo\Domain\RepoName;
use App\UI\Http\GitResponder;
use App\UI\Http\Security\GitAccessAuthorizer;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class GitDumbHttpController
{
    public function __construct(
        private GitAccessAuthorizer $authorizer,
        private GitResponder $responder,
        private RepoDirectory $repoDirectory,
        private Security $security,
    ) {
    }

    #[Route('/{repo}.git/{path}', requirements: ['repo' => '.+?', 'path' => 'HEAD|objects/info/[^/]+|objects/[0-9a-f]{2}/[0-9a-f]{38}|objects/pack/pack-[0-9a-f]{40}\.(?:pack|idx)'], methods: ['GET'])]
    #[Route('/{repo}/{path}', requirements: ['repo' => '.+?', 'path' => 'HEAD|objects/info/[^/]+|objects/[0-9a-f]{2}/[0-9a-f]{38}|objects/pack/pack-[0-9a-f]{40}\.(?:pack|idx)'], methods: ['GET'])]
    public function sendFile(Request $request, string $repo, string $path): Response
    {
        $repoName = RepoName::fromInput($repo);
        $user = $this->domainUser();
        $badCreds = (bool) $request->attributes->get('_bad_credentials', false);

        $auth = $this->authorizer->decide($user, $repoName, '', $path, 'GET', $badCreds);
        $response = $this->responder->respond($auth, $request, $repoName, $user, false);
        if (null !== $response) {
            return $response;
        }

        $repoPath = $this->repoDirectory->pathFor($repoName);
        if (!is_dir($repoPath)) {
            return new Response("Not Found\n", Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain']);
        }

        // Prevent path traversal
        $filePath = realpath($repoPath.'/'.$path);
        if (false === $filePath || !str_starts_with($filePath, realpath($repoPath) ?: $repoPath) || !is_file($filePath)) {
            return new Response("Not Found\n", Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain']);
        }

        $res = new BinaryFileResponse($filePath);

        if (str_starts_with($path, 'objects/pack/pack-') && str_ends_with($path, '.pack')) {
            $res->headers->set('Content-Type', 'application/x-git-packed-objects');
            $res->headers->set('Cache-Control', 'public, max-age=31536000');
        } elseif (str_starts_with($path, 'objects/pack/pack-') && str_ends_with($path, '.idx')) {
            $res->headers->set('Content-Type', 'application/x-git-packed-objects-toc');
            $res->headers->set('Cache-Control', 'public, max-age=31536000');
        } elseif (preg_match('#^objects/[0-9a-f]{2}/[0-9a-f]{38}$#', $path)) {
            $res->headers->set('Content-Type', 'application/x-git-loose-object');
            $res->headers->set('Cache-Control', 'public, max-age=31536000');
        } else {
            $res->headers->set('Content-Type', 'text/plain; charset=utf-8');
            $res->headers->set('Cache-Control', 'no-cache, max-age=0, must-revalidate');
            $res->headers->set('Pragma', 'no-cache');
            $res->headers->set('Expires', 'Fri, 01 Jan 1980 00:00:00 GMT');
        }

        return $res;
    }

    private function domainUser(): ?User
    {
        $securityUser = $this->security->getUser();

        return $securityUser instanceof SecurityUser ? $securityUser->user() : null;
    }
}
