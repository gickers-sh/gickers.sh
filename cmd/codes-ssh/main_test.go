package main

import (
	"bufio"
	"bytes"
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"crypto/rsa"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"io"
	"log"
	"net"
	"os"
	"os/signal"
	"path/filepath"
	"strings"
	"sync"
	"syscall"
	"testing"
	"time"

	"github.com/creack/pty"
	"golang.org/x/crypto/ssh"
)

// TestMain lets the test binary double as a fast mock `php` executable when
// GO_CODES_SSH_TEST_HELPER=1, avoiding slow external shells or PHP boots in Go unit tests.
func TestMain(m *testing.M) {
	if os.Getenv("GO_CODES_SSH_TEST_HELPER") == "1" {
		runHelperProcess()
		return
	}
	os.Exit(m.Run())
}

type helperReport struct {
	Args       []string          `json:"args"`
	Env        map[string]string `json:"env"`
	HasOrigCmd bool              `json:"has_orig_cmd"`
	OrigCmd    string            `json:"orig_cmd"`
	Stdin      string            `json:"stdin"`
}

func runHelperProcess() {
	mode := os.Getenv("HELPER_MODE")
	switch mode {
	case "sleep":
		// Ignore or exit on SIGTERM depending on submode; sleep until killed.
		fmt.Println("ready")
		time.Sleep(30 * time.Second)
		os.Exit(0)
	case "pty-echo":
		if marker := os.Getenv("HELPER_MARKER"); marker != "" {
			_ = os.WriteFile(marker, []byte("started"), 0o600)
		}
		sigCh := make(chan os.Signal, 4)
		signal.Notify(sigCh, syscall.SIGWINCH)
		go func() {
			for range sigCh {
				if ws, err := pty.GetsizeFull(os.Stdin); err == nil {
					fmt.Printf("RESIZE=%dx%d\n", ws.Cols, ws.Rows)
				}
			}
		}()
		ws, _ := pty.GetsizeFull(os.Stdin)
		fmt.Printf("TTY=%s TERM=%s SIZE=%dx%d\n", os.Getenv("SSH_TTY"), os.Getenv("TERM"), ws.Cols, ws.Rows)
		buf := make([]byte, 256)
		for {
			n, err := os.Stdin.Read(buf)
			if n > 0 {
				s := string(buf[:n])
				fmt.Print(strings.ToUpper(s))
				if strings.Contains(s, "q") {
					os.Exit(0)
				}
			}
			if err != nil {
				os.Exit(0)
			}
		}
	default:
		stdinBytes, _ := io.ReadAll(os.Stdin)
		origCmd, hasOrigCmd := os.LookupEnv("SSH_ORIGINAL_COMMAND")
		envMap := make(map[string]string)
		for _, kv := range os.Environ() {
			k, v, ok := strings.Cut(kv, "=")
			if ok {
				envMap[k] = v
			}
		}
		rep := helperReport{
			Args:       os.Args[1:],
			Env:        envMap,
			HasOrigCmd: hasOrigCmd,
			OrigCmd:    origCmd,
			Stdin:      string(stdinBytes),
		}
		_ = json.NewEncoder(os.Stdout).Encode(rep)
		if stderrMsg := os.Getenv("HELPER_STDERR"); stderrMsg != "" {
			fmt.Fprint(os.Stderr, stderrMsg)
		}
		if codeStr := os.Getenv("HELPER_EXIT_CODE"); codeStr != "" {
			var code int
			_, _ = fmt.Sscanf(codeStr, "%d", &code)
			os.Exit(code)
		}
		os.Exit(0)
	}
}

func generateTestEd25519Signer(t *testing.T) ssh.Signer {
	t.Helper()
	_, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatalf("generate ed25519 key: %v", err)
	}
	signer, err := ssh.NewSignerFromKey(priv)
	if err != nil {
		t.Fatalf("signer from key: %v", err)
	}
	return signer
}

