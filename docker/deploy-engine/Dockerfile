# Dedicated, isolated Docker-in-Docker deployment engine for gickers.sh.
# - Never touches the host's /var/run/docker.sock
# - Installs gVisor (`runsc`) so user-deployed containers run inside an application kernel sandbox
# - Enforces an outbound anti-SSRF firewall (DOCKER-USER iptables chain) that blocks all traffic
#   from deployed containers/builds to private RFC1918 networks, link-local cloud metadata, and
#   the gickers.sh control plane.

FROM docker:27-dind

RUN apk add --no-cache \
        ca-certificates \
        curl \
        ip6tables \
        iptables \
        tar \
        zstd \
    && ARCH="$(uname -m)" \
    && URL="https://storage.googleapis.com/gvisor/releases/release/latest/${ARCH}" \
    && if curl -fsSL "${URL}/gvisor.tar.zstd" -o /tmp/gvisor.tar.zstd; then \
        tar --zstd -xf /tmp/gvisor.tar.zstd -C /usr/local/bin \
        && rm -f /tmp/gvisor.tar.zstd; \
    else \
        curl -fsSL "${URL}/runsc" -o /usr/local/bin/runsc \
        && curl -fsSL "${URL}/containerd-shim-runsc-v1" -o /usr/local/bin/containerd-shim-runsc-v1; \
    fi \
    && chmod +x /usr/local/bin/runsc /usr/local/bin/containerd-shim-runsc-v1

COPY docker/deploy-engine/entrypoint.sh /usr/local/bin/gickers-deploy-entrypoint.sh
RUN ln -sf /usr/local/bin/gickers-deploy-entrypoint.sh /usr/local/bin/codes-deploy-entrypoint.sh \
    && chmod +x /usr/local/bin/gickers-deploy-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/gickers-deploy-entrypoint.sh"]
