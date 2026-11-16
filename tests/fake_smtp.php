<?php
/**
 * Tiny fake SMTP server for the rig: speaks enough SMTP for OpenCart's
 * Mail\Smtp (EHLO/HELO, AUTH LOGIN, MAIL FROM, RCPT TO, DATA, QUIT) and
 * appends every received message to a log file.
 *
 *   php tests/fake_smtp.php <port> <log file>
 *
 * Log format per message: "=== <port> <date>\nMAIL FROM:..\nRCPT TO:..\nAUTH: user\n\n<data>\n=== end\n".
 * A connection must finish within 10 s. One connection at a time (enough for tests).
 */
$port = isset($argv[1]) ? (int)$argv[1] : 2525;
$log = isset($argv[2]) ? $argv[2] : sys_get_temp_dir() . '/fake_smtp.log';
$server = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);

if (!$server) {
	fwrite(STDERR, "fake_smtp: $errstr ($errno)\n");
	exit(1);
}

while (true) {
	$conn = @stream_socket_accept($server, 3600);
	if (!$conn) {
		continue;
	}
	stream_set_timeout($conn, 10);
	$out = function ($line) use ($conn) {
		fwrite($conn, $line . "\r\n");
	};
	$out('220 fake-smtp ready');
	$from = '';
	$rcpt = array();
	$user = '';
	$authStep = 0;

	while (($line = fgets($conn, 2048)) !== false) {
		$cmd = rtrim($line, "\r\n");

		if ($authStep === 1) {
			$user = base64_decode($cmd);
			$authStep = 2;
			$out('334 UGFzc3dvcmQ6');
			continue;
		}
		if ($authStep === 2) {
			$authStep = 0;
			$out(base64_decode($cmd) === 'bad-password' ? '535 authentication failed' : '235 ok');
			continue;
		}

		$verb = strtoupper(substr($cmd, 0, 4));

		if ($verb === 'EHLO') {
			$out('250-fake-smtp');
			$out('250 AUTH LOGIN');
		} elseif ($verb === 'HELO') {
			$out('250 fake-smtp');
		} elseif ($verb === 'AUTH') {
			$authStep = 1;
			$out('334 VXNlcm5hbWU6');
		} elseif ($verb === 'MAIL') {
			$from = $cmd;
			$out('250 ok');
		} elseif ($verb === 'RCPT') {
			$rcpt[] = $cmd;
			$out(strpos($cmd, 'reject@') !== false ? '550 no such user' : '250 ok');
		} elseif ($verb === 'DATA') {
			$out('354 go ahead');
			$data = '';
			while (($d = fgets($conn, 4096)) !== false) {
				if (rtrim($d, "\r\n") === '.') {
					break;
				}
				$data .= $d;
			}
			file_put_contents($log, "=== " . $port . " " . date('c') . "\n" . $from . "\n" . implode("\n", $rcpt) . "\nAUTH: " . $user . "\n\n" . str_replace("\r\n", "\n", $data) . "=== end\n", FILE_APPEND | LOCK_EX);
			$out('250 queued');
		} elseif ($verb === 'QUIT') {
			$out('221 bye');
			break;
		} else {
			$out('250 ok');
		}
	}

	fclose($conn);
}
