<?php

declare(strict_types=1);

namespace App\UI\Http\Controller\Dev;

use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Repo\Domain\RepoStore;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\UI\Tui\TuiAppFactory;
use App\UI\Tui\View\Repo\RepoView;
use App\UI\Tui\View\Selection\SelectionView;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class ConsoleDebugController extends AbstractController
{
    public function __construct(
        private readonly TuiAppFactory $apps,
        private readonly UserStore $userStore,
        private readonly ServerSettings $serverSettings,
        private readonly RepoStore $repoStore,
        private readonly KernelInterface $kernel,
        private readonly Security $security,
    ) {
    }

    /**
     * Main debug console: provides an authentic SSH CLI terminal and live TUI debugger.
     * Restricted strictly to administrators in non-production environments.
     */
    #[Route('/_dev/console', name: 'dev_console', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->assertAdminAccess();

        $allUsers = $this->userStore->all();
        $allRepos = $this->repoStore->all();
        $initialSessionId = 'console_' . bin2hex(random_bytes(6));

        $currentUser = $this->domainUser();

        return $this->render('dev/console_debug.html.twig', [
            'session_id' => $initialSessionId,
            'users' => $allUsers,
            'repos' => $allRepos,
            'current_user' => $currentUser,
            'server_name' => '' !== $this->serverSettings->name ? $this->serverSettings->name : 'gickers.sh',
        ]);
    }

    /**
     * Legacy route redirect: /_dev/tui redirects to /_dev/console.
     */
    #[Route('/_dev/tui', name: 'dev_tui', methods: ['GET'])]
    public function tuiRedirect(Request $request): Response
    {
        $this->assertAdminAccess();

        $query = $request->getQueryString();
        $target = '/_dev/console' . ($query ? '?' . $query : '');

        return new RedirectResponse($target, Response::HTTP_FOUND);
    }

    /**
     * Executes authentic SSH CLI commands using the real internal SSH dispatcher.
     * Output is returned with authentic 24-bit ANSI styling.
     */
    #[Route('/_dev/console/exec', name: 'dev_console_exec', methods: ['POST'])]
    public function executeCommand(Request $request): JsonResponse
    {
        $this->assertAdminAccess();

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Invalid JSON payload'], Response::HTTP_BAD_REQUEST);
        }

        $rawCmd = isset($payload['command']) && is_string($payload['command']) ? trim($payload['command']) : '';

        // Check if command is piped, e.g. `signup | bash`, `ssh duck@gickers.sh setup | bash`
        $isPiped = false;
        if (preg_match('/^(.*?)\s*\|\s*(?:bash|sh|zsh|iex|powershell|cat)(?:\s+.*)?$/i', $rawCmd, $pipeMatch)) {
            $isPiped = true;
            $rawCmd = trim($pipeMatch[1]);
        }

        // Extract SSH wrapper if present: e.g. `ssh console@gickers.sh signup` or `ssh duck@gickers.sh:2223 repo list`
        $cmdTargetUser = null;
        if (preg_match('/^ssh(?:\s+-[a-zA-Z0-9]+(?:\s+[^\s]+)?)*\s+(?:([a-zA-Z0-9_.-]+)@)?[a-zA-Z0-9_.-]+(?::\d+)?(?:\s+(.*))?$/s', $rawCmd, $m)) {
            if (!empty($m[1])) {
                $cmdTargetUser = trim($m[1]);
            }
            $cmd = isset($m[2]) ? trim($m[2]) : '';
        } else {
            $cmd = $rawCmd;
        }

        if ('' === $cmd) {
            return new JsonResponse([
                'output' => '',
                'exit_code' => 0,
            ]);
        }

        if ('clear' === $cmd || 'cls' === $cmd) {
            return new JsonResponse([
                'clear' => true,
                'output' => '',
                'exit_code' => 0,
            ]);
        }

        if ('tui' === $cmd || str_starts_with($cmd, 'tui ')) {
            $repo = trim(substr($cmd, 3));

            return new JsonResponse([
                'tui' => true,
                'repo' => '' !== $repo ? $repo : null,
                'output' => "Launching TUI...\r\n",
                'exit_code' => 0,
            ]);
        }

        $actor = null;
        if (null !== $cmdTargetUser && '' !== $cmdTargetUser) {
            $actor = $this->userStore->byUsername($cmdTargetUser);
            $actorUsername = $cmdTargetUser;
        } else {
            $actorName = $payload['user'] ?? null;
            if (is_string($actorName) && '' !== trim($actorName)) {
                $actor = $this->userStore->byUsername(trim($actorName));
            }
            if (null === $actor) {
                $actor = $this->domainUser();
            }
            $actorUsername = $actor?->username() ?? 'anonymous';
        }

        $cols = isset($payload['cols']) && is_numeric($payload['cols']) ? max(20, min(240, (int) $payload['cols'])) : 100;
        $rows = isset($payload['rows']) && is_numeric($payload['rows']) ? max(10, min(100, (int) $payload['rows'])) : 30;
        $term = isset($payload['term']) && is_string($payload['term']) ? trim($payload['term']) : 'xterm-256color';
        $noColor = !empty($payload['no_color']);

        $startTime = hrtime(true);
        $memBefore = memory_get_usage();

        $savedEnv = [];
        foreach (['COLUMNS', 'LINES', 'TERM', 'NO_COLOR', 'SSH_TTY', 'SSH_USER', 'SSH_ORIGINAL_COMMAND', 'SSH_PIPED'] as $envKey) {
            $savedEnv[$envKey] = [
                'env' => getenv($envKey),
                'hasServer' => \array_key_exists($envKey, $_SERVER),
                'server' => $_SERVER[$envKey] ?? null,
            ];
        }

        // Set realistic SSH environment variables matching real Go SSH gateway
        putenv("COLUMNS={$cols}");
        $_SERVER['COLUMNS'] = (string) $cols;
        putenv("LINES={$rows}");
        $_SERVER['LINES'] = (string) $rows;
        putenv("TERM={$term}");
        $_SERVER['TERM'] = $term;
        if ($noColor) {
            putenv('NO_COLOR=1');
            $_SERVER['NO_COLOR'] = '1';
        } else {
            putenv('NO_COLOR');
            unset($_SERVER['NO_COLOR']);
        }
        putenv('SSH_TTY=dev-console');
        $_SERVER['SSH_TTY'] = 'dev-console';
        putenv("SSH_USER={$actorUsername}");
        $_SERVER['SSH_USER'] = $actorUsername;
        putenv("SSH_ORIGINAL_COMMAND={$cmd}");
        $_SERVER['SSH_ORIGINAL_COMMAND'] = $cmd;

        if ($isPiped) {
            putenv('SSH_PIPED=1');
            $_SERVER['SSH_PIPED'] = '1';
        } else {
            putenv('SSH_PIPED');
            unset($_SERVER['SSH_PIPED']);
        }

        try {
            $app = new Application($this->kernel);
            $command = $app->find('soft:ssh:dispatch');

            $params = [
                'original-command' => $cmd,
                '--user' => $actorUsername,
            ];
            if (null !== $actor && [] !== $actor->publicKeys()) {
                $params['--key'] = $actor->publicKeys()[0]->authorizedKey();
            } elseif (null !== $actor) {
                // User exists in DB but has no public key yet: auto-register one so SSH commands succeed
                $rawBytes = hash('sha256', $actor->username(), true);
                $wire = pack('Na11Na32', 11, 'ssh-ed25519', 32, $rawBytes);
                $keyLine = 'ssh-ed25519 ' . base64_encode($wire) . ' ' . $actor->username() . '@gickers.sh';
                $sshKey = \App\Identity\Domain\Model\SshKey::parse($keyLine);
                $actor->addPublicKey($sshKey, new \DateTimeImmutable());
                $this->userStore->save($actor);
                $params['--key'] = $keyLine;
            } else {
                // Generate deterministic ephemeral key for unknown caller (e.g. self-registration)
                $rawBytes = hash('sha256', $actorUsername, true);
                $wire = pack('Na11Na32', 11, 'ssh-ed25519', 32, $rawBytes);
                $params['--key'] = 'ssh-ed25519 ' . base64_encode($wire) . ' ' . $actorUsername . '@gickers.sh';
            }

            $tester = new CommandTester($command);
            $exitCode = $tester->execute($params, [
                'interactive' => false,
                'decorated' => !$noColor,
            ]);

            $output = $tester->getDisplay(true);
            $durationMs = round((hrtime(true) - $startTime) / 1e6, 2);
            $memPeakMb = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
            $memDeltaKb = round((memory_get_usage() - $memBefore) / 1024, 1);

            return new JsonResponse([
                'output' => $output,
                'exit_code' => $exitCode,
                'profile' => [
                    'duration_ms' => $durationMs,
                    'memory_peak_mb' => $memPeakMb,
                    'memory_delta_kb' => $memDeltaKb,
                    'actor' => $actorUsername,
                    'term' => $term,
                    'no_color' => $noColor,
                    'command' => $cmd,
                    'timestamp' => date('H:i:s'),
                ],
            ]);
        } catch (\Throwable $e) {
            $durationMs = round((hrtime(true) - $startTime) / 1e6, 2);

            return new JsonResponse([
                'output' => "\e[31mError: " . $e->getMessage() . "\e[0m\r\n",
                'exit_code' => 1,
                'profile' => [
                    'duration_ms' => $durationMs,
                    'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                    'error' => $e->getMessage(),
                    'actor' => $actorUsername,
                ],
            ]);
        } finally {
            foreach ($savedEnv as $envKey => $prev) {
                if (false === $prev['env']) {
                    putenv($envKey);
                } else {
                    putenv($envKey . '=' . $prev['env']);
                }
                if ($prev['hasServer']) {
                    $_SERVER[$envKey] = $prev['server'];
                } else {
                    unset($_SERVER[$envKey]);
                }
            }
        }
    }

    /**
     * Component showcase view.
     */
    #[Route('/_dev/console/components', name: 'dev_console_components', methods: ['GET'])]
    #[Route('/_dev/tui/components', name: 'dev_tui_components', methods: ['GET'])]
    public function components(): Response
    {
        $this->assertAdminAccess();

        return $this->render('dev/tui_components.html.twig', [
            'server_name' => '' !== $this->serverSettings->name ? $this->serverSettings->name : 'gickers.sh',
        ]);
    }

    /**
     * Live SSE stream for the interactive TUI application.
     */
    #[Route('/_dev/console/stream', name: 'dev_console_stream', methods: ['GET'])]
    #[Route('/_dev/tui/stream', name: 'dev_tui_stream', methods: ['GET'])]
    public function streamOutput(Request $request): Response
    {
        $this->assertAdminAccess();

        $sessionId = (string) $request->query->get('session_id', 'default');
        $sessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId) ?: 'default';

        $cols = max(20, min(240, (int) $request->query->get('cols', 80)));
        $rows = max(10, min(100, (int) $request->query->get('rows', 24)));

        $username = $request->query->get('user');
        $actor = null;
        if (is_string($username) && '' !== trim($username)) {
            $actor = $this->userStore->byUsername(trim($username));
        }
        if (null === $actor) {
            $actor = $this->domainUser();
        }

        $repoName = $request->query->get('repo');
        $tabParam = $request->query->get('tab');

        $stateFile = sys_get_temp_dir() . '/tui_state_' . $sessionId;
        if (null === $tabParam || '' === trim((string) $tabParam) || '0' === trim((string) $tabParam)) {
            if (file_exists($stateFile)) {
                $saved = json_decode((string) file_get_contents($stateFile), true);
                if (is_array($saved)) {
                    if ((null === $tabParam || '' === trim((string) $tabParam) || '0' === trim((string) $tabParam)) && isset($saved['tab']) && (is_string($saved['tab']) || is_int($saved['tab']))) {
                        $tabParam = (string) $saved['tab'];
                    }
                    if ((null === $repoName || '' === trim((string) $repoName)) && isset($saved['repo']) && is_string($saved['repo']) && '' !== trim($saved['repo'])) {
                        $repoName = trim($saved['repo']);
                    }
                }
            }
        }

        $response = new StreamedResponse(function () use ($sessionId, $cols, $rows, $actor, $repoName, $tabParam, $stateFile): void {
            // Prevent execution time limits on SSE stream
            @set_time_limit(0);
            if (\function_exists('ini_set')) {
                @ini_set('max_execution_time', '0');
            }

            // Disable output buffering
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            $vt = new VirtualTerminal($cols, $rows);
            $app = $this->apps->create($vt, $actor, true);

            $initialView = null;
            if (is_string($repoName) && '' !== trim($repoName)) {
                try {
                    $initialView = $app->views()->repoByName(trim($repoName));
                } catch (\Throwable) {
                    $initialView = null;
                }
            }

            $view = $initialView ?? $app->views()->selection();
            $app->start($view);

            if (null !== $tabParam && '' !== trim((string) $tabParam)) {
                $tabVal = strtolower(trim((string) $tabParam));
                if ($view instanceof SelectionView) {
                    $tabMap = [
                        'about' => 0,
                        'feed' => 1,
                        'repos' => 2,
                        'repositories' => 2,
                        'my-repositories' => 2,
                        'profile' => 3,
                        'wiki' => 4,
                    ];
                    $idx = is_numeric($tabVal) ? (int) $tabVal : ($tabMap[$tabVal] ?? 0);
                    if ($idx > 0 && $idx < 5) {
                        $view->selectTab($idx);
                    }
                } elseif ($view instanceof RepoView) {
                    $tabMap = [
                        'readme' => 0,
                        'files' => 1,
                        'commits' => 2,
                        'branches' => 3,
                        'tags' => 4,
                        'deployments' => 5,
                        'profile' => 6,
                        'settings' => 7,
                    ];
                    $idx = is_numeric($tabVal) ? (int) $tabVal : ($tabMap[$tabVal] ?? 0);
                    if ($idx > 0 && $idx < 8) {
                        $view->selectTab($idx);
                    }
                }
            }

            $tui = $app->tui();
            $tui->start();

            $inspector = new \App\UI\Tui\Debug\ComponentInspector();
            $frameCount = 0;
            $lastOrigin = 'Initial Render';

            // Initial render pass
            $tui->tick();
            $initial = $vt->consumeOutput();
            if ('' !== $initial) {
                $frameCount++;
                echo "data: " . json_encode([
                    'type' => 'output',
                    'data' => $initial,
                    'frame_id' => $frameCount,
                    'origin' => 'Initial Render',
                    'duration_ms' => 0.5,
                    'bytes' => \strlen($initial),
                    'timestamp' => microtime(true),
                ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n\n";

                try {
                    $tree = $inspector->buildTree($app->navigator()->top(), $app->renderer());
                    echo "data: " . json_encode(['type' => 'component_tree', 'tree' => $tree], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n\n";
                } catch (\Throwable) {
                }

                echo "data: " . json_encode(['type' => 'subprocesses', 'jobs' => $app->async()->getActiveJobs()], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n\n";

                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            }

            $lastState = null;
            $sendState = function () use ($app, &$lastState, $stateFile): void {
                try {
                    $top = $app->navigator()->top();
                    $isRepo = $top instanceof RepoView;
                    $repoName = $isRepo ? $top->title() : null;
                    $activeTab = method_exists($top, 'activeTab') ? $top->activeTab() : 0;
                    $state = [
                        'type' => 'state',
                        'view' => $isRepo ? 'repo' : 'selection',
                        'repo' => $repoName,
                        'tab' => $activeTab,
                    ];
                    if ($state !== $lastState) {
                        $lastState = $state;
                        @file_put_contents($stateFile, json_encode([
                            'tab' => $activeTab,
                            'repo' => $repoName,
                            'view' => $state['view'],
                        ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));

                        echo "data: " . json_encode($state, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n\n";
                        if (ob_get_level() > 0) {
                            @ob_flush();
                        }
                        flush();
                    }
                } catch (\Throwable) {
                }
            };

            $sendState();

            $inputFile = sys_get_temp_dir() . '/tui_in_' . $sessionId;
            @touch($inputFile);
            @chmod($inputFile, 0666);

            $lastPing = time();
            $lastSubprocessCheck = time();

            while (!connection_aborted()) {
                @set_time_limit(0);
                $hadInput = false;

                clearstatcache(true, $inputFile);
                if (file_exists($inputFile) && filesize($inputFile) > 0) {
                    $fp = @fopen($inputFile, 'r+');
                    if ($fp) {
                        if (flock($fp, LOCK_EX)) {
                            $contents = stream_get_contents($fp);
                            ftruncate($fp, 0);
                            rewind($fp);
                            flock($fp, LOCK_UN);
                            fclose($fp);

                            if (is_string($contents) && '' !== trim($contents)) {
                                $lines = explode("\n", trim($contents));
                                foreach ($lines as $line) {
                                    if ('' === trim($line)) {
                                        continue;
                                    }
                                    $msg = json_decode($line, true);
                                    if (!is_array($msg)) {
                                        continue;
                                    }

                                    if (isset($msg['resize']) && is_array($msg['resize'])) {
                                        $colsRaw = $msg['resize']['cols'] ?? 80;
                                        $rowsRaw = $msg['resize']['rows'] ?? 24;
                                        $c = max(20, min(240, is_numeric($colsRaw) ? (int) $colsRaw : 80));
                                        $r = max(10, min(100, is_numeric($rowsRaw) ? (int) $rowsRaw : 24));
                                        $lastOrigin = "Window Resize ({$c}x{$r})";
                                        $vt->simulateResize($c, $r);
                                        echo "data: " . json_encode([
                                            'type' => 'event_log',
                                            'event' => 'resize',
                                            'detail' => "{$c}x{$r}",
                                            'origin' => 'Viewport Resize',
                                            'timestamp' => microtime(true),
                                        ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n\n";
                                    } elseif (isset($msg['key']) && is_string($msg['key'])) {
                                        $keyStr = $msg['key'];
                                        $lastOrigin = "Key [{$keyStr}]";
                                        $vt->simulateInput($keyStr);
                                        echo "data: " . json_encode([
                                            'type' => 'event_log',
                                            'event' => 'key',
                                            'detail' => $keyStr,
                                            'origin' => 'User Keyboard',
                                            'timestamp' => microtime(true),
                                        ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n\n";
                                    } elseif (isset($msg['inspect']) && is_array($msg['inspect'])) {
                                        $col = is_numeric($msg['inspect']['col'] ?? null) ? (int) $msg['inspect']['col'] : 1;
                                        $row = is_numeric($msg['inspect']['row'] ?? null) ? (int) $msg['inspect']['row'] : 1;
                                        $found = $app->hitTester()->findAt($col, $row);
                                        $info = null;
                                        if (null !== $found) {
                                            $info = $inspector->inspectWidget($found['widget'], $app->renderer());
                                        }
                                        echo "data: " . json_encode([
                                            'type' => 'component_selected',
                                            'component' => $info,
                                            'query' => ['col' => $col, 'row' => $row],
                                        ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n\n";
                                        if (ob_get_level() > 0) {
                                            @ob_flush();
                                        }
                                        flush();
                                    } elseif (!empty($msg['get_tree'])) {
                                        try {
                                            $tree = $inspector->buildTree($app->navigator()->top(), $app->renderer());
                                            echo "data: " . json_encode(['type' => 'component_tree', 'tree' => $tree], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n\n";
                                        } catch (\Throwable) {
                                        }
                                        if (ob_get_level() > 0) {
                                            @ob_flush();
                                        }
                                        flush();
                                    } elseif (!empty($msg['get_subprocesses'])) {
                                        echo "data: " . json_encode(['type' => 'subprocesses', 'jobs' => $app->async()->getActiveJobs()], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n\n";
                                        if (ob_get_level() > 0) {
                                            @ob_flush();
                                        }
                                        flush();
                                    }
                                }
                                $hadInput = true;
                            }
                        } else {
                            fclose($fp);
                        }
                    }
                }

                $tickStart = hrtime(true);
                $tui->tick();
                $tickMs = round((hrtime(true) - $tickStart) / 1e6, 2);

                $chunk = $vt->consumeOutput();
                if ('' !== $chunk) {
                    $frameCount++;
                    echo "data: " . json_encode([
                        'type' => 'output',
                        'data' => $chunk,
                        'frame_id' => $frameCount,
                        'origin' => $lastOrigin,
                        'duration_ms' => $tickMs,
                        'bytes' => \strlen($chunk),
                        'timestamp' => microtime(true),
                    ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n\n";

                    try {
                        $tree = $inspector->buildTree($app->navigator()->top(), $app->renderer());
                        echo "data: " . json_encode(['type' => 'component_tree', 'tree' => $tree], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n\n";
                    } catch (\Throwable) {
                    }

                    if (ob_get_level() > 0) {
                        @ob_flush();
                    }
                    flush();
                    $sendState();
                    $lastOrigin = 'Async Tick / Loader';
                }

                if (time() - $lastSubprocessCheck >= 2) {
                    $lastSubprocessCheck = time();
                    $jobs = $app->async()->getActiveJobs();
                    echo "data: " . json_encode(['type' => 'subprocesses', 'jobs' => $jobs], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n\n";
                    if (ob_get_level() > 0) {
                        @ob_flush();
                    }
                    flush();
                }

                if (time() - $lastPing >= 15) {
                    echo ": ping\n\n";
                    if (ob_get_level() > 0) {
                        @ob_flush();
                    }
                    flush();
                    $lastPing = time();
                }

                usleep($hadInput ? 5000 : 25000);
            }

            $tui->stop();
            @unlink($inputFile);
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-transform, private');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Content-Encoding', 'none');

        return $response;
    }

    /**
     * Receives simulated keystrokes and resize events for the live TUI session.
     */
    #[Route('/_dev/console/input', name: 'dev_console_input', methods: ['POST'])]
    #[Route('/_dev/tui/input', name: 'dev_tui_input', methods: ['POST'])]
    public function input(Request $request): JsonResponse
    {
        $this->assertAdminAccess();

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Invalid JSON payload'], Response::HTTP_BAD_REQUEST);
        }

        $rawSessionId = $payload['session_id'] ?? 'default';
        $sessionId = is_string($rawSessionId) ? $rawSessionId : 'default';
        $sessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId) ?: 'default';

        $inputFile = sys_get_temp_dir() . '/tui_in_' . $sessionId;
        $entry = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        $fp = @fopen($inputFile, 'a');
        if ($fp && flock($fp, LOCK_EX)) {
            fwrite($fp, $entry);
            flock($fp, LOCK_UN);
            fclose($fp);
            clearstatcache(true, $inputFile);

            return new JsonResponse(['ok' => true]);
        }

        return new JsonResponse(['error' => 'Cannot append to session input queue'], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * Enforces that the debug console is only accessible by server admins
     * and is completely disabled in production environment.
     */
    private function assertAdminAccess(): void
    {
        if ('prod' === $this->getParameter('kernel.environment')) {
            throw $this->createNotFoundException('The debug console is not available in production.');
        }

        $securityUser = $this->security->getUser();
        $domainUser = $securityUser instanceof SecurityUser ? $securityUser->user() : null;

        if (null === $domainUser || !$domainUser->isAdmin()) {
            throw $this->createAccessDeniedException('Access restricted to server administrators.');
        }
    }

    private function domainUser(): ?User
    {
        $securityUser = $this->security->getUser();

        return $securityUser instanceof SecurityUser ? $securityUser->user() : null;
    }
}
