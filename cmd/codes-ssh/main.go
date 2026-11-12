package main

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/hex"
	"encoding/pem"
	"errors"
	"flag"
	"fmt"
	"io"
	"log"
	"math"
	"net"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"strings"
	"sync"
	"syscall"
	"time"

	"github.com/creack/pty"
	"golang.org/x/crypto/ssh"
)

const (
	defaultListenAddr     = "127.0.0.1:2223"
	handshakeTimeout      = 10 * time.Second
	maxConcurrentSessions = 128
	ptyKillGrace          = 2 * time.Second
	shutdownKillGrace     = 3 * time.Second
)

type ptyReqPayload struct {
	Term     string
	Width    uint32
	Height   uint32
	WidthPx  uint32
	HeightPx uint32
	Modes    string
}

type windowChangePayload struct {
	Width    uint32
	Height   uint32
	WidthPx  uint32
	HeightPx uint32
}

type execPayload struct {
	Command string
}

type envPayload struct {
	Name  string
	Value string
}

type exitStatusPayload struct {
	Status uint32
}

type sessionState struct {
	env      map[string]string
	hasPty   bool
	ptyTerm  string
	width    uint32
	height   uint32
	widthPx  uint32
	heightPx uint32
	modes    string
}

type server struct {
	cfg        *ssh.ServerConfig
	phpBin     string
	consoleBin string
	logger     *log.Logger
	sessionSem chan struct{}
	sessionWg  sync.WaitGroup
	connWg     sync.WaitGroup

	mu      sync.Mutex
	preAuth map[net.Conn]struct{}
	authed  map[net.Conn]struct{}
}

func newServer(cfg *ssh.ServerConfig, phpBin, consoleBin string, logger *log.Logger) *server {
	return &server{
		cfg:        cfg,
		phpBin:     phpBin,
		consoleBin: consoleBin,
		logger:     logger,
		sessionSem: make(chan struct{}, maxConcurrentSessions),
		preAuth:    make(map[net.Conn]struct{}),
		authed:     make(map[net.Conn]struct{}),
	}
}

func main() {
	defaultDataPath := os.Getenv("SOFT_SERVE_DATA_PATH")
	if strings.TrimSpace(defaultDataPath) == "" {
		defaultDataPath = ".data"
	}
	defaultHostKey := filepath.Join(defaultDataPath, "ssh", "soft_serve_host_ed25519")
	defaultPHP, err := exec.LookPath("php")
	if err != nil || defaultPHP == "" {
		defaultPHP = "php"
	}

	listenAddr := flag.String("listen", defaultListenAddr, "TCP address to listen on (host:port)")
	hostKeyPath := flag.String("host-key", defaultHostKey, "Path to the Ed25519 SSH host private key")
	phpBin := flag.String("php", defaultPHP, "Path to the PHP CLI binary")
	consoleBin := flag.String("console", "bin/console", "Path to Symfony bin/console")
	flag.Parse()

	logger := log.New(os.Stderr, "", 0)

	absHostKey, err := filepath.Abs(*hostKeyPath)
	if err != nil {
		logger.Fatalf("resolve host-key path: %v", err)
	}
	absConsole, err := filepath.Abs(*consoleBin)
	if err != nil {
		logger.Fatalf("resolve console path: %v", err)
	}
	resolvedPHP := *phpBin
	if strings.ContainsRune(resolvedPHP, filepath.Separator) {
		if absPHP, absErr := filepath.Abs(resolvedPHP); absErr == nil {
			resolvedPHP = absPHP
		}
	}

	hostSigner, generated, err := ensureHostKey(absHostKey)
	if err != nil {
		logger.Fatalf("host key error: %v", err)
	}
	if generated {
		logger.Printf("generated SSH host key %s", absHostKey)
	}

	ln, err := net.Listen("tcp", *listenAddr)
	if err != nil {
		logger.Fatalf("listen %s: %v", *listenAddr, err)
	}
	logger.Printf("listening on %s", ln.Addr().String())

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	srv := newServer(newSSHServerConfig(hostSigner), resolvedPHP, absConsole, logger)
	if err := srv.serve(ctx, ln); err != nil {
		logger.Fatalf("server error: %v", err)
	}
}