func generateTestRSASigner(t *testing.T) ssh.Signer {
	t.Helper()
	priv, err := rsa.GenerateKey(rand.Reader, 2048)
	if err != nil {
		t.Fatalf("generate rsa key: %v", err)
	}
	signer, err := ssh.NewSignerFromKey(priv)
	if err != nil {
		t.Fatalf("rsa signer: %v", err)
	}
	return signer
}

func startTestServer(t *testing.T, extraEnv ...string) (string, ssh.PublicKey, context.CancelFunc) {
	t.Helper()
	dir := t.TempDir()
	hostKeyPath := filepath.Join(dir, "ssh", "soft_serve_host_ed25519")
	hostSigner, _, err := ensureHostKey(hostKeyPath)
	if err != nil {
		t.Fatalf("ensureHostKey: %v", err)
	}

	selfExe, err := os.Executable()
	if err != nil {
		t.Fatalf("os.Executable: %v", err)
	}

	t.Setenv("GO_CODES_SSH_TEST_HELPER", "1")
	for _, kv := range extraEnv {
		k, v, _ := strings.Cut(kv, "=")
		t.Setenv(k, v)
	}

	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("listen: %v", err)
	}

	ctx, cancel := context.WithCancel(context.Background())
	srv := newServer(newSSHServerConfig(hostSigner), selfExe, "/app/bin/console", log.New(io.Discard, "", 0))

	done := make(chan error, 1)
	go func() {
		done <- srv.serve(ctx, ln)
	}()

	t.Cleanup(func() {
		cancel()
		select {
		case err := <-done:
			if err != nil {
				t.Errorf("server.serve error: %v", err)
			}
		case <-time.After(5 * time.Second):
			t.Errorf("server did not shut down within 5s")
		}
	})

	return ln.Addr().String(), hostSigner.PublicKey(), cancel
}

func TestEnsureHostKeyGeneratesAtomicallyAndReloads(t *testing.T) {
	dir := t.TempDir()
	keyPath := filepath.Join(dir, "ssh", "soft_serve_host_ed25519")

	s1, gen1, err := ensureHostKey(keyPath)
	if err != nil {
		t.Fatalf("first ensureHostKey failed: %v", err)
	}
	if !gen1 {
		t.Fatal("expected generated=true on first call")
	}

	dirInfo, err := os.Stat(filepath.Dir(keyPath))
	if err != nil {
		t.Fatalf("stat dir: %v", err)
	}
	if dirInfo.Mode().Perm() != 0o700 {
		t.Fatalf("expected dir mode 0700, got %04o", dirInfo.Mode().Perm())
	}

	keyInfo, err := os.Stat(keyPath)
	if err != nil {
		t.Fatalf("stat key: %v", err)
	}
	if keyInfo.Mode().Perm() != 0o600 {
		t.Fatalf("expected key mode 0600, got %04o", keyInfo.Mode().Perm())
	}

	pubBytes, err := os.ReadFile(keyPath + ".pub")
	if err != nil {
		t.Fatalf("read pub key: %v", err)
	}
	parsedPub, _, _, _, err := ssh.ParseAuthorizedKey(pubBytes)
	if err != nil {
		t.Fatalf("parse .pub file: %v", err)
	}
	if !bytes.Equal(parsedPub.Marshal(), s1.PublicKey().Marshal()) {
		t.Fatal("public key file does not match private key signer")
	}

	// Ensure no leftover temp files exist.
	entries, err := os.ReadDir(filepath.Dir(keyPath))
	if err != nil {
		t.Fatalf("readdir: %v", err)
	}
	if len(entries) != 2 {
		t.Fatalf("expected 2 files in ssh dir, got %d", len(entries))
	}

	// Second call reloads existing key without regenerating.
	s2, gen2, err := ensureHostKey(keyPath)
	if err != nil {
		t.Fatalf("second ensureHostKey failed: %v", err)
	}
	if gen2 {
		t.Fatal("expected generated=false on second call")
	}
	if !bytes.Equal(s1.PublicKey().Marshal(), s2.PublicKey().Marshal()) {
		t.Fatal("reloaded host key differs from generated key")
	}
}

