#!/bin/sh
# Builds dist/aichat.ocmod.zip from upload/ + install.xml
set -e
cd "$(dirname "$0")"
mkdir -p dist
rm -f dist/aichat.ocmod.zip
zip -r -X dist/aichat.ocmod.zip upload install.xml -x '*.DS_Store'
echo "Built dist/aichat.ocmod.zip"
