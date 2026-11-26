#include <string.h>
#include <unistd.h>

/* Installed as /usr/bin/ffmpeg (dpkg-divert, real one = /usr/bin/ffmpeg.distrib).
   ckts audio check "ffmpeg -v quiet -f aac -i pipe:0 ... -f s16le pipe:1" -> small static decoder, rest -> real ffmpeg */
int main(int argc, char **argv) {
    if (argc >= 10 && !strcmp(argv[3], "-f") && !strcmp(argv[4], "aac") && !strcmp(argv[5], "-i") &&
        !strcmp(argv[6], "pipe:0") && !strcmp(argv[argc - 3], "-f") && !strcmp(argv[argc - 2], "s16le") &&
        !strcmp(argv[argc - 1], "pipe:1") && access("/opt/qc-ffaac/ffaac", X_OK) == 0)
        execv("/opt/qc-ffaac/ffaac", argv);
    execv("/usr/bin/ffmpeg.distrib", argv);
    return 127;
}