func TestEnsureHostKeyRejectsCorruptAndEncrypted(t *testing.T) {
	dir := t.TempDir()

	corruptPath := filepath.Join(dir, "corrupt_key")
	if err := os.WriteFile(corruptPath, []byte("not a private key\n"), 0o600); err != nil {
		t.Fatalf("write corrupt key: %v", err)
	}
	if _, _, err := ensureHostKey(corruptPath); err == nil {
		t.Fatal("expected error for corrupt host key")
	}

	_, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatalf("generate key: %v", err)
	}
	encBlock, err := ssh.MarshalPrivateKeyWithPassphrase(priv, "enc", []byte("secret-pass"))
	if err != nil {
		t.Fatalf("marshal encrypted key: %v", err)
	}
	encPath := filepath.Join(dir, "encrypted_key")
	if err := os.WriteFile(encPath, pem.EncodeToMemory(encBlock), 0o600); err != nil {
		t.Fatalf("write encrypted key: %v", err)
	}
	if _, _, err := ensureHostKey(encPath); err == nil {
		t.Fatal("expected error for encrypted host key")
	}
}

func TestAuthRejectsPasswordNoneAndCertificates(t *testing.T) {
	addr, hostPub, _ := startTestServer(t)

	// Password auth rejected
	_, err := ssh.Dial("tcp", addr, &ssh.ClientConfig{
		User:            "alice",
		Auth:            []ssh.AuthMethod{ssh.Password("hunter2")},
		HostKeyCallback: ssh.FixedHostKey(hostPub),
	})
	if err == nil {
		t.Fatal("expected password auth to be rejected")
	}

	// No auth methods rejected
	_, err = ssh.Dial("tcp", addr, &ssh.ClientConfig{
		User:            "alice",
		Auth:            nil,
		HostKeyCallback: ssh.FixedHostKey(hostPub),
	})
	if err == nil {
		t.Fatal("expected none auth to be rejected")
	}

	// OpenSSH certificate rejected
	caSigner := generateTestEd25519Signer(t)
	userSigner := generateTestEd25519Signer(t)
	cert := &ssh.Certificate{
		Key:             userSigner.PublicKey(),
		Serial:          1,
		CertType:        ssh.UserCert,
		KeyId:           "alice",
		ValidPrincipals: []string{"alice"},
		ValidBefore:     ssh.CertTimeInfinity,
	}
	if err := cert.SignCert(rand.Reader, caSigner); err != nil {
		t.Fatalf("sign cert: %v", err)
	}
	certSigner, err := ssh.NewCertSigner(cert, userSigner)
	if err != nil {
		t.Fatalf("new cert signer: %v", err)
	}

	_, err = ssh.Dial("tcp", addr, &ssh.ClientConfig{
		User:            "alice",
		Auth:            []ssh.AuthMethod{ssh.PublicKeys(certSigner)},
		HostKeyCallback: ssh.FixedHostKey(hostPub),
	})
	if err == nil {
		t.Fatal("expected OpenSSH certificate auth to be rejected")
	}
}