func ensureHostKey(path string) (ssh.Signer, bool, error) {
	info, err := os.Stat(path)
	if err == nil {
		if info.IsDir() {
			return nil, false, fmt.Errorf("host key path %q is a directory", path)
		}
		pemBytes, readErr := os.ReadFile(path)
		if readErr != nil {
			return nil, false, fmt.Errorf("read host key %q: %w", path, readErr)
		}
		signer, parseErr := ssh.ParsePrivateKey(pemBytes)
		if parseErr != nil {
			return nil, false, fmt.Errorf("parse host key %q: %w", path, parseErr)
		}
		return signer, false, nil
	}
	if !errors.Is(err, os.ErrNotExist) {
		return nil, false, fmt.Errorf("stat host key %q: %w", path, err)
	}

	dir := filepath.Dir(path)
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return nil, false, fmt.Errorf("create host key directory %q: %w", dir, err)
	}

	pub, priv, err := ed25519.GenerateKey(nil)
	if err != nil {
		return nil, false, fmt.Errorf("generate ed25519 host key: %w", err)
	}

	pemBlock, err := ssh.MarshalPrivateKey(priv, "soft-serve")
	if err != nil {
		return nil, false, fmt.Errorf("marshal host private key: %w", err)
	}
	pemBytes := pem.EncodeToMemory(pemBlock)
	if err := writeFileAtomic(path, pemBytes, 0o600); err != nil {
		return nil, false, fmt.Errorf("write host key %q: %w", path, err)
	}

	sshPub, err := ssh.NewPublicKey(pub)
	if err != nil {
		return nil, false, fmt.Errorf("convert host public key: %w", err)
	}
	pubBytes := ssh.MarshalAuthorizedKey(sshPub)
	if err := writeFileAtomic(path+".pub", pubBytes, 0o644); err != nil {
		return nil, false, fmt.Errorf("write host public key %q: %w", path+".pub", err)
	}

	signer, err := ssh.NewSignerFromKey(priv)
	if err != nil {
		return nil, false, fmt.Errorf("create host key signer: %w", err)
	}
	return signer, true, nil
}

func writeFileAtomic(path string, data []byte, mode os.FileMode) error {
	var rnd [8]byte
	if _, err := rand.Read(rnd[:]); err != nil {
		return err
	}
	tmpPath := fmt.Sprintf("%s.tmp.%s", path, hex.EncodeToString(rnd[:]))

	f, err := os.OpenFile(tmpPath, os.O_WRONLY|os.O_CREATE|os.O_EXCL, mode)
	if err != nil {
		return err
	}

	cleanup := func() {
		_ = os.Remove(tmpPath)
	}

	written := 0
	for written < len(data) {
		n, wErr := f.Write(data[written:])
		if wErr != nil {
			_ = f.Close()
			cleanup()
			return wErr
		}
		written += n
	}

	if err := f.Sync(); err != nil {
		_ = f.Close()
		cleanup()
		return err
	}
	if err := f.Close(); err != nil {
		cleanup()
		return err
	}
	if err := os.Rename(tmpPath, path); err != nil {
		cleanup()
		return err
	}
	return nil
}

func newSSHServerConfig(hostSigner ssh.Signer) *ssh.ServerConfig {
	cfg := &ssh.ServerConfig{
		NoClientAuth:                false,
		MaxAuthTries:                6,
		PasswordCallback:            nil,
		KeyboardInteractiveCallback: nil,
		PublicKeyCallback: func(_ ssh.ConnMetadata, key ssh.PublicKey) (*ssh.Permissions, error) {
			if _, ok := key.(*ssh.Certificate); ok {
				return nil, errors.New("ssh certificates not supported")
			}
			authKey := strings.TrimSpace(string(ssh.MarshalAuthorizedKey(key)))
			if authKey == "" {
				return nil, errors.New("invalid public key")
			}
			return &ssh.Permissions{
				Extensions: map[string]string{
					"pubkey": authKey,
				},
			}, nil
		},
	}
	cfg.AddHostKey(hostSigner)
	return cfg
}

func (s *server) serve(ctx context.Context, ln net.Listener) error {
	go func() {
		<-ctx.Done()
		_ = ln.Close()
		s.closePreAuthConns()
	}()

	for {
		conn, err := ln.Accept()
		if err != nil {
			if ctx.Err() != nil || errors.Is(err, net.ErrClosed) {
				break
			}
			var ne net.Error
			if errors.As(err, &ne) && ne.Timeout() {
				continue
			}
			return err
		}

		s.trackPreAuth(conn)
		s.connWg.Add(1)
		go func(c net.Conn) {
			defer s.connWg.Done()
			s.handleConn(ctx, c)
		}(conn)
	}

	s.sessionWg.Wait()
	s.closeAllConns()
	s.connWg.Wait()
	return nil
}

