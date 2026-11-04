<?php

declare(strict_types=1);

namespace App\Service\Config;

use Symfony\Component\Yaml\Yaml;

/**
 * ServerConfig loads, parses, validates, and serializes the complete
 * Soft Serve server configuration matching `codes.sh.src/pkg/config/*`.
 *
 * Supports loading from `config.yaml` and merging `SOFT_SERVE_*` environment variables.
 */
class ServerConfig
{
 public const DEFAULT_SSH_LISTEN_ADDR = ':23231';
 public const DEFAULT_GIT_LISTEN_ADDR = ':9418';
 public const DEFAULT_HTTP_LISTEN_ADDR = ':23232';
 public const DEFAULT_STATS_LISTEN_ADDR = 'localhost:23233';

 public string $name = 'Soft Serve';
 public string $dataPath = '.data';

 /** @var array<string, mixed> */
 public array $ssh = [
  'enabled' => true,
  'listen_addr' => self::DEFAULT_SSH_LISTEN_ADDR,
  'public_url' => 'ssh://localhost:23231',
  'key_path' => 'ssh/soft_serve_host_ed25519',
  'client_key_path' => 'ssh/soft_serve_client_ed25519',
  'client_strict_host_key_checking' => 'accept-new',
  'client_allowed_hosts' => [],
  'max_timeout' => 0,
  'idle_timeout' => 600,
 ];

 /** @var array<string, mixed> */
 public array $git = [
  'enabled' => true,
  'listen_addr' => self::DEFAULT_GIT_LISTEN_ADDR,
  'public_url' => 'git://localhost',
  'max_timeout' => 0,
  'idle_timeout' => 3,
  'max_connections' => 32,
 ];

 /** @var array<string, mixed> */
 public array $http = [
  'enabled' => true,
  'listen_addr' => self::DEFAULT_HTTP_LISTEN_ADDR,
  'public_url' => 'http://localhost:23232',
  'tls_key_path' => '',
  'tls_cert_path' => '',
  'cors' => [
   'allowed_headers' => [
    'Accept',
    'Accept-Language',
    'Content-Language',
    'Content-Type',
    'Origin',
    'X-Requested-With',
    'User-Agent',
    'Authorization',
    'Access-Control-Request-Method',
    'Access-Control-Allow-Origin',
   ],
   'allowed_methods' => ['GET', 'HEAD', 'POST', 'PUT', 'OPTIONS'],
   'allowed_origins' => ['http://localhost:23232'],
  ],
 ];

 /** @var array<string, mixed> */
 public array $stats = [
  'enabled' => true,
  'listen_addr' => self::DEFAULT_STATS_LISTEN_ADDR,
 ];

 /** @var array<string, mixed> */
 public array $log = [
  'format' => 'text',
  'time_format' => '2006-01-02 15:04:05',
  'path' => '',
 ];

 /** @var array<string, mixed> */
 public array $db = [
  'driver' => 'sqlite',
  'data_source' => 'soft-serve.db?_pragma=busy_timeout(5000)&_pragma=foreign_keys(1)',
 ];

 /** @var array<string, mixed> */
 public array $lfs = [
  'enabled' => true,
  'ssh_enabled' => false,
 ];

 /** @var array<string, mixed> */
 public array $jobs = [
  'mirror_pull' => '@every 10m',
 ];

 /** @var list<string> */
 public array $initialAdminKeys = [];

 public ?string $anonAccess = null;
 public ?bool $allowKeyless = null;
 public string $defaultRepo = '';

 public function __construct(?string $dataPath = null)
 {
  $this->dataPath = $dataPath ?? (getenv('SOFT_SERVE_DATA_PATH') ?: (dirname(__DIR__, 3) . '/.data'));
 }

 /**
  * Loads and parses a config file (YAML) if it exists, then applies environment variables.
  */
 public function load(?string $configPath = null): self
 {
  $path = $configPath ?? $this->getConfigPath();
  if ($path !== null && file_exists($path)) {
   $this->parseFile($path);
  }

  $this->parseEnv();
  return $this;
 }

