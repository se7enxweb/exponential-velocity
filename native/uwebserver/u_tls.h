/* u_tls.h -- the TLS side of uwebserver, through OpenSSL 3.
 *
 * A server context built from the configuration (certificate chain, key,
 * extra chain file, lowest protocol version, TLS 1.2 ciphers, TLS 1.3
 * suites), ALPN that selects http/1.1 (the only protocol uwebserver
 * speaks), and connections driven without blocking: the handshake, reads
 * and writes each report when they need the socket readable or writable,
 * so one slow client never holds up the event loop.
 */
#ifndef U_TLS_H
#define U_TLS_H

#include <openssl/ssl.h>
#include <openssl/err.h>
#include <openssl/x509.h>
#include <openssl/x509v3.h>
#include <openssl/pem.h>
#include <errno.h>
#include <stdio.h>
#include <string.h>
#include <sys/stat.h>

/* The Mozilla "intermediate" TLS 1.2 ciphers: forward secrecy, AEAD only. */
#define UW_TLS_DEFAULT_CIPHERS \
    "ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:" \
    "ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:" \
    "ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305"

#define UW_TLS_WANT_READ  (-2)
#define UW_TLS_WANT_WRITE (-3)

static char uw_tls_errbuf[256];

static const char *uw_tls_error(void) {
    unsigned long e = ERR_get_error();
    if (e) ERR_error_string_n(e, uw_tls_errbuf, sizeof(uw_tls_errbuf));
    else if (!uw_tls_errbuf[0]) snprintf(uw_tls_errbuf, sizeof uw_tls_errbuf, "unknown error");
    ERR_clear_error();
    return uw_tls_errbuf;
}

static int uw_alpn_select(SSL *ssl, const unsigned char **out, unsigned char *outlen,
                          const unsigned char *in, unsigned int inlen, void *arg) {
    (void)ssl; (void)arg;
    for (unsigned int i = 0; i < inlen; ) {
        unsigned int l = in[i];
        if (i + 1 + l > inlen) break;
        if (l == 8 && memcmp(in + i + 1, "http/1.1", 8) == 0) { *out = in + i + 1; *outlen = 8; return SSL_TLSEXT_ERR_OK; }
        i += 1 + l;
    }
    return SSL_TLSEXT_ERR_NOACK;   /* no ALPN rather than a protocol we do not speak */
}

/*
 * The server context. min_version is "1.2" or "1.3". Returns NULL with a
 * message in err.
 */
