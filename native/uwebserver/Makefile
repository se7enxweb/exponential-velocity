# uwebserver -- the small web server in C beside Exponential Velocity.
#
#   make -C native/uwebserver              build ../../sbin/uwebserver
#   make -C native/uwebserver install      install it, its manual page and
#                                          its bash completion under PREFIX
#   make -C native/uwebserver clean
#
# The release and build are taken from git when there is one; give
# UWEB_VERSION=v0.0.4.42 to build from a tree without it.

CC       ?= cc
PREFIX   ?= /usr/local
DESTDIR  ?=
OUT      ?= ../../sbin/uwebserver

UWEB_VERSION ?= $(shell git describe --tags --abbrev=0 2>/dev/null || echo unknown)
UWEB_BUILD   ?= $(shell git rev-parse --short HEAD 2>/dev/null || echo source)

WARN     = -Wall -Wextra -Wformat=2
CFLAGS  ?= -O2
LIBS     = -lssl -lcrypto
DEFS     = -DUWEB_VERSION='"$(UWEB_VERSION)"' -DUWEB_BUILD='"$(UWEB_BUILD)"'

SOURCES  = uwebserver.c u_options.h u_http.h u_mime.h u_log.h u_tls.h u_sendfile.h

.PHONY: all install clean

all: $(OUT)

$(OUT): $(SOURCES)
	$(CC) $(CFLAGS) $(WARN) $(DEFS) -o $@ uwebserver.c $(LDFLAGS) $(LIBS)

install: $(OUT)
	install -D -m 0755 $(OUT) $(DESTDIR)$(PREFIX)/sbin/uwebserver
	install -D -m 0644 ../../docs/uwebserver.1 $(DESTDIR)$(PREFIX)/share/man/man1/uwebserver.1
	install -D -m 0644 uwebserver.bash-completion $(DESTDIR)$(PREFIX)/share/bash-completion/completions/uwebserver

clean:
	rm -f $(OUT)