 /**
  * Resolves the default config.yaml location.
  */
 public function getConfigPath(): string
 {
  $customLoc = getenv('SOFT_SERVE_CONFIG_LOCATION');
  if ($customLoc && file_exists($customLoc)) {
   return $customLoc;
  }

  return $this->dataPath . '/config.yaml';
 }

 /**
  * Parses a YAML configuration file.
  */
 public function parseFile(string $filePath): void
 {
  if (!file_exists($filePath)) {
   return;
  }

  $data = Yaml::parseFile($filePath);
  if (!is_array($data)) {
   return;
  }

  if (isset($data['name']) && is_string($data['name'])) {
   $this->name = $data['name'];
  }

  if (isset($data['ssh']) && is_array($data['ssh'])) {
   $this->ssh = array_merge($this->ssh, $data['ssh']);
  }

  if (isset($data['git']) && is_array($data['git'])) {
   $this->git = array_merge($this->git, $data['git']);
  }

  if (isset($data['http']) && is_array($data['http'])) {
   if (isset($data['http']['cors']) && is_array($data['http']['cors'])) {
    $data['http']['cors'] = array_merge($this->http['cors'], $data['http']['cors']);
   }
   $this->http = array_merge($this->http, $data['http']);
  }

  if (isset($data['stats']) && is_array($data['stats'])) {
   $this->stats = array_merge($this->stats, $data['stats']);
  }

  if (isset($data['log']) && is_array($data['log'])) {
   $this->log = array_merge($this->log, $data['log']);
  }

  if (isset($data['db']) && is_array($data['db'])) {
   $this->db = array_merge($this->db, $data['db']);
  }

  if (isset($data['lfs']) && is_array($data['lfs'])) {
   $this->lfs = array_merge($this->lfs, $data['lfs']);
  }

  if (isset($data['jobs']) && is_array($data['jobs'])) {
   $this->jobs = array_merge($this->jobs, $data['jobs']);
  }

  if (isset($data['initial_admin_keys'])) {
   if (is_array($data['initial_admin_keys'])) {
    $this->initialAdminKeys = array_values(array_filter(array_map('trim', $data['initial_admin_keys'])));
   } elseif (is_string($data['initial_admin_keys'])) {
    $this->initialAdminKeys = array_values(array_filter(array_map('trim', explode("\n", $data['initial_admin_keys']))));
   }
  }

  if (isset($data['anon_access']) && is_string($data['anon_access'])) {
   $this->anonAccess = $data['anon_access'];
  }

  if (isset($data['allow_keyless'])) {
   $this->allowKeyless = (bool) $data['allow_keyless'];
  }

  if (isset($data['default_repo']) && is_string($data['default_repo'])) {
   $this->defaultRepo = $data['default_repo'];
  }
 }

