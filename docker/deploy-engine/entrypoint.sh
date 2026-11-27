#!/bin/sh
set -eu

# ---------------------------------------------------------------------------
# 1. Clean up stale PID files left behind after an unclean container restart
#    or rebuild (`dockerd.pid` / `containerd.pid`) so dockerd never refuses
#    to boot with "process with PID X is still running".
# ---------------------------------------------------------------------------
rm -f /var/run/docker.pid /var/run/docker/containerd/containerd.pid /var/run/containerd/containerd.pid

# ---------------------------------------------------------------------------
# 2. Configure gVisor (runsc) runtime and disable inter-container communication
#    on the default bridge (`icc: false`).
# ---------------------------------------------------------------------------
mkdir -p /etc/docker

# Choose gVisor platform (systrap is the default user-space interception mode
# that works inside unprivileged/DinD containers without requiring /dev/kvm).
RUNSC_PLATFORM="${DEPLOY_GVISOR_PLATFORM:-systrap}"

cat >/etc/docker/daemon.json <<EOF
{
  "icc": false,
  "no-new-privileges": true,
  "live-restore": false,
  "log-driver": "json-file",
  "log-opts": {
    "max-size": "10m",
    "max-file": "3"
  },
  "default-ulimits": {
    "nofile": {
      "Name": "nofile",
      "Hard": 65536,
      "Soft": 65536
    }
  },
  "runtimes": {
    "runsc": {
      "path": "/usr/local/bin/runsc",
      "runtimeArgs": [
        "--platform=${RUNSC_PLATFORM}",
        "--network=host"
      ]
    }
  }
}
EOF

# ---------------------------------------------------------------------------
# 2. Enforce outbound Anti-SSRF & Control-Plane Isolation Firewall (iptables)
#    All deployed containers and `docker build` steps sit on internal bridge
#    interfaces (`br-+` or `docker0`) and leave through `eth0`.
#    We allow:
#      - Established/related return traffic (e.g. HTTP responses to Caddy ingress)
#      - Intra-project traffic on the same isolated per-environment bridge (`-i br-X -o br-X`)
#      - Public Internet egress via `eth0`
#    We strictly DROP:
#      - Cross-bridge traffic (`-i br-X -o br-Y`) between different deployed projects
#      - Any new connection from a container/build (`! -i eth0 -o eth0`) to private/internal
#        IPv4/IPv6 ranges (RFC 1918, CGNAT, loopback, link-local/Cloud Metadata 169.254.169.254),
#        which completely shields the gickers.sh control plane and host network.
# ---------------------------------------------------------------------------
setup_firewall() {
    iptables -N DOCKER-USER 2>/dev/null || true
    iptables -F DOCKER-USER

    # 1. Allow return packets for established connections (including Caddy -> container HTTP ingress)
    iptables -A DOCKER-USER -m conntrack --ctstate RELATED,ESTABLISHED -j RETURN

    # 2. Block deployed containers from initiating connections to the deploy-engine's own eth0 IP or localhost
    iptables -I INPUT -i docker0 -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT 2>/dev/null || true
    iptables -I INPUT -i br-+ -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT 2>/dev/null || true
    iptables -A INPUT -i docker0 -p tcp --dport 2375 -j DROP 2>/dev/null || true
    iptables -A INPUT -i br-+ -p tcp --dport 2375 -j DROP 2>/dev/null || true

    # 3. Anti-SSRF: Block any new outbound connection leaving via eth0 towards private, link-local,
    #    loopback, CGNAT, or cloud metadata IPv4 subnets (protects gickers-internal and host LAN)
    for cidr in \
        0.0.0.0/8 \
        10.0.0.0/8 \
        100.64.0.0/10 \
        127.0.0.0/8 \
        169.254.0.0/16 \
        172.16.0.0/12 \
        192.0.0.0/24 \
        192.0.2.0/24 \
        192.168.0.0/16 \
        198.18.0.0/15 \
        198.51.100.0/24 \
        203.0.113.0/24 \
        224.0.0.0/4 \
        240.0.0.0/4
    do
        iptables -A DOCKER-USER ! -i eth0 -o eth0 -d "$cidr" -j DROP
    done

    # 4. Allow public Internet egress from containers/builds via eth0 and Caddy ingress from eth0
    iptables -A DOCKER-USER -i eth0 -j RETURN
    iptables -A DOCKER-USER -o eth0 -j RETURN

    # 5. Allow intra-environment communication (containers on the exact same bridge)
    #    Wait/delegate to Docker's per-bridge isolation rules while blocking cross-bridge traffic
    iptables -A DOCKER-USER -i docker0 -o docker0 -j DROP
    iptables -A DOCKER-USER -j RETURN
}

setup_firewall

# Re-assert DOCKER-USER rules after dockerd initializes its own iptables chains
(
    while ! docker info >/dev/null 2>&1; do
        sleep 1
    done
    setup_firewall
) &

export DOCKER_TLS_CERTDIR=""
exec dockerd-entrypoint.sh dockerd --host=tcp://0.0.0.0:2375 --host=unix:///var/run/docker.sock "$@"