func TestAuthAcceptsAnyUsernameAndPubkeyAlgorithms(t *testing.T) {
	addr, hostPub, _ := startTestServer(t)

	edSigner := generateTestEd25519Signer(t)
	rsaSigner := generateTestRSASigner(t)

	cases := []struct {
		name   string
		user   string
		signer ssh.Signer
	}{
		{"ed25519 arbitrary user", "duck", edSigner},
		{"ed25519 anonymous user", "anonymous", edSigner},
		{"ed25519 empty user", "", edSigner},
		{"rsa sha2 user", "alice", rsaSigner},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			client, err := ssh.Dial("tcp", addr, &ssh.ClientConfig{
				User:            tc.user,
				Auth:            []ssh.AuthMethod{ssh.PublicKeys(tc.signer)},
				HostKeyCallback: ssh.FixedHostKey(hostPub),
			})
			if err != nil {
				t.Fatalf("dial failed: %v", err)
			}
			defer client.Close()

			sess, err := client.NewSession()
			if err != nil {
				t.Fatalf("new session: %v", err)
			}
			defer sess.Close()

			out, err := sess.Output("info")
			if err != nil {
				t.Fatalf("session output: %v", err)
			}

			var rep helperReport
			if err := json.Unmarshal(out, &rep); err != nil {
				t.Fatalf("decode report: %v (%s)", err, string(out))
			}

			wantKey := strings.TrimSpace(string(ssh.MarshalAuthorizedKey(tc.signer.PublicKey())))
			wantArgs := []string{"/app/bin/console", "soft:ssh:dispatch", "--key=" + wantKey}
			if len(rep.Args) != len(wantArgs) {
				t.Fatalf("args = %v, want %v", rep.Args, wantArgs)
			}
			for i := range wantArgs {
				if rep.Args[i] != wantArgs[i] {
					t.Fatalf("arg[%d] = %q, want %q", i, rep.Args[i], wantArgs[i])
				}
			}
		})
	}
}

func TestGlobalRequestsAndChannelRestrictions(t *testing.T) {
	addr, hostPub, _ := startTestServer(t)
	userSigner := generateTestEd25519Signer(t)

	client, err := ssh.Dial("tcp", addr, &ssh.ClientConfig{
		User:            "alice",
		Auth:            []ssh.AuthMethod{ssh.PublicKeys(userSigner)},
		HostKeyCallback: ssh.FixedHostKey(hostPub),
	})
	if err != nil {
		t.Fatalf("dial: %v", err)
	}
	defer client.Close()

	// keepalive succeeds
	ok, _, err := client.SendRequest("keepalive@openssh.com", true, nil)
	if err != nil || !ok {
		t.Fatalf("keepalive failed: ok=%v err=%v", ok, err)
	}

	// tcpip-forward rejected
	ok, _, err = client.SendRequest("tcpip-forward", true, ssh.Marshal(struct {
		Addr string
		Port uint32
	}{"127.0.0.1", 8080}))
	if err != nil {
		t.Fatalf("tcpip-forward send error: %v", err)
	}
	if ok {
		t.Fatal("expected tcpip-forward global request to be rejected")
	}

	// direct-tcpip, forwarded-tcpip, x11 channels rejected
	for _, chanType := range []string{"direct-tcpip", "forwarded-tcpip", "x11", "auth-agent@openssh.com"} {
		_, _, err := client.OpenChannel(chanType, nil)
		if err == nil {
			t.Fatalf("expected channel %q to be rejected", chanType)
		}
		var openErr *ssh.OpenChannelError
		if !errors.As(err, &openErr) || openErr.Reason != ssh.Prohibited {
			t.Fatalf("expected Prohibited rejection for %q, got %v", chanType, err)
		}
	}

	// sftp subsystem rejected
	ch, reqs, err := client.OpenChannel("session", nil)
	if err != nil {
		t.Fatalf("open session: %v", err)
	}
	defer ch.Close()
	go ssh.DiscardRequests(reqs)

	subOK, err := ch.SendRequest("subsystem", true, ssh.Marshal(struct{ Name string }{"sftp"}))
	if err != nil {
		t.Fatalf("subsystem send error: %v", err)
	}
	if subOK {
		t.Fatal("expected sftp subsystem to be rejected")
	}
}

