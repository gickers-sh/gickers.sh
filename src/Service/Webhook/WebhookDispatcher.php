<?php

declare(strict_types=1);

namespace App\Service\Webhook;

use App\Model\UserModel;
use App\Model\WebhookModel;
use App\Service\Security\SsrfValidator;
use App\Service\Store\DataStore;

/**
 * WebhookDispatcher dispatches HMAC-SHA256 signed events with SSRF validation.
 * Matches pkg/webhook in Soft Serve.
 */
class WebhookDispatcher
{
 public function __construct(
  private readonly DataStore $dataStore,
  private readonly SsrfValidator $ssrfValidator,
 ) {}

 /**
  * Computes the HMAC-SHA256 signature for the payload.
  */
 public function computeSignature(string $payload, string $secret): string
 {
  return 'sha256=' . hash_hmac('sha256', $payload, $secret);
 }

 /**
  * Dispatches a webhook delivery, validating SSRF, generating HMAC signature, and recording delivery.
  *
  * @param array<string, mixed>|string $payload
  * @return string Delivery UUID
  */
 public function dispatch(
  WebhookModel $webhook,
  string|int $event,
  array|string $payload,
  bool $performHttp = false,
  bool $throwOnSsrf = false,
 ): string {
  $deliveryId = $this->generateUuid();
  $body = is_array($payload)
   ? (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
   : $payload;

  $contentType = $webhook->contentType === 'form'
   ? 'application/x-www-form-urlencoded'
   : 'application/json';

  $headers = [
   'Content-Type' => $contentType,
   'User-Agent' => 'SoftServe/1.0',
   'X-SoftServe-Event' => (string) $event,
   'X-SoftServe-Delivery' => $deliveryId,
  ];

  if ($webhook->secret !== '') {
   $signature = $this->computeSignature($body, $webhook->secret);
   $headers['X-Soft-Serve-Signature-256'] = $signature;
   $headers['X-SoftServe-Signature'] = $signature;
  }

  $reqHeadersStr = '';
  foreach ($headers as $k => $v) {
   $reqHeadersStr .= sprintf("%s: %s\n", $k, $v);
  }

  // Validate target URL against SSRF
  $isSafe = $this->ssrfValidator->validateUrl($webhook->url);
  if (!$isSafe) {
   $errorMsg = sprintf('SSRF: destination URL "%s" or resolved IP is blocked', $webhook->url);

   if ($webhook->id !== null) {
    $this->dataStore->recordDelivery(
     deliveryId: $deliveryId,
     webhookId: $webhook->id,
     event: $event,
     url: $webhook->url,
     method: 'POST',
     error: $errorMsg,
     reqHeaders: $reqHeadersStr,
     reqBody: $body,
     status: 0,
     resHeaders: '',
     resBody: '',
    );
   }

   if ($throwOnSsrf) {
    throw new \RuntimeException($errorMsg);
   }

   return $deliveryId;
  }

  $resStatus = 200;
  $resHeadersStr = '';
  $resBody = '';
  $reqError = null;

  if ($performHttp) {
   [$resStatus, $resHeadersStr, $resBody, $reqError] = $this->sendHttpRequest(
    $webhook->url,
    $headers,
    $body,
   );
  } else {
   $resBody = '{"delivered":true}';
   $resHeadersStr = "HTTP/1.1 200 OK\nContent-Type: application/json\n";
  }

  if ($webhook->id !== null) {
   $this->dataStore->recordDelivery(
    deliveryId: $deliveryId,
    webhookId: $webhook->id,
    event: $event,
    url: $webhook->url,
    method: 'POST',
    error: $reqError,
    reqHeaders: $reqHeadersStr,
    reqBody: $body,
    status: $resStatus,
    resHeaders: $resHeadersStr,
    resBody: $resBody,
   );
  }

  return $deliveryId;
 }

 /**
  * Dispatches an event to all active webhooks subscribed to this event in the repository.
  *
  * @param array<string, mixed>|string $payload
  * @return list<string> Delivery IDs
  */
 public function dispatchToRepo(
  int $repoId,
  string|int $event,
  array|string $payload,
  bool $performHttp = false,
 ): array {
  $webhooks = $this->dataStore->listWebhooks($repoId);
  $deliveries = [];

  foreach ($webhooks as $webhook) {
   if (!$webhook->active) {
    continue;
   }

   if ($webhook->hasEvent($event)) {
    $deliveries[] = $this->dispatch($webhook, $event, $payload, $performHttp);
   }
  }

  return $deliveries;
 }

 /**
  * Checks if a commit SHA is a git zero hash (40 zeros).
  */
 public static function isZeroHash(string $sha): bool
 {
  return $sha === '0000000000000000000000000000000000000000' || (strlen($sha) >= 40 && preg_match('/^0+$/', $sha) === 1);
 }

 /**
  * Dispatches a push event to repository webhooks.
  * Matches pkg/backend/hooks.go and pkg/webhook/push.go.
  *
  * @param string $repoName Repository name
  * @param list<array{ref: string, old: string, new: string}>|list<array<string, string>> $changes Git ref changes
  * @param UserModel|null $user Triggering user
  * @param bool $performHttp Whether to perform the HTTP request immediately
  * @return list<string> Delivery IDs
  */
 public function dispatchPushEvent(
  string $repoName,
  array $changes,
  ?UserModel $user = null,
  bool $performHttp = false,
 ): array {
  $repo = $this->dataStore->findRepoByName($repoName);
  if ($repo === null) {
   return [];
  }
  $repoId = (int) $repo['id'];

  $payload = [
   'event' => 'push',
   'repository' => [
    'id' => $repoId,
    'name' => $repo['name'] ?? $repoName,
    'description' => $repo['description'] ?? '',
    'project_name' => $repo['project_name'] ?? '',
    'private' => (bool) ($repo['private'] ?? false),
   ],
   'sender' => [
    'id' => $user?->id,
    'username' => $user?->username ?? 'anonymous',
   ],
   'commits' => [],
   'changes' => $changes,
  ];

  // EventPush = 4
  return $this->dispatchToRepo($repoId, 4, $payload, $performHttp);
 }

 /**
  * Dispatches a branch or tag event (create or delete) to repository webhooks.
  * Matches pkg/backend/hooks.go and pkg/webhook/branch_tag.go.
  *
  * @param string $repoName Repository name
  * @param string $refName Git ref name (e.g. refs/heads/main or refs/tags/v1.0.0)
  * @param string $oldSha Old commit SHA
  * @param string $newSha New commit SHA
  * @param UserModel|null $user Triggering user
  * @param bool $performHttp Whether to perform the HTTP request immediately
  * @return list<string> Delivery IDs
  */
 public function dispatchBranchTagEvent(
  string $repoName,
  string $refName,
  string $oldSha,
  string $newSha,
  ?UserModel $user = null,
  bool $performHttp = false,
 ): array {
  $repo = $this->dataStore->findRepoByName($repoName);
  if ($repo === null) {
   return [];
  }
  $repoId = (int) $repo['id'];

  $isCreate = self::isZeroHash($oldSha);
  $isDelete = self::isZeroHash($newSha);

  $eventId = $isCreate ? 1 : ($isDelete ? 2 : 1);
  $eventName = $isCreate ? 'branch_tag_create' : ($isDelete ? 'branch_tag_delete' : 'branch_tag');

  $payload = [
   'event' => $eventName,
   'ref' => $refName,
   'before' => $oldSha,
   'after' => $newSha,
   'created' => $isCreate,
   'deleted' => $isDelete,
   'repository' => [
    'id' => $repoId,
    'name' => $repo['name'] ?? $repoName,
    'description' => $repo['description'] ?? '',
    'project_name' => $repo['project_name'] ?? '',
    'private' => (bool) ($repo['private'] ?? false),
   ],
   'sender' => [
    'id' => $user?->id,
    'username' => $user?->username ?? 'anonymous',
   ],
  ];

  return $this->dispatchToRepo($repoId, $eventId, $payload, $performHttp);
 }

 /**
  * @param array<string, string> $headers
  * @return array{0: int, 1: string, 2: string, 3: ?string}
  */
 private function sendHttpRequest(string $url, array $headers, string $body): array
 {
  $headerLines = [];
  foreach ($headers as $k => $v) {
   $headerLines[] = sprintf('%s: %s', $k, $v);
  }

  $context = stream_context_create([
   'http' => [
    'method' => 'POST',
    'header' => implode("\r\n", $headerLines),
    'content' => $body,
    'timeout' => 5.0,
    'ignore_errors' => true,
   ],
  ]);

  $fp = @fopen($url, 'r', false, $context);
  if ($fp === false) {
   return [0, '', '', 'Failed to connect to destination URL'];
  }

  $meta = stream_get_meta_data($fp);
  $resHeadersList = $meta['wrapper_data'] ?? [];
  $resHeadersStr = is_array($resHeadersList) ? implode("\n", $resHeadersList) : '';

  $status = 0;
  if (!empty($resHeadersList[0]) && preg_match('#HTTP/\S+\s+(\d+)#', $resHeadersList[0], $m)) {
   $status = (int) $m[1];
  }

  $resBody = (string) stream_get_contents($fp);
  fclose($fp);

  return [$status, $resHeadersStr, $resBody, null];
 }

 private function generateUuid(): string
 {
  $data = random_bytes(16);
  $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
  $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant RFC 4122

  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
 }
}