 /**
  * Parses and overrides settings with `SOFT_SERVE_*` environment variables.
  */
 public function parseEnv(): void
 {
  if ($name = getenv('SOFT_SERVE_NAME')) {
   $this->name = $name;
  }

  if ($dp = getenv('SOFT_SERVE_DATA_PATH')) {
   $this->dataPath = $dp;
  }

  // SSH
  if (($val = getenv('SOFT_SERVE_SSH_ENABLED')) !== false && $val !== '') {
   $this->ssh['enabled'] = filter_var($val, FILTER_VALIDATE_BOOLEAN);
  }
  if ($val = getenv('SOFT_SERVE_SSH_LISTEN_ADDR')) {
   $this->ssh['listen_addr'] = $val;
  }
  if ($val = getenv('SOFT_SERVE_SSH_PUBLIC_URL')) {
   $this->ssh['public_url'] = $val;
  }
  if ($val = getenv('SOFT_SERVE_SSH_KEY_PATH')) {
   $this->ssh['key_path'] = $val;
  }
  if ($val = getenv('SOFT_SERVE_SSH_CLIENT_KEY_PATH')) {
   $this->ssh['client_key_path'] = $val;
  }
  if ($val = getenv('SOFT_SERVE_SSH_CLIENT_STRICT_HOST_KEY_CHECKING')) {
   $this->ssh['client_strict_host_key_checking'] = $val;
  }
  if ($val = getenv('SOFT_SERVE_SSH_MAX_TIMEOUT')) {
   $this->ssh['max_timeout'] = (int) $val;
  }
  if ($val = getenv('SOFT_SERVE_SSH_IDLE_TIMEOUT')) {
   $this->ssh['idle_timeout'] = (int) $val;
  }

  // Git Daemon
  if (($val = getenv('SOFT_SERVE_GIT_ENABLED')) !== false && $val !== '') {
   $this->git['enabled'] = filter_var($val, FILTER_VALIDATE_BOOLEAN);
  }
  if ($val = getenv('SOFT_SERVE_GIT_LISTEN_ADDR')) {
   $this->git['listen_addr'] = $val;
  }
  if ($val = getenv('SOFT_SERVE_GIT_PUBLIC_URL')) {
   $this->git['public_url'] = $val;
  }
  if ($val = getenv('SOFT_SERVE_GIT_MAX_TIMEOUT')) {
   $this->git['max_timeout'] = (int) $val;
  }
  if ($val = getenv('SOFT_SERVE_GIT_IDLE_TIMEOUT')) {
   $this->git['idle_timeout'] = (int) $val;
  }
  if ($val = getenv('SOFT_SERVE_GIT_MAX_CONNECTIONS')) {
   $this->git['max_connections'] = (int) $val;
  }

  // HTTP
  if (($val = getenv('SOFT_SERVE_HTTP_ENABLED')) !== false && $val !== '') {
   $this->http['enabled'] = filter_var($val, FILTER_VALIDATE_BOOLEAN);
  }
  if ($val = getenv('SOFT_SERVE_HTTP_LISTEN_ADDR')) {
   $this->http['listen_addr'] = $val;
  }
  if ($val = getenv('SOFT_SERVE_HTTP_PUBLIC_URL')) {
   $this->http['public_url'] = $val;
  }
  if ($val = getenv('SOFT_SERVE_HTTP_TLS_KEY_PATH')) {
   $this->http['tls_key_path'] = $val;
  }
  if ($val = getenv('SOFT_SERVE_HTTP_TLS_CERT_PATH')) {
   $this->http['tls_cert_path'] = $val;
  }

  // Stats
  if (($val = getenv('SOFT_SERVE_STATS_ENABLED')) !== false && $val !== '') {
   $this->stats['enabled'] = filter_var($val, FILTER_VALIDATE_BOOLEAN);
  }
  if ($val = getenv('SOFT_SERVE_STATS_LISTEN_ADDR')) {
   $this->stats['listen_addr'] = $val;
  }

  // Database
  if ($val = getenv('SOFT_SERVE_DB_DRIVER')) {
   $this->db['driver'] = $val;
  }
  if ($val = getenv('SOFT_SERVE_DB_DATA_SOURCE')) {
   $this->db['data_source'] = $val;
  }

  // LFS
  if (($val = getenv('SOFT_SERVE_LFS_ENABLED')) !== false && $val !== '') {
   $this->lfs['enabled'] = filter_var($val, FILTER_VALIDATE_BOOLEAN);
  }
  if (($val = getenv('SOFT_SERVE_LFS_SSH_ENABLED')) !== false && $val !== '') {
   $this->lfs['ssh_enabled'] = filter_var($val, FILTER_VALIDATE_BOOLEAN);
  }

  // Admin Keys
  if ($keys = getenv('SOFT_SERVE_INITIAL_ADMIN_KEYS')) {
   $parsed = array_values(array_filter(array_map('trim', explode("\n", $keys))));
   $this->initialAdminKeys = array_values(array_unique(array_merge($this->initialAdminKeys, $parsed)));
  }
  if ($key = getenv('SOFT_SERVE_INITIAL_ADMIN_KEY')) {
   if (!in_array(trim($key), $this->initialAdminKeys, true)) {
    $this->initialAdminKeys[] = trim($key);
   }
  }

  // Overrides
  if ($val = getenv('SOFT_SERVE_ANON_ACCESS')) {
   $this->anonAccess = $val;
  }
  if (($val = getenv('SOFT_SERVE_ALLOW_KEYLESS')) !== false && $val !== '') {
   $this->allowKeyless = filter_var($val, FILTER_VALIDATE_BOOLEAN);
  }
  if ($val = getenv('SOFT_SERVE_DEFAULT_REPO')) {
   $this->defaultRepo = $val;
  }
 }