func (s *server) trackPreAuth(c net.Conn) {
	s.mu.Lock()
	s.preAuth[c] = struct{}{}
	s.mu.Unlock()
}

func (s *server) promoteAuthed(c net.Conn) {
	s.mu.Lock()
	delete(s.preAuth, c)
	s.authed[c] = struct{}{}
	s.mu.Unlock()
}

func (s *server) untrackConn(c net.Conn) {
	s.mu.Lock()
	delete(s.preAuth, c)
	delete(s.authed, c)
	s.mu.Unlock()
}

func (s *server) closePreAuthConns() {
	s.mu.Lock()
	conns := make([]net.Conn, 0, len(s.preAuth))
	for c := range s.preAuth {
		conns = append(conns, c)
	}
	s.mu.Unlock()

	for _, c := range conns {
		_ = c.Close()
	}
}

func (s *server) closeAllConns() {
	s.mu.Lock()
	conns := make([]net.Conn, 0, len(s.preAuth)+len(s.authed))
	for c := range s.preAuth {
		conns = append(conns, c)
	}
	for c := range s.authed {
		conns = append(conns, c)
	}
	s.mu.Unlock()

	for _, c := range conns {
		_ = c.Close()
	}
}

func (s *server) handleConn(ctx context.Context, netConn net.Conn) {
	_ = netConn.SetDeadline(time.Now().Add(handshakeTimeout))
	sshConn, chans, reqs, err := ssh.NewServerConn(netConn, s.cfg)
	if err != nil {
		s.untrackConn(netConn)
		_ = netConn.Close()
		return
	}
	_ = netConn.SetDeadline(time.Time{})
	s.promoteAuthed(netConn)
	defer func() {
		s.untrackConn(netConn)
		_ = sshConn.Close()
	}()

	go handleGlobalRequests(reqs)

	var pubkey string
	if sshConn.Permissions != nil {
		pubkey = sshConn.Permissions.Extensions["pubkey"]
	}

	for newChan := range chans {
		if newChan.ChannelType() != "session" {
			_ = newChan.Reject(ssh.Prohibited, "channel type not allowed")
			continue
		}
		if pubkey == "" {
			_ = newChan.Reject(ssh.Prohibited, "missing public key")
			continue
		}

		select {
		case s.sessionSem <- struct{}{}:
		default:
			_ = newChan.Reject(ssh.ResourceShortage, "too many concurrent sessions")
			continue
		}

		ch, chanReqs, err := newChan.Accept()
		if err != nil {
			<-s.sessionSem
			continue
		}

		s.sessionWg.Add(1)
		go func(c ssh.Channel, r <-chan *ssh.Request, key string) {
			defer func() {
				<-s.sessionSem
				s.sessionWg.Done()
			}()
			s.handleSession(ctx, sshConn, c, r, key)
		}(ch, chanReqs, pubkey)
	}
}

func handleGlobalRequests(reqs <-chan *ssh.Request) {
	for req := range reqs {
		switch req.Type {
		case "keepalive@openssh.com":
			_ = req.Reply(true, nil)
		default:
			_ = req.Reply(false, nil)
		}
	}
}

