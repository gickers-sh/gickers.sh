<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Identity\Domain\Model\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Repo\Domain\RepoDirectory;
use App\Repo\Domain\RepoName;
use App\UI\Http\Git\GitServiceProcess;
use App\UI\Http\GitResponder;
use App\UI\Http\Security\GitAccessAuthorizer;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class GitSmartHttpController
{
    public function __construct(
        private GitAccessAuthorizer $authorizer,
        private GitResponder $responder,
        private GitServiceProcess $gitProcess,
        private RepoDirectory $repoDirectory,
        private Security $security,
    ) {
    }

    #[Route('/{repo}.git/info/refs', requirements: ['repo' => '.+?'], methods: ['GET'])]
    #[Route('/{repo}/info/refs', requirements: ['repo' => '.+?'], methods: ['GET'])]
    public function infoRefs(Request $request, string $repo): Response
    {
        $repoName = RepoName::fromInput($repo);
        $user = $this->domainUser();
        $badCreds = (bool) $request->attributes->get('_bad_credentials', false);
        $service = $request->query->getString('service');

        $auth = $this->authorizer->decide($user, $repoName, $service, 'info/refs', 'GET', $badCreds);
        $response = $this->responder->respond($auth, $request, $repoName, $user, false);
        if (null !== $response) {
            return $response;
        }

        $repoPath = $this->repoDirectory->pathFor($repoName);
        if (!is_dir($repoPath)) {
            return new Response("Not Found\n", Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain']);
        }

        if (\in_array($service, ['git-upload-pack', 'git-receive-pack'], true)) {
            $protocol = $request->headers->get('Git-Protocol');
            $body = $this->gitProcess->advertise($repoPath, $service, $protocol, $user, $repoName->value);

            return new Response($body, Response::HTTP_OK, [
                'Content-Type' => sprintf('application/x-%s-advertisement', $service),
                'Cache-Control' => 'no-cache, max-age=0, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => 'Fri, 01 Jan 1980 00:00:00 GMT',
            ]);
        }

        // Dumb HTTP fallback
        $this->gitProcess->updateServerInfo($repoPath);
        $refsFile = $repoPath.'/info/refs';
        if (!is_file($refsFile)) {
            return new Response("Not Found\n", Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain']);
        }

        $res = new BinaryFileResponse($refsFile);
        $res->headers->set('Content-Type', 'text/plain; charset=utf-8');
        $res->headers->set('Cache-Control', 'no-cache, max-age=0, must-revalidate');

        return $res;
    }

    #[Route('/{repo}.git/{service}', requirements: ['repo' => '.+?', 'service' => 'git-upload-pack|git-receive-pack'], methods: ['POST'])]
    #[Route('/{repo}/{service}', requirements: ['repo' => '.+?', 'service' => 'git-upload-pack|git-receive-pack'], methods: ['POST'])]
    public function serviceRpc(Request $request, string $repo, string $service): Response
    {
        $repoName = RepoName::fromInput($repo);
        $user = $this->domainUser();
        $badCreds = (bool) $request->attributes->get('_bad_credentials', false);

        $auth = $this->authorizer->decide($user, $repoName, $service, $service, 'POST', $badCreds);
        $response = $this->responder->respond($auth, $request, $repoName, $user, false);
        if (null !== $response) {
            return $response;
        }

        $repoPath = $this->repoDirectory->pathFor($repoName);
        if (!is_dir($repoPath)) {
            return new Response("Not Found\n", Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain']);
        }

        $isGzip = 'gzip' === $request->headers->get('Content-Encoding');
        $protocol = $request->headers->get('Git-Protocol');
        $inputStream = $request->getContent(true);

        $streamResponse = new StreamedResponse(function () use ($repoPath, $service, $inputStream, $isGzip, $protocol, $user, $repoName) {
            $this->gitProcess->streamRpc($repoPath, $service, $inputStream, $isGzip, $protocol, $user, $repoName->value);
        }, Response::HTTP_OK, [
            'Content-Type' => sprintf('application/x-%s-result', $service),
            'Cache-Control' => 'no-cache, max-age=0, must-revalidate',
            'Connection' => 'Keep-Alive',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        return $streamResponse;
    }

    private function domainUser(): ?User
    {
        $securityUser = $this->security->getUser();

        return $securityUser instanceof SecurityUser ? $securityUser->user() : null;
    }
}