 /**
  * Parses a listen address (e.g. ":23231", "127.0.0.1:23232", "0.0.0.0:9418", "9418")
  * into a [host, port] array.
  *
  * @return array{host: string, port: int}
  */
 public static function parseListenAddr(string $addr, int $defaultPort, string $defaultHost = '127.0.0.1'): array
 {
  $clean = trim($addr);
  if ($clean === '') {
   return ['host' => $defaultHost, 'port' => $defaultPort];
  }

  if (is_numeric($clean)) {
   return ['host' => $defaultHost, 'port' => (int) $clean];
  }

  if (str_starts_with($clean, ':')) {
   $port = (int) substr($clean, 1);
   return ['host' => $defaultHost, 'port' => $port > 0 ? $port : $defaultPort];
  }

  $parts = explode(':', $clean);
  if (count($parts) === 2) {
   $host = $parts[0] !== '' ? $parts[0] : $defaultHost;
   $port = (int) $parts[1];
   return ['host' => $host, 'port' => $port > 0 ? $port : $defaultPort];
  }

  return ['host' => $clean, 'port' => $defaultPort];
 }

 /**
  * Writes the default config.yaml matching Go template if not present.
  */
 public function writeDefaultConfig(string $targetPath): bool
 {
  if (file_exists($targetPath)) {
   return false;
  }

  $dir = dirname($targetPath);
  if (!is_dir($dir)) {
   mkdir($dir, 0755, true);
  }

  $content = <<<YAML
# Soft Serve Server configurations

# The name of the server.
# This is the name that will be displayed in the UI.
name: "{$this->name}"

# Logging configuration.
log:
  format: "{$this->log['format']}"
  time_format: "{$this->log['time_format']}"

# The SSH server configuration.
ssh:
  enabled: true
  listen_addr: "{$this->ssh['listen_addr']}"
  public_url: "{$this->ssh['public_url']}"
  key_path: "{$this->ssh['key_path']}"
  client_key_path: "{$this->ssh['client_key_path']}"
  client_strict_host_key_checking: "{$this->ssh['client_strict_host_key_checking']}"
  max_timeout: {$this->ssh['max_timeout']}
  idle_timeout: {$this->ssh['idle_timeout']}

# The Git daemon configuration.
git:
  enabled: true
  listen_addr: "{$this->git['listen_addr']}"
  public_url: "{$this->git['public_url']}"
  max_timeout: {$this->git['max_timeout']}
  idle_timeout: {$this->git['idle_timeout']}
  max_connections: {$this->git['max_connections']}

# The HTTP server configuration.
http:
  enabled: true
  listen_addr: "{$this->http['listen_addr']}"
  public_url: "{$this->http['public_url']}"
  cors:
    allowed_headers:
      - "Accept"
      - "Accept-Language"
      - "Content-Language"
      - "Content-Type"
      - "Origin"
      - "X-Requested-With"
      - "User-Agent"
      - "Authorization"
      - "Access-Control-Request-Method"
      - "Access-Control-Allow-Origin"
    allowed_methods:
      - "GET"
      - "HEAD"
      - "POST"
      - "PUT"
      - "OPTIONS"
    allowed_origins:
      - "http://localhost:23232"

# The stats server configuration.
stats:
  enabled: true
  listen_addr: "{$this->stats['listen_addr']}"

# The database configuration.
db:
  driver: "{$this->db['driver']}"
  data_source: "{$this->db['data_source']}"

# Git LFS configuration.
lfs:
  enabled: true
  ssh_enabled: false

# Jobs configuration.
jobs:
  mirror_pull: "{$this->jobs['mirror_pull']}"

YAML;

  return file_put_contents($targetPath, $content) !== false;
 }
}
