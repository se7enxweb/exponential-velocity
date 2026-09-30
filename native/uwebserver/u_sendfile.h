/* u_sendfile.h -- one step of sending part of a file to a non-blocking
 * socket, for uwebserver's event loop.
 *
 * uw_sendfile_step() sends at most `max` bytes from *off and returns what
 * sendfile(2) did: bytes sent (> 0, *off advanced), 0 when the file ended
 * before the range did (it was cut short while being sent), or -1 with
 * errno (EAGAIN: the socket is full, wait until it is writable). It never
 * loops on EAGAIN: the caller goes back to the event loop and is called
 * again when the socket can take more, so a slow reader costs nothing.
 *
 * Linux has sendfile(2); elsewhere pread + write in 64 KiB steps. TLS
 * connections never come here: their data has to go through SSL_write.
 */
#ifndef U_SENDFILE_H
#define U_SENDFILE_H

#include <sys/types.h>
#include <unistd.h>
#include <errno.h>

#ifdef __linux__
#include <sys/sendfile.h>
static ssize_t uw_sendfile_step(int sock, int fd, off_t *off, size_t max) {
    ssize_t n = sendfile(sock, fd, off, max);
    return n;
}
#else
static ssize_t uw_sendfile_step(int sock, int fd, off_t *off, size_t max) {
    char buf[65536];
    if (max > sizeof buf) max = sizeof buf;
    ssize_t r = pread(fd, buf, max, *off);
    if (r <= 0) return r;
    ssize_t w = write(sock, buf, (size_t)r);
    if (w > 0) *off += w;
    return w;
}
#endif

#endif /* U_SENDFILE_H */