static SSL_CTX *uw_tls_ctx_new(const char *cert, const char *key, const char *chain,
                              const char *min_version, const char *ciphers, const char *suites,
                              char *err, size_t errlen) {
    OPENSSL_init_ssl(OPENSSL_INIT_LOAD_SSL_STRINGS | OPENSSL_INIT_LOAD_CRYPTO_STRINGS, NULL);
    SSL_CTX *ctx = SSL_CTX_new(TLS_server_method());
    if (!ctx) { snprintf(err, errlen, "TLS: %s", uw_tls_error()); return NULL; }
    SSL_CTX_set_min_proto_version(ctx, (min_version && strcmp(min_version, "1.3") == 0) ? TLS1_3_VERSION : TLS1_2_VERSION);
    /* No compression (CRIME), no renegotiation, and no session tickets: their
     * key would live, unchanged, as long as the process (and be shared by every
     * worker); sessions resume from the server's cache instead. */
    SSL_CTX_set_options(ctx, SSL_OP_NO_COMPRESSION | SSL_OP_NO_RENEGOTIATION | SSL_OP_NO_TICKET);
#ifdef SSL_OP_ALLOW_CLIENT_RENEGOTIATION
    SSL_CTX_clear_options(ctx, SSL_OP_ALLOW_CLIENT_RENEGOTIATION);   /* OpenSSL 3 refuses it by default; keep it so */
#endif
    SSL_CTX_set_mode(ctx, SSL_MODE_ENABLE_PARTIAL_WRITE | SSL_MODE_ACCEPT_MOVING_WRITE_BUFFER | SSL_MODE_RELEASE_BUFFERS);
    if (SSL_CTX_set_cipher_list(ctx, ciphers && *ciphers ? ciphers : UW_TLS_DEFAULT_CIPHERS) != 1) {
        snprintf(err, errlen, "--tls-ciphers: no usable cipher in '%s'", ciphers ? ciphers : "");
        SSL_CTX_free(ctx); return NULL;
    }
    if (suites && *suites && SSL_CTX_set_ciphersuites(ctx, suites) != 1) {
        snprintf(err, errlen, "--tls-ciphersuites: no usable suite in '%s'", suites);
        SSL_CTX_free(ctx); return NULL;
    }
    if (SSL_CTX_use_certificate_chain_file(ctx, cert) != 1) {
        snprintf(err, errlen, "cannot load the certificate %s: %s", cert, uw_tls_error());
        SSL_CTX_free(ctx); return NULL;
    }
    if (chain && *chain) {
        FILE *f = fopen(chain, "re");
        if (!f) { snprintf(err, errlen, "cannot read the chain %s: %s", chain, strerror(errno)); SSL_CTX_free(ctx); return NULL; }
        X509 *x; int n = 0;
        while ((x = PEM_read_X509(f, NULL, NULL, NULL)) != NULL) {
            if (SSL_CTX_add0_chain_cert(ctx, x) != 1) { X509_free(x); fclose(f); snprintf(err, errlen, "cannot add a chain certificate from %s", chain); SSL_CTX_free(ctx); return NULL; }
            n++;
        }
        fclose(f);
        ERR_clear_error();
        if (n == 0) { snprintf(err, errlen, "%s holds no certificate", chain); SSL_CTX_free(ctx); return NULL; }
    }
    if (SSL_CTX_use_PrivateKey_file(ctx, key, SSL_FILETYPE_PEM) != 1) {
        if (ERR_GET_REASON(ERR_peek_last_error()) == X509_R_KEY_VALUES_MISMATCH) { ERR_clear_error(); snprintf(err, errlen, "the private key %s does not belong to the certificate %s", key, cert); }
        else snprintf(err, errlen, "cannot load the private key %s: %s", key, uw_tls_error());
        SSL_CTX_free(ctx); return NULL;
    }
    if (SSL_CTX_check_private_key(ctx) != 1) {
        snprintf(err, errlen, "the private key %s does not belong to the certificate %s", key, cert);
        SSL_CTX_free(ctx); return NULL;
    }
    SSL_CTX_set_session_cache_mode(ctx, SSL_SESS_CACHE_SERVER);
    SSL_CTX_sess_set_cache_size(ctx, 1024);
    SSL_CTX_set_alpn_select_cb(ctx, uw_alpn_select, NULL);
    return ctx;
}

/*
 * Whether a private key file may be used: refused (-1) when users other
 * than its owner may change it or anyone may read it; a warning (1) when
 * its group may read it; 0 when fine. The reason is in msg.
 */
static int uw_tls_key_mode_check(const char *key, char *msg, size_t msglen) {
    struct stat st;
    if (stat(key, &st) != 0) { snprintf(msg, msglen, "cannot read the private key %s: %s", key, strerror(errno)); return -1; }
    if (!S_ISREG(st.st_mode)) { snprintf(msg, msglen, "the private key %s is not a regular file", key); return -1; }
    if (st.st_mode & (S_IROTH | S_IWOTH)) {
        snprintf(msg, msglen, "the private key %s may be read or changed by any user (mode %04o); chmod 600 it", key, (unsigned)(st.st_mode & 07777));
        return -1;
    }
    if (st.st_mode & S_IWGRP) {
        snprintf(msg, msglen, "the private key %s may be changed by its group (mode %04o); chmod 640 or 600 it", key, (unsigned)(st.st_mode & 07777));
        return -1;
    }
    if (st.st_mode & S_IRGRP) {
        snprintf(msg, msglen, "the private key %s may be read by its group (mode %04o)", key, (unsigned)(st.st_mode & 07777));
        return 1;
    }
    return 0;
}