func (s *server) handleSession(
	ctx context.Context,
	sshConn *ssh.ServerConn,
	ch ssh.Channel,
	reqs <-chan *ssh.Request,
	pubkey string,
) {
	state := &sessionState{
		env: make(map[string]string),
	}

	for {
		select {
		case <-ctx.Done():
			_ = ch.Close()
			return
		case req, ok := <-reqs:
			if !ok {
				_ = ch.Close()
				return
			}

			switch req.Type {
			case "env":
				var p envPayload
				if err := ssh.Unmarshal(req.Payload, &p); err != nil || !isAllowedEnvVar(p.Name) || containsNul(p.Value) {
					_ = req.Reply(false, nil)
					continue
				}
				state.env[p.Name] = p.Value
				_ = req.Reply(true, nil)

			case "pty-req":
				if state.hasPty {
					_ = req.Reply(false, nil)
					continue
				}
				var p ptyReqPayload
				if err := ssh.Unmarshal(req.Payload, &p); err != nil || containsNul(p.Term) {
					_ = req.Reply(false, nil)
					continue
				}
				state.hasPty = true
				state.ptyTerm = p.Term
				state.width = p.Width
				state.height = p.Height
				state.widthPx = p.WidthPx
				state.heightPx = p.HeightPx
				state.modes = p.Modes
				_ = req.Reply(true, nil)

			case "window-change":
				var p windowChangePayload
				if err := ssh.Unmarshal(req.Payload, &p); err != nil {
					_ = req.Reply(false, nil)
					continue
				}
				state.width = p.Width
				state.height = p.Height
				state.widthPx = p.WidthPx
				state.heightPx = p.HeightPx
				_ = req.Reply(state.hasPty, nil)

			case "subsystem":
				_ = req.Reply(false, nil)

			case "exec":
				var p execPayload
				if err := ssh.Unmarshal(req.Payload, &p); err != nil || containsNul(p.Command) {
					_ = req.Reply(false, nil)
					_ = ch.Close()
					return
				}
				s.executeSession(ctx, sshConn, ch, reqs, req, pubkey, state, true, p.Command)
				return

			case "shell":
				s.executeSession(ctx, sshConn, ch, reqs, req, pubkey, state, false, "")
				return

			default:
				_ = req.Reply(false, nil)
			}
		}
	}
}

func (s *server) executeSession(
	ctx context.Context,
	sshConn *ssh.ServerConn,
	ch ssh.Channel,
	reqs <-chan *ssh.Request,
	startReq *ssh.Request,
	pubkey string,
	state *sessionState,
	isExec bool,
	execCmd string,
) {
	if state.hasPty {
		s.runPTYSession(ctx, sshConn, ch, reqs, startReq, pubkey, state, isExec, execCmd)
		return
	}
	s.runPipeSession(ctx, sshConn, ch, reqs, startReq, pubkey, state, isExec, execCmd)
}

func (s *server) runPTYSession(
	ctx context.Context,
	sshConn *ssh.ServerConn,
	ch ssh.Channel,
	reqs <-chan *ssh.Request,
	startReq *ssh.Request,
	pubkey string,
	state *sessionState,
	isExec bool,
	execCmd string,
) {
	ptmx, tty, err := pty.Open()
	if err != nil {
		_ = startReq.Reply(false, nil)
		_, _ = fmt.Fprintf(ch.Stderr(), "pty open failed: %v\n", err)
		sendExitStatus(ch, 1)
		_ = ch.Close()
		return
	}

	var ptyMu sync.Mutex
	ptyClosed := false
	closePtmx := func() {
		ptyMu.Lock()
		if !ptyClosed {
			ptyClosed = true
			_ = ptmx.Close()
		}
		ptyMu.Unlock()
	}

	_ = pty.Setsize(ptmx, &pty.Winsize{
		Rows: clampUint16(state.height, 24),
		Cols: clampUint16(state.width, 80),
		X:    clampUint16(state.widthPx, 0),
		Y:    clampUint16(state.heightPx, 0),
	})

	ttyName := tty.Name()
	cmd := exec.Command(s.phpBin, s.consoleBin, "soft:ssh:dispatch", "--key="+pubkey)
	cmd.Env = buildChildEnv(os.Environ(), state.env, sshConn.RemoteAddr(), sshConn.LocalAddr(), state.ptyTerm, ttyName, isExec, execCmd)
	cmd.Stdin = tty
	cmd.Stdout = tty
	cmd.Stderr = tty
	cmd.SysProcAttr = &syscall.SysProcAttr{
		Setsid:  true,
		Setctty: true,
		Ctty:    0,
	}

	if err := cmd.Start(); err != nil {
		_ = tty.Close()
		closePtmx()
		_ = startReq.Reply(false, nil)
		_, _ = fmt.Fprintf(ch.Stderr(), "start failed: %v\n", err)
		sendExitStatus(ch, 1)
		_ = ch.Close()
		return
	}
	_ = tty.Close()
	_ = startReq.Reply(true, nil)

	chanClosed := make(chan struct{})
	go func() {
		defer close(chanClosed)
		for req := range reqs {
			switch req.Type {
			case "window-change":
				var wc windowChangePayload
				if err := ssh.Unmarshal(req.Payload, &wc); err == nil {
					ptyMu.Lock()
					if !ptyClosed {
						_ = pty.Setsize(ptmx, &pty.Winsize{
							Rows: clampUint16(wc.Height, 24),
							Cols: clampUint16(wc.Width, 80),
							X:    clampUint16(wc.WidthPx, 0),
							Y:    clampUint16(wc.HeightPx, 0),
						})
					}
					ptyMu.Unlock()
					_ = req.Reply(true, nil)
				} else {
					_ = req.Reply(false, nil)
				}
			default:
				_ = req.Reply(false, nil)
			}
		}
	}()

	procDone := make(chan struct{})
	watchDone := startDisconnectWatcher(ctx, cmd.Process.Pid, procDone, chanClosed, ptyKillGrace, shutdownKillGrace)

	outDone := make(chan struct{})
	go func() {
		defer close(outDone)
		_, _ = io.Copy(ch, ptmx)
	}()

	go func() {
		_, _ = io.Copy(ptmx, ch)
	}()

	<-outDone
	waitErr := cmd.Wait()
	close(procDone)
	<-watchDone
	closePtmx()

	_ = ch.CloseWrite()
	sendExitStatus(ch, exitCodeFromErr(waitErr))
	_ = ch.Close()
}

