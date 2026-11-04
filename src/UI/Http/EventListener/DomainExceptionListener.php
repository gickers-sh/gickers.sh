<?php

declare(strict_types=1);

namespace App\UI\Http\EventListener;

use App\Lfs\Domain\Exception\LockConflict;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Domain\Exception\NotFound;
use App\UI\Http\GitResponder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * Maps domain exceptions to HTTP status codes for Git and Git LFS HTTP endpoints:
 * - NotFound -> 404
 * - AccessDenied -> 401 with challenge (anonymous) or 404 (authenticated, denyRead)
 * - InvalidInput -> 422
 * - LockConflict / Conflict -> 409
 */
#[AsEventListener(event: 'kernel.exception', priority: 10)]
final readonly class DomainExceptionListener
{
    public function __construct(
        private Security $security,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        $request = $event->getRequest();
        $isLfs = str_contains($request->getPathInfo(), '/info/lfs')
            || 'application/vnd.git-lfs+json' === $request->headers->get('Accept');

        if ($throwable instanceof LockConflict) {
            $existing = $throwable->existingLock;
            $data = [
                'message' => 'lock already exists',
                'lock' => [
                    'id' => (string) $existing->id(),
                    'path' => $existing->path(),
                    'locked_at' => $existing->createdAt()->format(\DateTimeInterface::ATOM),
                    'owner' => [
                        'name' => $existing->owner()->username(),
                    ],
                ],
            ];
            $event->setResponse(new JsonResponse($data, Response::HTTP_CONFLICT, ['Content-Type' => GitResponder::LFS_JSON]));

            return;
        }

        if ($throwable instanceof Conflict) {
            if ($isLfs) {
                $event->setResponse(new JsonResponse(
                    ['message' => $throwable->getMessage()],
                    Response::HTTP_CONFLICT,
                    ['Content-Type' => GitResponder::LFS_JSON],
                ));
            } else {
                $event->setResponse(new Response($throwable->getMessage(), Response::HTTP_CONFLICT, ['Content-Type' => 'text/plain']));
            }

            return;
        }

        if ($throwable instanceof InvalidInput) {
            if ($isLfs) {
                $event->setResponse(new JsonResponse(
                    ['message' => $throwable->getMessage()],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    ['Content-Type' => GitResponder::LFS_JSON],
                ));
            } else {
                $event->setResponse(new Response($throwable->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, ['Content-Type' => 'text/plain']));
            }

            return;
        }

        if ($throwable instanceof AccessDenied) {
            $user = $this->security->getUser();
            if (null === $user) {
                // Anonymous: 401 with challenge headers
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
                $event->setResponse($response);
            } else {
                // Authenticated: 404 (denyRead: unreadable behaves like missing)
                if ($isLfs) {
                    $event->setResponse(new JsonResponse(
                        ['message' => 'repository not found'],
                        Response::HTTP_NOT_FOUND,
                        ['Content-Type' => GitResponder::LFS_JSON],
                    ));
                } else {
                    $event->setResponse(new Response("Not Found\n", Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain']));
                }
            }

            return;
        }

        if ($throwable instanceof NotFound) {
            if ($isLfs) {
                $event->setResponse(new JsonResponse(
                    ['message' => $throwable->getMessage()],
                    Response::HTTP_NOT_FOUND,
                    ['Content-Type' => GitResponder::LFS_JSON],
                ));
            } else {
                $event->setResponse(new Response("Not Found\n", Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain']));
            }
        }
    }
}