/* One step of the handshake: 1 done, UW_TLS_WANT_*, or -1 failed. */
static int uw_tls_handshake(SSL *ssl) {
    ERR_clear_error();
    int r = SSL_do_handshake(ssl);
    if (r == 1) return 1;
    int e = SSL_get_error(ssl, r);
    if (e == SSL_ERROR_WANT_READ) return UW_TLS_WANT_READ;
    if (e == SSL_ERROR_WANT_WRITE) return UW_TLS_WANT_WRITE;
    ERR_clear_error();
    return -1;
}

/* > 0 bytes, 0 the peer closed, UW_TLS_WANT_*, -1 error. */
static int uw_tls_read(SSL *ssl, void *buf, int len) {
    ERR_clear_error();
    int n = SSL_read(ssl, buf, len);
    if (n > 0) return n;
    int e = SSL_get_error(ssl, n);
    if (e == SSL_ERROR_WANT_READ) return UW_TLS_WANT_READ;
    if (e == SSL_ERROR_WANT_WRITE) return UW_TLS_WANT_WRITE;
    if (e == SSL_ERROR_ZERO_RETURN) return 0;
    ERR_clear_error();
    return -1;
}

/* > 0 bytes written, UW_TLS_WANT_*, -1 error. */
static int uw_tls_write(SSL *ssl, const void *buf, int len) {
    ERR_clear_error();
    int n = SSL_write(ssl, buf, len);
    if (n > 0) return n;
    int e = SSL_get_error(ssl, n);
    if (e == SSL_ERROR_WANT_READ) return UW_TLS_WANT_READ;
    if (e == SSL_ERROR_WANT_WRITE) return UW_TLS_WANT_WRITE;
    ERR_clear_error();
    return -1;
}

/* Subject, days left and DNS names of a certificate file, for the start-up line. */
static void uw_tls_describe(const char *path, char *out, size_t outlen) {
    snprintf(out, outlen, "%s", path);
    FILE *fp = fopen(path, "re");
    if (!fp) return;
    X509 *cert = PEM_read_X509(fp, NULL, NULL, NULL);
    fclose(fp);
    if (!cert) { ERR_clear_error(); return; }
    char subject[256] = "", san[256] = "";
    X509_NAME_oneline(X509_get_subject_name(cert), subject, sizeof subject);
    int day = 0, sec = 0;
    ASN1_TIME_diff(&day, &sec, NULL, X509_get0_notAfter(cert));
    GENERAL_NAMES *sans = X509_get_ext_d2i(cert, NID_subject_alt_name, NULL, NULL);
    if (sans) {
        size_t pos = 0;
        for (int i = 0; i < sk_GENERAL_NAME_num(sans); i++) {
            GENERAL_NAME *g = sk_GENERAL_NAME_value(sans, i);
            if (g->type != GEN_DNS) continue;
            /* An ASN.1 string has a length and need not end in NUL. */
            const unsigned char *d = ASN1_STRING_get0_data(g->d.dNSName);
            int dl = ASN1_STRING_length(g->d.dNSName);
            if (dl <= 0) continue;
            if (pos + (size_t)dl + 3 >= sizeof san) break;
            if (pos) { san[pos++] = ','; san[pos++] = ' '; }
            for (int k = 0; k < dl; k++) san[pos++] = (d[k] >= 0x20 && d[k] < 0x7f) ? (char)d[k] : '?';
            san[pos] = '\0';
        }
        GENERAL_NAMES_free(sans);
    }
    X509_free(cert);
    for (char *c = subject; *c; c++) if ((unsigned char)*c < 0x20 || *c == 0x7f) *c = '?';
    snprintf(out, outlen, "%s (%s %d days, names: %s)", subject, day < 0 ? "expired" : "expires in", day < 0 ? -day : day, san[0] ? san : "none");
}

#endif /* U_TLS_H */