func (s *server) runPipeSession(
	ctx context.Context,
	sshConn *ssh.ServerConn,
	ch ssh.Channel,
	reqs <-chan *ssh.Request,
	startReq *ssh.Request,
	pubkey string,
	state *sessionState,
	isExec bool,
	execCmd string,
) {
	cmd := exec.Command(s.phpBin, s.consoleBin, "soft:ssh:dispatch", "--key="+pubkey)
	cmd.Env = buildChildEnv(os.Environ(), state.env, sshConn.RemoteAddr(), sshConn.LocalAddr(), "", "", isExec, execCmd)
	cmd.SysProcAttr = &syscall.SysProcAttr{
		Setpgid: true,
	}

	stdinPipe, err := cmd.StdinPipe()
	if err != nil {
		_ = startReq.Reply(false, nil)
		_, _ = fmt.Fprintf(ch.Stderr(), "stdin pipe failed: %v\n", err)
		sendExitStatus(ch, 1)
		_ = ch.Close()
		return
	}
	stdoutPipe, err := cmd.StdoutPipe()
	if err != nil {
		_ = startReq.Reply(false, nil)
		_, _ = fmt.Fprintf(ch.Stderr(), "stdout pipe failed: %v\n", err)
		sendExitStatus(ch, 1)
		_ = ch.Close()
		return
	}
	stderrPipe, err := cmd.StderrPipe()
	if err != nil {
		_ = startReq.Reply(false, nil)
		_, _ = fmt.Fprintf(ch.Stderr(), "stderr pipe failed: %v\n", err)
		sendExitStatus(ch, 1)
		_ = ch.Close()
		return
	}

	if err := cmd.Start(); err != nil {
		_ = startReq.Reply(false, nil)
		_, _ = fmt.Fprintf(ch.Stderr(), "start failed: %v\n", err)
		sendExitStatus(ch, 1)
		_ = ch.Close()
		return
	}
	_ = startReq.Reply(true, nil)

	chanClosed := make(chan struct{})
	go func() {
		defer close(chanClosed)
		for req := range reqs {
			_ = req.Reply(false, nil)
		}
	}()

	procDone := make(chan struct{})
	watchDone := startDisconnectWatcher(ctx, cmd.Process.Pid, procDone, chanClosed, 0, shutdownKillGrace)

	var ioWg sync.WaitGroup
	ioWg.Add(2)
	go func() {
		defer ioWg.Done()
		_, _ = io.Copy(ch, stdoutPipe)
	}()
	go func() {
		defer ioWg.Done()
		_, _ = io.Copy(ch.Stderr(), stderrPipe)
	}()

	go func() {
		_, _ = io.Copy(stdinPipe, ch)
		_ = stdinPipe.Close()
	}()

	ioWg.Wait()
	waitErr := cmd.Wait()
	close(procDone)
	<-watchDone

	_ = ch.CloseWrite()
	sendExitStatus(ch, exitCodeFromErr(waitErr))
	_ = ch.Close()
}

