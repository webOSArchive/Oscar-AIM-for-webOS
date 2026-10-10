#!/bin/sh
# Puts AIM back on a webOS CE TouchPad, for testing against the webOS Chat server:
#   /usr/lib/purple-2/libaim.so            the AIM plugin (client/libaim/build-touchpad.sh)
#   /usr/palm/public/accounts/com.palm.aol Palm's AIM account type, from stock webOS 3.0.5
# Both are new files; nothing on the device is replaced. The root filesystem is remounted
# read-write for the copy and read-only again after.
#
#   touchpad-install.sh <stock 3.0.5 com.palm.aol folder>    install
#   touchpad-install.sh --remove                             take both out again
set -e
DEV=${TP_DEVICE:-topaz-linux}
HERE=$(cd "$(dirname "$0")" && pwd)
PLUGIN=$HERE/../build/touchpad/out/libaim.so

run() { printf '%s\nexit\n' "$1" | novaterm -d "$DEV" 2>&1 | tr -d '\r' | grep -v '^root@'; }

if [ "$1" = --remove ]; then
    run 'mount -o remount,rw / && rm -f /usr/lib/purple-2/libaim.so && rm -rf /usr/palm/public/accounts/com.palm.aol; mount -o remount,ro /'
    echo removed; exit 0
fi

AOL=${1:?usage: $0 <stock 3.0.5 com.palm.aol folder> | --remove}
[ -f "$PLUGIN" ] || { echo "build $PLUGIN first (client/libaim/build-touchpad.sh)"; exit 1; }
tar czf /tmp/aol-template.tgz -C "$(dirname "$AOL")" "$(basename "$AOL")"
novacom -d "$DEV" put file:///tmp/libaim.so < "$PLUGIN"
novacom -d "$DEV" put file:///tmp/aol-template.tgz < /tmp/aol-template.tgz
run 'mount -o remount,rw / && cp /tmp/libaim.so /usr/lib/purple-2/libaim.so && chmod 755 /usr/lib/purple-2/libaim.so && tar xzf /tmp/aol-template.tgz -C /usr/palm/public/accounts; mount -o remount,ro /; rm -f /tmp/libaim.so /tmp/aol-template.tgz; ls -la /usr/lib/purple-2/libaim.so /usr/palm/public/accounts/com.palm.aol'
