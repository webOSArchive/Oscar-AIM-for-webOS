#!/bin/sh
# Builds libpurple's AIM plugin (Pidgin 2.14.2's OSCAR code, the last release that has it)
# as one libaim.so for webOS CE's libpurple 2.14.13 on the TouchPad (ARMv7, softfp, glibc).
#
# Inputs, under ../../build/touchpad/ (not committed):
#   deb/x/       Debian lenny's libglib2.0-dev 2.16.6 armel, for the headers (the device has 2.16.6)
#   devlib/      the device's libglib/gobject/gmodule and libpurple.so.0 (CE's 2.14.13), to link to
# and the Pidgin sources under ../../ref/: 2.14.2 for oscar/, 2.14.13 for libpurple's headers.
# Toolchain: crosstool-NG arm-cortex_a8-linux-gnueabi (softfp, NEON).
set -e
cd "$(dirname "$0")"
HERE=$(pwd)
ROOT=$HERE/../..
B=$ROOT/build/touchpad
CC=${CC:-$HOME/x-tools/arm-cortex_a8-linux-gnueabi/bin/arm-cortex_a8-linux-gnueabi-gcc}
OSCAR=$ROOT/ref/pidgin-2.14.2/libpurple/protocols/oscar
PURPLE=$ROOT/ref/pidgin-2.14.13/libpurple
OUT=$B/out
mkdir -p $OUT/obj

# The few config.h answers the plugin and libpurple's internal.h ask for.
cat > $OUT/config.h <<'H'
#define VERSION "2.14.13"
#define DISPLAY_VERSION "2.14.13"
#define PURPLE_PLUGINS 1
#define SIZEOF_TIME_T 4
#define HAVE_ENDIAN_H 1
#define HAVE_ICONV 1
#define HAVE_LANGINFO_CODESET 1
#define HAVE_TM_GMTOFF 1
#define HAVE_TIMEZONE 1
#define HAVE_STRUCT_TM_TM_ZONE 1
#define HAVE_GETADDRINFO 1
#define HAVE_INET_ATON 1
#define HAVE_INET_NTOP 1
#define HAVE_FILENO 1
#define LIBDIR "/usr/lib/purple-2"
#define DATADIR "/usr/share"
#define SYSCONFDIR "/etc"
#define LOCALEDIR "/usr/share/locale"
#define PACKAGE "pidgin"
#define GETTEXT_PACKAGE "pidgin"
H

# libpurple's version.h is generated; CE's library is 2.14.13.
sed -e 's/@PURPLE_MAJOR_VERSION@/2/; s/@PURPLE_MINOR_VERSION@/14/; s/@PURPLE_MICRO_VERSION@/13/' \
    $PURPLE/version.h.in > $OUT/version.h
mkdir -p $OUT/inc && ln -sfn $PURPLE $OUT/inc/libpurple

CFLAGS="-O2 -fPIC -DHAVE_CONFIG_H -DPURPLE_PLUGINS -U_FORTIFY_SOURCE -fno-stack-protector \
 -I$OUT -I$PURPLE -I$OUT/inc -I$OSCAR \
 -I$B/deb/x/usr/include/glib-2.0 -I$B/deb/x/usr/lib/glib-2.0/include \
 -Wno-deprecated-declarations -Wno-incompatible-pointer-types -Wno-int-conversion -Wno-implicit-function-declaration"

SRCS="authorization.c bstream.c clientlogin.c encoding.c family_admin.c family_alert.c family_auth.c family_bart.c
 family_bos.c family_buddy.c family_chat.c family_chatnav.c family_feedbag.c family_icbm.c family_icq.c
 family_locate.c family_oservice.c family_popup.c family_stats.c family_userlookup.c flap_connection.c kerberos.c
 misc.c msgcookie.c odc.c oft.c oscar.c oscar_data.c peer.c peer_proxy.c rxhandlers.c snac.c tlv.c
 userinfo.c util.c visibility.c libaim.c"
OBJS=""
for s in $SRCS; do
    $CC $CFLAGS -c $OSCAR/$s -o $OUT/obj/${s%.c}.o
    OBJS="$OBJS $OUT/obj/${s%.c}.o"
done
$CC -shared -o $OUT/libaim.so $OBJS -L$B/devlib -lpurple -lgobject-2.0 -lgmodule-2.0 -lglib-2.0 -Wl,--as-needed
echo "built $OUT/libaim.so"