func startDisconnectWatcher(
	ctx context.Context,
	pid int,
	procDone <-chan struct{},
	chanClosed <-chan struct{},
	disconnectGrace time.Duration,
	shutdownGrace time.Duration,
) <-chan struct{} {
	done := make(chan struct{})
	go func() {
		defer close(done)
		select {
		case <-procDone:
			return
		case <-chanClosed:
			killProcessGroup(pid, procDone, disconnectGrace)
		case <-ctx.Done():
			killProcessGroup(pid, procDone, shutdownGrace)
		}
	}()
	return done
}

func killProcessGroup(pid int, procDone <-chan struct{}, grace time.Duration) {
	if pid <= 0 {
		return
	}
	if grace <= 0 {
		_ = syscall.Kill(-pid, syscall.SIGKILL)
		return
	}

	_ = syscall.Kill(-pid, syscall.SIGTERM)
	timer := time.NewTimer(grace)
	defer timer.Stop()

	select {
	case <-procDone:
		return
	case <-timer.C:
		select {
		case <-procDone:
			return
		default:
			_ = syscall.Kill(-pid, syscall.SIGKILL)
		}
	}
}

func sendExitStatus(ch ssh.Channel, code uint32) {
	payload := ssh.Marshal(exitStatusPayload{Status: code})
	_, _ = ch.SendRequest("exit-status", false, payload)
}

func exitCodeFromErr(err error) uint32 {
	if err == nil {
		return 0
	}
	var exitErr *exec.ExitError
	if errors.As(err, &exitErr) {
		code := exitErr.ExitCode()
		if code >= 0 {
			return uint32(code)
		}
		if ws, ok := exitErr.Sys().(syscall.WaitStatus); ok && ws.Signaled() {
			return uint32(128 + int(ws.Signal()))
		}
	}
	return 1
}

func isAllowedEnvVar(name string) bool {
	if containsNul(name) {
		return false
	}
	switch name {
	case "TERM", "COLORTERM", "LANG":
		return true
	}
	if !strings.HasPrefix(name, "LC_") || len(name) <= 3 {
		return false
	}
	for i := 3; i < len(name); i++ {
		c := name[i]
		if !((c >= 'A' && c <= 'Z') || (c >= '0' && c <= '9') || c == '_') {
			return false
		}
	}
	return true
}

func containsNul(s string) bool {
	return strings.IndexByte(s, 0) >= 0
}

func buildChildEnv(
	base []string,
	sessionEnv map[string]string,
	remoteAddr net.Addr,
	localAddr net.Addr,
	ptyTerm string,
	ttyName string,
	isExec bool,
	execCmd string,
) []string {
	overrides := make(map[string]string, len(sessionEnv)+5)
	for k, v := range sessionEnv {
		overrides[k] = v
	}
	if ttyName != "" {
		overrides["SSH_TTY"] = ttyName
		if ptyTerm != "" {
			overrides["TERM"] = ptyTerm
		}
	}
	if isExec {
		overrides["SSH_ORIGINAL_COMMAND"] = execCmd
	}

	clientIP, clientPort := splitHostPort(remoteAddr)
	serverIP, serverPort := splitHostPort(localAddr)
	overrides["SSH_CONNECTION"] = fmt.Sprintf("%s %s %s %s", clientIP, clientPort, serverIP, serverPort)
	overrides["SSH_CLIENT"] = fmt.Sprintf("%s %s %s", clientIP, clientPort, serverPort)

	stripped := map[string]bool{
		"SSH_ORIGINAL_COMMAND": true,
		"SSH_CONNECTION":       true,
		"SSH_CLIENT":           true,
		"SSH_TTY":              true,
	}

	out := make([]string, 0, len(base)+len(overrides))
	for _, entry := range base {
		k, _, ok := strings.Cut(entry, "=")
		if !ok {
			continue
		}
		if stripped[k] {
			continue
		}
		if _, replaced := overrides[k]; replaced {
			continue
		}
		out = append(out, entry)
	}
	for k, v := range overrides {
		out = append(out, k+"="+v)
	}
	return out
}

func splitHostPort(addr net.Addr) (string, string) {
	if addr == nil {
		return "127.0.0.1", "0"
	}
	host, port, err := net.SplitHostPort(addr.String())
	if err != nil {
		return "127.0.0.1", "0"
	}
	if host == "" {
		host = "127.0.0.1"
	}
	if port == "" {
		port = "0"
	}
	return host, port
}

func clampUint16(val uint32, fallback uint16) uint16 {
	if val == 0 {
		return fallback
	}
	if val > math.MaxUint16 {
		return math.MaxUint16
	}
	return uint16(val)
}
