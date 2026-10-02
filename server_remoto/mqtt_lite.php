<?php
// Minimal MQTT 3.1.1 client for short-lived PHP requests: connect, subscribe
// (QoS 0), publish (QoS 0), read until a wanted message arrives, disconnect.
// Enough for crono.php to read the Pi's retained snapshot and to send it a
// command and wait for the ack — no extension or Composer package needed.

class MqttLite {
  private $sock = null;
  private $pid = 0;

  public function __construct(private string $host, private int $port,
                              private string $user, private string $pass,
                              private float $timeout = 5.0) {}

  public function connect(): void {
    $this->sock = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $err, $this->timeout);
    if (!$this->sock) throw new RuntimeException("broker non raggiungibile ($err)");
    stream_set_timeout($this->sock, (int)ceil($this->timeout));
    $id = 'crono-web-' . bin2hex(random_bytes(6));
    $body = self::str('MQTT') . chr(4) . chr(0x80 | 0x40 | 0x02) . pack('n', 30)
          . self::str($id) . self::str($this->user) . self::str($this->pass);
    $this->send(0x10, $body);
    $p = $this->read(microtime(true) + $this->timeout);
    if (!$p || $p['type'] !== 2 || strlen($p['body']) < 2 || ord($p['body'][1]) !== 0) {
      throw new RuntimeException('connessione al broker rifiutata');
    }
  }

  public function subscribe(array $topics): void {
    $body = pack('n', ++$this->pid);
    foreach ($topics as $t) $body .= self::str($t) . chr(0);
    $this->send(0x82, $body);
  }

  public function publish(string $topic, string $payload, bool $retain = false): void {
    $this->send(0x30 | ($retain ? 1 : 0), self::str($topic) . $payload);
  }

  // Collect messages on $topics until $done($got) says so or the time runs out.
  // Returns [topic => payload] with the latest payload per topic.
  public function collect(array $topics, float $seconds, callable $done): array {
    $got = [];
    $until = microtime(true) + $seconds;
    while (microtime(true) < $until) {
      $p = $this->read($until);
      if (!$p) break;
      if ($p['type'] !== 3) continue;                 // SUBACK, PINGRESP, ...
      $b = $p['body'];
      $tl = unpack('n', substr($b, 0, 2))[1];
      $topic = substr($b, 2, $tl);
      $off = 2 + $tl + ((($p['flags'] >> 1) & 3) ? 2 : 0);
      if (in_array($topic, $topics, true)) $got[$topic] = substr($b, $off);
      if ($done($got)) break;
    }
    return $got;
  }

  public function close(): void {
    if ($this->sock) {
      @fwrite($this->sock, "\xE0\x00");
      fclose($this->sock);
      $this->sock = null;
    }
  }

  public function __destruct() { $this->close(); }

  private static function str(string $s): string { return pack('n', strlen($s)) . $s; }

  private function send(int $header, string $body): void {
    $len = strlen($body);
    $enc = '';
    do {
      $d = $len % 128;
      $len = intdiv($len, 128);
      $enc .= chr($len > 0 ? $d | 0x80 : $d);
    } while ($len > 0);
    $pkt = chr($header) . $enc . $body;
    for ($sent = 0; $sent < strlen($pkt); $sent += $n) {
      $n = @fwrite($this->sock, substr($pkt, $sent));
      if (!$n) throw new RuntimeException('scrittura verso il broker fallita');
    }
  }

  // One packet, or null on timeout/EOF.
  private function read(float $until): ?array {
    $h = $this->bytes(1, $until);
    if ($h === null) return null;
    $len = 0;
    for ($mul = 1; ; $mul *= 128) {
      $c = $this->bytes(1, $until);
      if ($c === null) return null;
      $len += (ord($c) & 127) * $mul;
      if (!(ord($c) & 128)) break;
    }
    $body = $len ? $this->bytes($len, $until) : '';
    if ($body === null) return null;
    return ['type' => ord($h) >> 4, 'flags' => ord($h) & 15, 'body' => $body];
  }

  private function bytes(int $n, float $until): ?string {
    $buf = '';
    while (strlen($buf) < $n) {
      $left = $until - microtime(true);
      if ($left <= 0) return null;
      // Blocking read with a shrinking timeout, not stream_select(): select
      // can't see bytes PHP already pulled into its own stream buffer.
      stream_set_timeout($this->sock, (int)$left, (int)(fmod($left, 1) * 1e6));
      $chunk = fread($this->sock, $n - strlen($buf));
      if ($chunk === '' || $chunk === false) return null;
      $buf .= $chunk;
    }
    return $buf;
  }
}