func TestEnvAllowlistAndShellVsExecContract(t *testing.T) {
	// Even if parent has SSH_ORIGINAL_COMMAND, shell must clear it.
	addr, hostPub, _ := startTestServer(t, "SSH_ORIGINAL_COMMAND=leaked-from-parent", "APP_ENV=prod")
	userSigner := generateTestEd25519Signer(t)

	client, err := ssh.Dial("tcp", addr, &ssh.ClientConfig{
		User:            "alice",
		Auth:            []ssh.AuthMethod{ssh.PublicKeys(userSigner)},
		HostKeyCallback: ssh.FixedHostKey(hostPub),
	})
	if err != nil {
		t.Fatalf("dial: %v", err)
	}
	defer client.Close()

	// 1. Test env allowlist and exec SSH_ORIGINAL_COMMAND
	sess, err := client.NewSession()
	if err != nil {
		t.Fatalf("new session: %v", err)
	}

	if err := sess.Setenv("TERM", "xterm-256color"); err != nil {
		t.Fatalf("Setenv TERM: %v", err)
	}
	if err := sess.Setenv("COLORTERM", "truecolor"); err != nil {
		t.Fatalf("Setenv COLORTERM: %v", err)
	}
	if err := sess.Setenv("LANG", "en_US.UTF-8"); err != nil {
		t.Fatalf("Setenv LANG: %v", err)
	}
	if err := sess.Setenv("LC_ALL", "C.UTF-8"); err != nil {
		t.Fatalf("Setenv LC_ALL: %v", err)
	}
	for _, blocked := range []string{"APP_ENV", "DATABASE_URL", "SSH_ORIGINAL_COMMAND", "LD_PRELOAD", "PATH", "LC_bad"} {
		if err := sess.Setenv(blocked, "evil"); err == nil {
			t.Fatalf("expected env %q to be rejected", blocked)
		}
	}

	out, err := sess.Output("repo list")
	_ = sess.Close()
	if err != nil {
		t.Fatalf("exec output: %v", err)
	}

	var rep helperReport
	if err := json.Unmarshal(out, &rep); err != nil {
		t.Fatalf("decode report: %v", err)
	}
	if !rep.HasOrigCmd || rep.OrigCmd != "repo list" {
		t.Fatalf("SSH_ORIGINAL_COMMAND = (%v, %q), want (true, %q)", rep.HasOrigCmd, rep.OrigCmd, "repo list")
	}
	if rep.Env["APP_ENV"] != "prod" {
		t.Fatalf("APP_ENV = %q, want prod", rep.Env["APP_ENV"])
	}
	if rep.Env["TERM"] != "xterm-256color" || rep.Env["COLORTERM"] != "truecolor" || rep.Env["LANG"] != "en_US.UTF-8" || rep.Env["LC_ALL"] != "C.UTF-8" {
		t.Fatalf("allowlisted env missing: %+v", rep.Env)
	}
	if rep.Env["SSH_CONNECTION"] == "" || rep.Env["SSH_CLIENT"] == "" {
		t.Fatalf("expected SSH_CONNECTION and SSH_CLIENT, got conn=%q client=%q", rep.Env["SSH_CONNECTION"], rep.Env["SSH_CLIENT"])
	}

	// 2. Test shell clears SSH_ORIGINAL_COMMAND
	sess2, err := client.NewSession()
	if err != nil {
		t.Fatalf("new shell session: %v", err)
	}
	var stdout bytes.Buffer
	sess2.Stdout = &stdout
	if err := sess2.Shell(); err != nil {
		t.Fatalf("shell: %v", err)
	}
	if err := sess2.Wait(); err != nil {
		t.Fatalf("shell wait: %v", err)
	}
	_ = sess2.Close()

	var shellRep helperReport
	if err := json.Unmarshal(stdout.Bytes(), &shellRep); err != nil {
		t.Fatalf("decode shell report: %v (%s)", err, stdout.String())
	}
	if shellRep.HasOrigCmd {
		t.Fatalf("expected SSH_ORIGINAL_COMMAND to be unset in shell, got %q", shellRep.OrigCmd)
	}
}

