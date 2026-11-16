#!/usr/bin/env bash
# Local test install: OpenCart 3.0.3.6 + this module + a second store + fake Claude API,
# then the end-to-end smoke test. For a dev box with PHP 8.x CLI and apt (no Docker).
#
#   tests/setup_oc.sh /path/to/scratch
#
# OpenCart 3 core is not PHP 8 clean: deprecations are hidden (test prepend +
# error_display off in the scratch copy), core is not patched otherwise.
set -euo pipefail

WORK=${1:?usage: setup_oc.sh <scratch dir>}
REPO=$(cd "$(dirname "$0")/.." && pwd)
OC=$WORK/oc
DB=${DB:-oc}
P0=${P0:-8080}
P1=${P1:-8081}
PAPI=${PAPI:-8099}
PSVC=${PSVC:-8098}
PSMTP0=${PSMTP0:-2525}
PSMTP1=${PSMTP1:-2526}
export COMPOSER_ALLOW_SUPERUSER=1

# 1. MariaDB
if ! command -v mysqld_safe >/dev/null; then
	apt-get update -q && DEBIAN_FRONTEND=noninteractive apt-get install -y -q mariadb-server
fi
pgrep -x mariadbd >/dev/null || pgrep -x mysqld >/dev/null || { (mysqld_safe >/dev/null 2>&1 &); sleep 5; }
mysql -uroot -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4;
	CREATE USER IF NOT EXISTS 'oc'@'localhost' IDENTIFIED BY 'oc'; GRANT ALL ON $DB.* TO 'oc'@'localhost';"

# 2. OpenCart 3.0.3.6
mkdir -p "$WORK/dl" "$WORK/tools"
[ -f "$WORK/dl/oc.zip" ] || curl -sSL -o "$WORK/dl/oc.zip" https://github.com/opencart/opencart/releases/download/3.0.3.6/opencart-3.0.3.6.zip
rm -rf "$WORK/dl/upload" "$OC" && (cd "$WORK/dl" && unzip -q -o oc.zip 'upload/*')
cp -r "$WORK/dl/upload" "$OC"
mv "$OC/config-dist.php" "$OC/config.php"; mv "$OC/admin/config-dist.php" "$OC/admin/config.php"
# cli_install.php of 3.0.3.6 uses DIR_STORAGE without defining it
echo "<?php define('DIR_STORAGE', '$OC/system/storage/');" > "$WORK/tools/prepend_storage.php"
(cd "$OC/install" && php -d auto_prepend_file="$WORK/tools/prepend_storage.php" -d error_reporting=0 cli_install.php install \
	--db_hostname localhost --db_username oc --db_password oc --db_database "$DB" --db_driver mysqli --db_port 3306 \
	--username admin --password admin --email admin@example.com --http_server "http://127.0.0.1:$P0/")
# a 2-letter + region language code works for Handover::strings(); demo has en-gb only
sed -i "s/\$_\['error_display'\]\s*= true;/\$_['error_display'] = false;/" "$OC/system/config/default.php"
mysql -uroot "$DB" -e "UPDATE oc_setting SET value='0' WHERE \`key\`='config_error_display';"

# 3. Module files
cp -r "$REPO/upload/"* "$OC/"

# 4. Servers: two storefront ports (store 0 / store 1) + fake API
cat > "$WORK/tools/prepend_test.php" <<EOF
<?php
define('AICHAT_API_BASE', 'http://127.0.0.1:$PAPI');
define('AICHAT_TURNSTILE_URL', 'http://127.0.0.1:$PSVC/siteverify');
define('AICHAT_SPEEDY_BASE', 'http://127.0.0.1:$PSVC/v1');
define('AICHAT_TELEGRAM_BASE', 'http://127.0.0.1:$PSVC/tg');
// mails go to a file unless the flag file exists: then through OpenCart's real Mail class (fake SMTP on $PSMTP0 / $PSMTP1)
if (!is_file('$WORK/mail_real.flag')) define('AICHAT_MAIL_FILE', '$WORK/mail.log');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
EOF
pkill -f "php -d auto_prepend_file=$WORK/tools/prepend_test.php" || true
pkill -f "127.0.0.1:$P0" || true
pkill -f "127.0.0.1:$P1" || true
pkill -f "127.0.0.1:$PAPI" || true
pkill -f "127.0.0.1:$PSVC" || true
pkill -f "tests/fake_smtp.php" || true
rm -f "$WORK/mail.log" "$WORK/fake_tg.json" "$WORK/smtp.log" "$WORK/mail_real.flag"
sleep 0.5
(php -d auto_prepend_file="$WORK/tools/prepend_test.php" -S 127.0.0.1:$P0 -t "$OC" >"$WORK/srv$P0.log" 2>&1 &)
(php -d auto_prepend_file="$WORK/tools/prepend_test.php" -S 127.0.0.1:$P1 -t "$OC" >"$WORK/srv$P1.log" 2>&1 &)
(FAKE_LOG="$WORK/fake.log" FAKE_OWN="http://127.0.0.1:$P0/" php -S 127.0.0.1:$PAPI "$REPO/tests/fake_anthropic.php" >"$WORK/fake_srv.log" 2>&1 &)
(php "$REPO/tests/fake_smtp.php" $PSMTP0 "$WORK/smtp.log" >"$WORK/fake_smtp0.log" 2>&1 &)
(php "$REPO/tests/fake_smtp.php" $PSMTP1 "$WORK/smtp.log" >"$WORK/fake_smtp1.log" 2>&1 &)
(FAKE_SVC_LOG="$WORK/fake_services.log" FAKE_TG_STATE="$WORK/fake_tg.json" php -S 127.0.0.1:$PSVC "$REPO/tests/fake_services.php" >"$WORK/fake_svc_srv.log" 2>&1 &)
sleep 1

