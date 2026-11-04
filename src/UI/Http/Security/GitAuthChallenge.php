<?php

declare(strict_types=1);

namespace App\UI\Http\Security;

use App\UI\Http\GitResponder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Handles authentication challenges and bearer token failure responses for the `git` firewall.
 * Sets WWW-Authenticate and LFS-Authenticate headers, answering JSON for LFS endpoints and plain text otherwise.
 */
final class GitAuthChallenge implements AuthenticationEntryPointInterface, AuthenticationFailureHandlerInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->buildChallengeResponse($request);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->buildChallengeResponse($request);
    }

    private function buildChallengeResponse(Request $request): Response
    {
        $isLfs = str_contains($request->getPathInfo(), '/info/lfs/')
            || 'application/vnd.git-lfs+json' === $request->headers->get('Accept');

        if ($isLfs) {
            $response = new JsonResponse(
                ['message' => 'credentials needed'],
                Response::HTTP_UNAUTHORIZED,
                ['Content-Type' => GitResponder::LFS_JSON],
            );
        } else {
            $response = new Response("Unauthorized\n", Response::HTTP_UNAUTHORIZED, ['Content-Type' => 'text/plain']);
        }

        GitResponder::addChallengeHeaders($response);

        return $response;
    }
}