func TestPipeSessionStreamsStdinEOFAfterWriteAndExitCode(t *testing.T) {
	addr, hostPub, _ := startTestServer(t, "HELPER_EXIT_CODE=42", "HELPER_STDERR=err-line\n")
	userSigner := generateTestEd25519Signer(t)

	client, err := ssh.Dial("tcp", addr, &ssh.ClientConfig{
		User:            "alice",
		Auth:            []ssh.AuthMethod{ssh.PublicKeys(userSigner)},
		HostKeyCallback: ssh.FixedHostKey(hostPub),
	})
	if err != nil {
		t.Fatalf("dial: %v", err)
	}
	defer client.Close()

	sess, err := client.NewSession()
	if err != nil {
		t.Fatalf("new session: %v", err)
	}
	defer sess.Close()

	var stdout, stderr bytes.Buffer
	sess.Stdout = &stdout
	sess.Stderr = &stderr
	stdin, err := sess.StdinPipe()
	if err != nil {
		t.Fatalf("stdin pipe: %v", err)
	}

	if err := sess.Start("git-receive-pack demo"); err != nil {
		t.Fatalf("start: %v", err)
	}
	_, _ = io.WriteString(stdin, "binary-payload-bytes")
	_ = stdin.Close()

	err = sess.Wait()
	var exitErr *ssh.ExitError
	if !errors.As(err, &exitErr) || exitErr.ExitStatus() != 42 {
		t.Fatalf("expected exit status 42, got err=%v", err)
	}
	if stderr.String() != "err-line\n" {
		t.Fatalf("stderr = %q, want err-line\\n", stderr.String())
	}

	var rep helperReport
	if err := json.Unmarshal(stdout.Bytes(), &rep); err != nil {
		t.Fatalf("decode report: %v", err)
	}
	if rep.Stdin != "binary-payload-bytes" {
		t.Fatalf("stdin = %q, want binary-payload-bytes", rep.Stdin)
	}
}

func TestPTYSessionWindowChangeAndRejectsDuplicateExec(t *testing.T) {
	marker := filepath.Join(t.TempDir(), "started")
	addr, hostPub, _ := startTestServer(t, "HELPER_MODE=pty-echo", "HELPER_MARKER="+marker)
	userSigner := generateTestEd25519Signer(t)

	client, err := ssh.Dial("tcp", addr, &ssh.ClientConfig{
		User:            "alice",
		Auth:            []ssh.AuthMethod{ssh.PublicKeys(userSigner)},
		HostKeyCallback: ssh.FixedHostKey(hostPub),
	})
	if err != nil {
		t.Fatalf("dial: %v", err)
	}
	defer client.Close()

	ch, reqs, err := client.OpenChannel("session", nil)
	if err != nil {
		t.Fatalf("open session: %v", err)
	}
	defer ch.Close()
	go ssh.DiscardRequests(reqs)

	ptyOK, err := ch.SendRequest("pty-req", true, ssh.Marshal(ptyReqPayload{
		Term:   "xterm-256color",
		Width:  80,
		Height: 24,
	}))
	if err != nil || !ptyOK {
		t.Fatalf("pty-req failed: ok=%v err=%v", ptyOK, err)
	}
	time.Sleep(20 * time.Millisecond)
	if _, err := os.Stat(marker); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("expected no process spawned on pty-req alone, got stat err=%v", err)
	}

	shellOK, err := ch.SendRequest("shell", true, nil)
	if err != nil || !shellOK {
		t.Fatalf("shell failed: ok=%v err=%v", shellOK, err)
	}

	reader := bufio.NewReader(ch)
	banner, err := reader.ReadString('\n')
	if err != nil {
		t.Fatalf("read initial PTY banner: %v", err)
	}
	if !strings.Contains(banner, "TTY=") || !strings.Contains(banner, "TERM=xterm-256color") || !strings.Contains(banner, "SIZE=80x24") {
		t.Fatalf("expected TTY, TERM and initial SIZE=80x24 in banner, got %q", banner)
	}

	// Second exec on same channel must be refused.
	secondOK, err := ch.SendRequest("exec", true, ssh.Marshal(execPayload{Command: "info"}))
	if err != nil {
		t.Fatalf("second exec error: %v", err)
	}
	if secondOK {
		t.Fatal("expected second exec request on same channel to be rejected")
	}

	// Window-change accepted on PTY channel and delivers SIGWINCH.
	wcOK, err := ch.SendRequest("window-change", true, ssh.Marshal(windowChangePayload{
		Width:  120,
		Height: 40,
	}))
	if err != nil || !wcOK {
		t.Fatalf("window-change failed: ok=%v err=%v", wcOK, err)
	}

	time.Sleep(50 * time.Millisecond)
	_, _ = io.WriteString(ch, "hi\nq\n")
	rest, _ := io.ReadAll(reader)
	restStr := string(rest)
	if !strings.Contains(restStr, "RESIZE=120x40") {
		t.Fatalf("expected RESIZE=120x40 after window-change, got %q", restStr)
	}
	if !strings.Contains(restStr, "HI") {
		t.Fatalf("expected echoed HI in PTY output, got %q", restStr)
	}
}