# 5. Second store, products split between stores, a store-1 SEO keyword
mysql -uroot "$DB" <<EOF
INSERT INTO oc_store (store_id, name, url, \`ssl\`) VALUES (1, 'Second Store', 'http://127.0.0.1:$P1/', 'http://127.0.0.1:$P1/');
INSERT INTO oc_setting (store_id, code, \`key\`, value, serialized) SELECT 1, code, \`key\`, value, serialized FROM oc_setting WHERE store_id = 0 AND code = 'config' AND \`key\` NOT IN ('config_url', 'config_ssl', 'config_name');
INSERT INTO oc_setting (store_id, code, \`key\`, value, serialized) VALUES (1, 'config', 'config_url', 'http://127.0.0.1:$P1/', 0), (1, 'config', 'config_ssl', 'http://127.0.0.1:$P1/', 0), (1, 'config', 'config_name', 'Second Store', 0);
INSERT INTO oc_setting (store_id, code, \`key\`, value, serialized) SELECT 1, code, \`key\`, value, serialized FROM oc_setting WHERE store_id = 0 AND code = 'theme_default';
INSERT INTO oc_product_to_store (product_id, store_id) VALUES (48, 1), (43, 1), (28, 1);
DELETE FROM oc_product_to_store WHERE store_id = 0 AND product_id IN (48, 43);
INSERT INTO oc_category_to_store (category_id, store_id) SELECT category_id, 1 FROM oc_category_to_store WHERE store_id = 0 AND category_id IN (20, 27, 18, 46, 24, 34);
INSERT INTO oc_information_to_store (information_id, store_id) VALUES (4, 1);
INSERT INTO oc_seo_url (store_id, language_id, query, keyword) VALUES (1, 1, 'product_id=48', 'ipod-classic-s1');
EOF

# 6. Admin: log in, install the module (Installer::install), save settings for both stores
CJ="$WORK/cookies.txt"; rm -f "$CJ"
A="http://127.0.0.1:$P0/admin/index.php"
curl -s -c "$CJ" -b "$CJ" -o /dev/null "$A"
TOKEN=$(curl -s -c "$CJ" -b "$CJ" -D - -o /dev/null -d "username=admin&password=admin" "$A?route=common/login" | sed -n 's/.*user_token=\([A-Za-z0-9]*\).*/\1/p' | head -1)
[ -n "$TOKEN" ] || { echo "admin login failed"; exit 1; }
curl -s -b "$CJ" -o /dev/null "$A?route=extension/extension/module/install&extension=aichat&user_token=$TOKEN"
# (admin settings are saved through the browser in the admin checks; the smoke tests seed what they need)

# 7. Smoke test
RC=0
php "$REPO/tests/installer_test.php" "$OC" localhost oc oc "$DB" oc_ || RC=1
php "$REPO/tests/tracking_test.php" "$OC" || RC=1
SMOKE_DB=localhost:oc:oc:$DB:oc_ SMOKE_OC="$OC" SMOKE_MAIL="$WORK/mail.log" SMOKE_TG="http://127.0.0.1:$PSVC/tg" SMOKE_WORK="$WORK" SMOKE_SMTP="$PSMTP0:$PSMTP1" php "$REPO/tests/smoke_http.php" "http://127.0.0.1:$P0/" "http://127.0.0.1:$P1/" || RC=1
php "$REPO/tests/mail_smtp_test.php" "$OC" localhost oc oc "$DB" oc_ "$WORK/smtp.log" $PSMTP0 || RC=1
(cd "$WORK" && node "$REPO/tests/admin_round4.js" "$WORK") || RC=1
exit $RC
