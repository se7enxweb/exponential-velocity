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

# Every warning that finds real mistakes, and -Werror so none is shipped;
# WERROR= builds anyway with a compiler that warns about something new.
WARN     = -Wall -Wextra -Wformat=2 -Wformat-signedness -Wshadow -Wnull-dereference \
           -Wstrict-prototypes -Wpointer-arith -Wcast-align -Wwrite-strings -Wundef \
           -Wvla -Wimplicit-fallthrough -Wduplicated-cond -Wlogical-op
WERROR  ?= -Werror
CFLAGS  ?= -O2
# Hardening of the binary that serves: fortified libc calls, a stack
# protector and stack-clash probes, control-flow protection where the
# compiler has it, a position-independent executable, full RELRO with
# immediate binding, and no executable stack. HARDEN= LDHARDEN= to build
# without (a toolchain that lacks one of them).
CF_PROTECTION := $(shell $(CC) -fcf-protection -E -x c /dev/null >/dev/null 2>&1 && echo -fcf-protection)
HARDEN   ?= -U_FORTIFY_SOURCE -D_FORTIFY_SOURCE=2 -fstack-protector-strong -fstack-clash-protection -fPIE $(CF_PROTECTION)
LDHARDEN ?= -pie -Wl,-z,relro -Wl,-z,now -Wl,-z,noexecstack
LIBS     = -lssl -lcrypto
DEFS     = -DUWEB_VERSION='"$(UWEB_VERSION)"' -DUWEB_BUILD='"$(UWEB_BUILD)"'

SOURCES  = uwebserver.c u_options.h u_http.h u_mime.h u_log.h u_tls.h u_sendfile.h Makefile

.PHONY: all sanitize fuzz install clean

# AddressSanitizer and UndefinedBehaviorSanitizer: a build for testing, never
# for serving (make sanitize; the tests take UWEB_TEST_CFLAGS for the same).
SANITIZE = -g -O1 -fno-omit-frame-pointer -fsanitize=address,undefined -fno-sanitize-recover=all
SAN_OUT ?= uwebserver-sanitize

# The request parser's fuzz harness (fuzz_request.c): its own deterministic
# fuzzer, under the sanitizers. ./uwebserver-fuzz [seconds] [seed]
FUZZ_OUT ?= uwebserver-fuzz

all: $(OUT)

$(OUT): $(SOURCES)
	$(CC) $(CFLAGS) $(HARDEN) $(WARN) $(WERROR) $(DEFS) -o $@ uwebserver.c $(LDHARDEN) $(LDFLAGS) $(LIBS)

sanitize: $(SAN_OUT)

$(SAN_OUT): $(SOURCES)
	$(CC) $(SANITIZE) $(WARN) $(WERROR) $(DEFS) -o $@ uwebserver.c $(LDFLAGS) $(LIBS)

fuzz: $(FUZZ_OUT)

$(FUZZ_OUT): fuzz_request.c u_http.h u_options.h u_log.h
	$(CC) $(SANITIZE) $(WARN) -Wno-unused-function $(WERROR) -DUW_FUZZ_MAIN -o $@ fuzz_request.c

install: $(OUT)
	install -D -m 0755 $(OUT) $(DESTDIR)$(PREFIX)/sbin/uwebserver
	install -D -m 0644 ../../docs/uwebserver.1 $(DESTDIR)$(PREFIX)/share/man/man1/uwebserver.1
	install -D -m 0644 uwebserver.bash-completion $(DESTDIR)$(PREFIX)/share/bash-completion/completions/uwebserver

clean:
	rm -f $(OUT) $(SAN_OUT) $(FUZZ_OUT)
