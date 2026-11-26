# The server on the official PHP image, with a variant of the extension
# baseline installed. Debian (bookworm) rather than Alpine: glibc, so the
# Oracle add-on's Instant Client works.
#
#   docker build -f packaging/docker/Dockerfile --build-arg PHP_VERSION=8.3 \
#     --build-arg VARIANT=standard -t exponential-velocity:php8.3-standard .
#
# VARIANT is mini, lite, standard or full (docs/binaries.md); WITH_ADDONS=1
# adds the Oracle and Firebird drivers and ODBC drivers to standard and full
# (docs/docker.md). Published as ghcr.io/se7enxweb/exponential-velocity.
ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli-bookworm

ARG VARIANT=standard
ARG WITH_ADDONS=auto
ENV QBIX_HOME=/usr/share/exponential-velocity \
    QBIX_VARIANT=${VARIANT}

COPY . /usr/share/exponential-velocity
RUN set -eux; \
    addons="$WITH_ADDONS"; \
    if [ "$addons" = auto ]; then case "$VARIANT" in standard|full) addons=1 ;; *) addons=0 ;; esac; fi; \
    bash /usr/share/exponential-velocity/packaging/docker/install-extensions.sh "$VARIANT" "$addons"; \
    # The daemon and its administration commands in sbin, as the packages
    # place them, and a link at the former path, /usr/local/bin/<tool>
    # (docs/layout.md, "Programs").
    for t in qbixserver qbixctl qbixconsole; do \
      install -m 0755 "/usr/share/exponential-velocity/packaging/sbin/$t" "/usr/local/sbin/$t"; \
      ln -s "../sbin/$t" "/usr/local/bin/$t"; \
    done; \
    # The shell, a user command, under the packages' name for it: vc-qshell,
    # a link to bin/qshell.php (qshell is Qiniu's command-line tool).
    ln -s /usr/share/exponential-velocity/bin/qshell.php /usr/local/bin/vc-qshell; \
    mkdir -p /app; \
    # The configuration trees, laid out like Debian's /etc/apache2
    # (docs/layout.md): the engine's base tree /etc/qbix as the packages
    # install it (qbix.conf, ports.conf, envvars, sites-available/default.conf),
    # and Velocity's overlay /etc/vc with the same directories, empty, for a
    # site file of your own; then the FHS directories for state, logs, cache
    # and the run files. All of it belongs to the run user (www-data), so a
    # tree mounted or written there needs no root.
    cp -a /usr/share/exponential-velocity/packaging/etc/qbix /etc/qbix; \
    for d in /etc/qbix /etc/vc; do \
      for k in sites conf mods; do mkdir -p "$d/$k-available" "$d/$k-enabled"; done; \
    done; \
    mkdir -p /etc/vc/designs /var/lib/vc /var/log/vc /var/cache/vc /run/vc; \
    ln -s ../sites-available/default.conf /etc/qbix/sites-enabled/default.conf; \
    chown -R www-data:www-data /etc/qbix /etc/vc /var/lib/vc /var/log/vc /var/cache/vc /run/vc; \
    # The default command's application directory is / (the parent of
    # --root=/app): the control panel's credential store (/local) and the
    # certificates (/config/certs) are made there for the run user, as the
    # panel's trust rule wants them (its own, not writable by others).
    install -d -o www-data -g www-data -m 0700 /local /config/certs; \
    chown www-data:www-data /config; \
    # The image's former install path, for mounts and scripts that name it.
    ln -s exponential-velocity /usr/share/qbix-webserver; \
    cp -a /usr/share/exponential-velocity/web/. /app/; \
    # The image's baseline, checked by the engine itself: fails the build if
    # a required extension is missing.
    qbixctl ext:check --variant="$VARIANT"

# The server runs as www-data, not root. Docker and Podman let a container's
# user bind any port, so --port=80 still works; for root, run with --user root.
USER www-data
WORKDIR /app
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD ["php", "/usr/share/exponential-velocity/packaging/docker/healthcheck.php"]
CMD ["qbixserver", "--root=/app", "--port=8080"]