func TestSessionConcurrencyLimit(t *testing.T) {
	dir := t.TempDir()
	hostSigner, _, err := ensureHostKey(filepath.Join(dir, "host_ed25519"))
	if err != nil {
		t.Fatalf("ensureHostKey: %v", err)
	}
	selfExe, err := os.Executable()
	if err != nil {
		t.Fatalf("os.Executable: %v", err)
	}
	t.Setenv("GO_CODES_SSH_TEST_HELPER", "1")
	t.Setenv("HELPER_MODE", "sleep")

	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("listen: %v", err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	srv := newServer(newSSHServerConfig(hostSigner), selfExe, "/app/bin/console", log.New(io.Discard, "", 0))
	srv.sessionSem = make(chan struct{}, 2)

	var wg sync.WaitGroup
	wg.Add(1)
	go func() {
		defer wg.Done()
		_ = srv.serve(ctx, ln)
	}()

	userSigner := generateTestEd25519Signer(t)
	client, err := ssh.Dial("tcp", ln.Addr().String(), &ssh.ClientConfig{
		User:            "alice",
		Auth:            []ssh.AuthMethod{ssh.PublicKeys(userSigner)},
		HostKeyCallback: ssh.FixedHostKey(hostSigner.PublicKey()),
	})
	if err != nil {
		t.Fatalf("dial: %v", err)
	}
	defer client.Close()

	// Open 2 active sessions
	for i := range 2 {
		s, err := client.NewSession()
		if err != nil {
			t.Fatalf("session %d: %v", i, err)
		}
		if err := s.Start("sleep"); err != nil {
			t.Fatalf("start %d: %v", i, err)
		}
	}

	// 3rd concurrent session must be rejected with ResourceShortage.
	_, _, err = client.OpenChannel("session", nil)
	var openErr *ssh.OpenChannelError
	if !errors.As(err, &openErr) || openErr.Reason != ssh.ResourceShortage {
		t.Fatalf("expected ResourceShortage on 3rd channel, got %v", err)
	}

	cancel()
	wg.Wait()
}

func TestBuildChildEnvIncludesSSHUser(t *testing.T) {
	base := []string{"PATH=/usr/bin", "SSH_USER=spoofed"}
	sessionEnv := map[string]string{"TERM": "xterm-256color"}
	remoteAddr, _ := net.ResolveTCPAddr("tcp", "127.0.0.1:12345")
	localAddr, _ := net.ResolveTCPAddr("tcp", "127.0.0.1:2223")

	env := buildChildEnv(base, sessionEnv, remoteAddr, localAddr, "xterm-256color", "/dev/pts/1", true, "whoami", "alice", "SSH-2.0-OpenSSH_9.0", 80, 24)

	hasUser := false
	hasSpoofed := false
	for _, e := range env {
		if e == "SSH_USER=alice" {
			hasUser = true
		}
		if e == "SSH_USER=spoofed" {
			hasSpoofed = true
		}
	}

	if !hasUser {
		t.Errorf("expected SSH_USER=alice in env, got: %v", env)
	}
	if hasSpoofed {
		t.Errorf("expected spoofed SSH_USER to be replaced, got: %v", env)
	}
}
