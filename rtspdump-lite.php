<?php
/* rtspdump-lite.php -- MS-RTSP streaming program
  version 2.6, January 9th, 2011 and July 25, 2012

  Copyright (C) 2010 Joel Yliluoma

  This software is provided 'as-is', without any express or implied
  warranty.  In no event will the authors be held liable for any damages
  arising from the use of this software.

  Permission is granted to anyone to use this software for any purpose,
  including commercial applications, and to alter it and redistribute it
  freely, subject to the following restrictions:

  1. The origin of this software must not be misrepresented; you must not
     claim that you wrote the original software. If you use this software
     in a product, an acknowledgment in the product documentation would be
     appreciated but is not required.
  2. Altered source versions must be plainly marked as such, and must not be
     misrepresented as being the original software.
  3. This notice may not be removed or altered from any source distribution.
  4. Relicensing under GPL is explicitly allowed.

  Joel Yliluoma bisqwit@iki.fi
*/

// Change these as you like
define('MY_OS',        'GNULinux');
define('MY_OSVERSION', 'Fedora 12');
define('USER_AGENT',   'rtspdump-lite-php v2.6');

$stream      = '';
$output_file = 'dump.wmv';

$run_for = 999999999; // some 31.689 years
$begin_offset_seconds = 0;
$debug   = 0;
$verbose = 0;

// These variables are automatically deduced from server:
$rtsp_timeout     = 60; // Maximum mandatory interval for keep-alives
$asf_header       = ''; // Binary string for asf file header
$maxps            = 1600; // Maximum ASF packet size, all are padded to this
// These variables are used in RTSP traffic, automatically deduced from server:
$session  = '';
// Statistics:
$n_mm_packets    = 0;
$n_rtp_packets   = 0;
$n_mm_keyframes  = 0;
$n_mm_bytes      = 0;
$n_net_bytes     = 0;

/* Debug bitmasks. If you change these, change the --help page too. */
define('DEBUG_RTSP',           1);
define('DEBUG_RTP_INCOMING',   2);
define('DEBUG_RTP_REORDERING', 4);
define('DEBUG_RTP_AV',         8);
define('DEBUG_MISC',          16);

/********************************
 * Parse commandline arguments
 */
$opts = @getopt('hVr:o:d::vt:s:',
               Array('help', 'version', 'rtsp:', 'output:',
                     'debug::', 'verbose',
                     'time:', 'seek:'));
$do_help = false;
foreach($opts as $opt => $values)
  switch($opt)
  {
    case 'V': case 'version': print USER_AGENT."\n"; exit;
    case 'h': case 'help': $do_help = true; break;
    case 'r': case 'rtsp':
      if(is_array($values))
        { print "Can only specify one URL\n"; exit; }
      $stream = $values;
      break;
    case 'o': case 'output':
      if(is_array($values))
        { print "Can only specify one output file\n"; exit; }
      $output_file = $values;
      if($values == '-') $output_file = 'php://stdout';
      break;
    case 't': case 'time':
      if(is_array($values))
        { print "Can only specify running time once\n"; exit; }
      $run_for = $values;
      break;
    case 's': case 'seek':
      if(is_array($values))
        { print "Can only specify begin-offset once\n"; exit; }
      $begin_offset_seconds = $values;
      break;
    case 'd': case 'debug':
      if($values == false)
        $debug = ~0;
      elseif(is_array($values))
         foreach($values as $v)
           $debug |= $v;
      else
        $debug = $values;
      break;
    case 'v': case 'verbose':
      $verbose += 1;
  }

if($output_file == 'php://stdout')
{
  // Change the script's output to stderr in order to
  // prevent mangling the outputted multimedia stream.
  $debug_out = fopen('php://stderr', 'w');
  ob_start(create_function('$buf','global $debug_out;fwrite($debug_out,$buf);return "";'),
           32);
}

if($debug & DEBUG_MISC) printf("Commandline options: %s\n", json_encode($opts));
if(!$stream)
  { print "Please specify URL using the -r option\n"; $do_help = true; }

if($do_help)
{
  print USER_AGENT." - Stream recorder for Microsoft's RTSP variant\n". <<<EOF
Copyright (C) 2010-2011, Joel Yliluoma - http://iki.fi/bisqwit/
Usage: php rtspdump-lite.php [<options>]
 -h, --help          This help
 -V, --version       Print version number
 -r, --rtsp <url>    Specify stream URL (example: mms://w.glc.us.com/Med/)
                       This must point to a Microsoft Media Server (MS-RTSP).
                       Other servers, such as Realmedia servers,
                       are not supported. Use Live555 for them.
 -o, --output <file> Specify output filename (default: dump.wmv)
                       In order to dump to stdout, you can specify "-
                       as the output filename. If you use MEncoder/MPlayer to
                       read this stream, be sure to pass the "-demuxer asf"
                       option to it; otherwise it will complain a lot about
                       backward seeking.
 -d, --debug <int>   Debug traffic (use following values, or sum thereof):
                        1 = RTSP responses
                        2 = incoming RTP packets
                        4 = packet reordering
                        8 = A/V packet construction
                        16 = miscellaneous
 -s, --seek <float>  Begin recording at given offset (in seconds)
                       Note that due to the keyframe interval mechanism in
                       video encoding, the stream may actually begin at an
                       earlier or a later offset than what is specified,
                       usually off by a couple of seconds at most.
                       Also, seek offset is ignored for live streams.
 -t, --time <int>    Stop recording after number of seconds
 -v, --verbose       Increase verbosity

This LITE version only does TCP traffic.
Note that some PHP versions don't accept long options
  (e.g. --help). Use short ones instead (e.g. -h).
EOF;
  print "\n\n";
  exit;
}

/********************************
 * Begin RTSP protocol stuff
 */

function socket_readline($sock)
{
  $line = '';
  for(;;)
  {
    $c = socket_read($sock, 1);
    if($c === false || $c === '')
      { print "RTSP socket error: ". socket_strerror(socket_last_error()); return "\r\n"; }
    $line .= $c;
    if($c == "\n") break;
  }
  return $line;
}
function GetRTSPResponse($sock)
{
  $length = 0;
  $result = '';
  for(;;)
  {
    $line = socket_readline($sock);
    if($line == "\r\n") break;
    $result .= $line;
    if(preg_match('/^Content-length:/i', $line))
      $length = (int)preg_replace('@^.*:@', '', $line);
  }
  while($length > 0)
  {
    $s = socket_read($sock, $length);
    if($s === false || $s === '')
      { print "RTSP socket error: ". socket_strerror(socket_last_error()). "\n"; return ""; }
    $result .= $s;
    $length -= strlen($s);
  }
  return $result;
}
function SendRTSPrequest($sock, $command, $request)
{
  global $debug, $verbose;

  if($verbose) print "Sending {$command}\n";
  if($debug & DEBUG_RTSP) print $request;

  for($remain = $request; strlen($remain) > 0; )
  {
    $s = socket_write($sock, $remain);
    if($s === false) { print "RTSP socket error: ". socket_strerror(socket_last_error()). "\n"; return; }
    $remain = substr($remain, $s);
  }
}
function ReadRTSPresponse($sock, $command, $request, $prefix = '', $args = Array())
{
  global $debug, $verbose;
  $s = $prefix . GetRTSPResponse($sock);
  $c = count($args);
  foreach(explode("\r\n", $s) as $line)
  {
    $captures = Array();
    for($a=3; $a<$c; ++$a)
    {
      $arg = $args[$a];
      if(is_array($arg[0])) $captures = $arg; else $captures[] = $arg;
    }
    foreach($captures as $arg)
    {
      $tmp = preg_match($arg[1], $line, $mat);
      if($tmp)
      {
        $tmp = $mat[1];
        $var = $arg[0];
        global $$var;
        eval('$$var = '.str_replace('$1', '$tmp', $arg[2]).';');
        if($debug & DEBUG_MISC)
        {
          if($var == 'asf_header')
            printf("\$%-16s set to binary string (%d bytes)\n", $var, strlen($$var));
          else
            printf("\$%-16s set to %s\n", $var, $$var);
        }
      }
    }
  }

  if($debug & DEBUG_RTSP)
    print "[$command]\n$s\n\n";

  if(substr($s, 0, 13) != 'RTSP/1.0 200 ')
  {
    $type = 'error';
    if($command == 'LogConnect'
    || $command == 'UdpPacketPair'
    || $command == 'TcpPacketPair'
    || $command == 'KeepAlive') $type = 'warning';
    print "RTSP protocol $type: Server does not seem to agree with our intents on $command\n";
    if($type == 'error')
    {
      if(!($debug & DEBUG_RTSP))
      {
        print
          preg_replace('/^/m', '  ',
            "---Failing request:---\n$request\n---Response indicating failure:---\n$s\n");
      }
      exit;
    }
  }

  return $s;
}
function DoRTSPrequest($sock, $command, $request)
{
  global $debug, $verbose;
  SendRTSPrequest($sock, $command, $request);
  $args = func_get_args();
  return ReadRTSPresponse($sock, $command, $request, '', $args);
}

/* Connect to the RTSP server */
$url_components = parse_url($stream);
$dst_host = $url_components['host'];
$dst_addr = gethostbyname($dst_host);
$rtsp_port = @$url_components['port'];
if(!$rtsp_port) $rtsp_port = 554;
// Change protocol to rtsp in case it was given as mms:// or http:// .
$stream = "rtsp://$dst_host{$url_components['path']}";
// WMS won't care about the host name, but it's nice to keep it.

if($verbose) print "Connecting to {$dst_addr}, $rtsp_port for $stream\n";
$rtsp_sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if(!socket_connect($rtsp_sock, $dst_addr, $rtsp_port))
{
  print "RTSP connect error: ". socket_strerror(socket_last_error()) . "\n";
  exit;
}

/////////////////
/* A DESCRIBE request is not required to get streaming
 * started, if you already know all the required information.
 * But for ASF, we really need the asfv1 and maxps fields.
 * Otherwise we cannot produce working ASF files.
 */
$has_video = false;
$has_audio = false;
$s = DoRTSPrequest($rtsp_sock, 'Describe',
  "DESCRIBE {$stream} RTSP/1.0\r\n".
  "User-Agent: ".USER_AGENT."\r\n".
  "\r\n",
  Array('asf_header',  '/.*asfv1;base64,(.*)/', 'base64_decode($1)'), // ASF file header
  Array('maxps',       '/.*maxps:(.*)/',        '(int)$1'),           // Maximum packet size
  Array('has_video',   '/control:video/', 'true'),
  Array('has_audio',   '/control:audio/', 'true')
);

$slash = '/';
if(preg_match('@/$@', $stream)) $slash = '';
if($has_video)
  DoRTSPrequest($rtsp_sock, "SelectStream video",
    "SETUP $stream{$slash}video RTSP/1.0\r\n".
    "User-Agent: ".USER_AGENT."\r\n".
    "Session: $session\r\n".
    "Transport: RTP/AVP/TCP;unicast;interleaved=0-1\r\n".
    "\r\n",
    Array('session',          '/^Session: *([^;]*)/', '$1'),
    Array('rtsp_timeout',     '/timeout=([0-9]+)/', '(int)$1')
  );
if($has_audio)
  DoRTSPrequest($rtsp_sock, "SelectStream audio",
    "SETUP $stream{$slash}audio RTSP/1.0\r\n".
    "User-Agent: ".USER_AGENT."\r\n".
    "Session: $session\r\n".
    "Transport: RTP/AVP/TCP;unicast;interleaved=0-1\r\n".
    "\r\n",
    Array('session',          '/^Session: *([^;]*)/', '$1'),
    Array('rtsp_timeout',     '/timeout=([0-9]+)/', '(int)$1')
  );

/////////////////
/* This is completely optional, but the concept of
 * making "Linux" appear in the media server's logs
 * for the first time is intriguing. (MS-RTSP only)
 */
$param = '<XML><c-os>'.MY_OS.'</c-os><c-osversion>'.MY_OSVERSION.'</c-osversion></XML>';
DoRTSPrequest($rtsp_sock, 'LogConnect',
  "SET_PARAMETER {$stream} RTSP/1.0\r\n".
  "User-Agent: ".USER_AGENT."\r\n".
  "Session: $session\r\n".
  "Content-type: application/x-wms-Logconnectstats;charset=UTF-8\r\n".
  "Content-length: ".strlen($param)."\r\n".
  "\r\n".
  $param
);

$npt   = sprintf('%.3f-', $begin_offset_seconds);
$smpte = sprintf('%d:%d:%d:%.2f-',
    (int)(((int)$begin_offset_seconds) / 3600),
    (int)(((int)$begin_offset_seconds) / 60) % 60,
    (int)(((int)$begin_offset_seconds) % 60),
    29.97 * ($begin_offset_seconds - (int)$begin_offset_seconds));

if($run_for < 999999999)
{
  $t = $begin_offset_seconds + $run_for + 30.0;
  $npt .= sprintf('%.3f', $t);
  $smpte .= sprintf('%d:%d:%d:%.2f',
      (int)(((int)$t) / 3600),
      (int)(((int)$t) / 60) % 60,
      (int)(((int)$t) % 60),
      29.97 * ($t - (int)$t));
}
DoRTSPrequest($rtsp_sock, 'Play',
  "PLAY {$stream} RTSP/1.0\r\n".
  "User-Agent: ".USER_AGENT."\r\n".
  "Session: $session\r\n".
  "Range: smpte={$smpte}\r\n".
  "Bandwidth: 2147483647\r\n".
  "X-Accelerate-Streaming: AccelDuration=8000;AccelBandwidth=5912000\r\n".
  "\r\n"
);

function TCP_buffer_to_RTP($data)
{
  /* In the TCP stream, each RTP packet is encapsulated
   * with a 4-byte packet, where two unknown-meaning bytes
   * prefix a two-byte length value of the RTP packet.
   * Because TCP is streaming, RTP packets may be split
   * between different recv()s, and we must therefore
   * use a buffering scheme.
   * In UDP, the length is that of the packet itself.
   */
  static $remain = 0, $buffer = '', $rest = '', $handling_rtsp_response = false;
  $pos    = 0;
  if($rest != '') { $rest .= $data; $data = $rest; $rest = ''; }
  $length = strlen($data);
  while($pos < $length)
  {
    if(!$remain)
    {
      if($pos+4 > $length) return null;

      if($handling_rtsp_response)
      {
        $pos = strpos($data, "\r\n\r\n", $pos);
        if($pos === false)
        {
          $rest = substr($data, -3);
          return null;
        }
        $pos += 4;
        $handling_rtsp_response = false;
        continue;
      }
      if(substr($data, $pos, 9) == 'RTSP/1.0 ')
      {
        $handling_rtsp_response = true;
        $pos += 9;
        continue;
      }
      $tmp = unpack('Ca/Cb/nlength', substr($data, $pos, 4));
      $pos += 4;
      $remain = $tmp['length'];
      continue;
    }
    $take = $remain;
    if($pos+$take > $length) $take = $length-$pos;
    $buffer .= substr($data, $pos, $take);
    $remain -= $take;
    $pos += $take;
    if($remain == 0)
      { $rest .= substr($data, $pos); $result = $buffer; $buffer = ''; return $result; }
  }
  return null;
}

/**************************/
/*                        */
/* MAIN LOOP - SAVE VIDEO */
/*                        */
/**************************/

if($verbose) print "Opening output file, $output_file\n";
$fp = fopen($output_file, 'w');
fwrite($fp, $asf_header);

$last_wakeup = time()-3;
$run_until = time() + $run_for;

$asfpacket = '';

declare(ticks=1);
function SigTerminate($signal) { exit; }
pcntl_signal(SIGINT,  'SigTerminate');
pcntl_signal(SIGTERM, 'SigTerminate');
pcntl_signal(SIGPIPE, 'SigTerminate');

if($verbose) print "Beginning streaming\n";
for(;;)
{
  $cur_time = time();
  if($cur_time >= $run_until) break;

  if($cur_time > $last_wakeup + $rtsp_timeout - 5) // -5 just in case.
  {
    $last_wakeup = $cur_time;
    /* This is basically "wake-up" or "ping" in MS-RTSP */
    /* Sanctioned in the official specification. */
    if($debug & DEBUG_MISC)
      print
        str_pad('',60)."\r".
        date('Y-m-d H:i:s ')."Sending keep-alive\n";
    SendRTSPrequest($rtsp_sock, 'KeepAlive',
      "GET_PARAMETER {$stream} RTSP/1.0\r\n".
      "Session: $session\r\n".
      "\r\n");
    // The response will be dealt by TCP_buffer_to_RTP().
  }

  // Receive RTP packet
  do {
    $rtp = socket_read($rtsp_sock, $maxps+64);
    if($rtp === false)
    {
      print "Receive from socket failed: ".socket_strerror(socket_last_error())."\n";
      exit;
    }
    if($rtp === '')
    {
      print "Receive from socket failed: Remote end closed connection\n";
      exit;
    }
    $n_net_bytes += strlen($rtp)+20; // assume minimal length TCP packet
    // Push the raw data into a buffer and parse it for any RTP packets
    $rtp = TCP_buffer_to_RTP($rtp);
  } while($rtp === null);

  // Parse RTP packet header
  $hdr = unpack('Cconfig/Cpayloadtype/nseqno/Ntimestamp/Nssrc', $rtp);
  $hdr['mark']        = $hdr['payloadtype'] >= 0x80;
  $hdr['payloadtype'] &= 0x7F;
  $payload_offs = 12 + ($hdr['config'] & 0x0F) * 4;
  $payload = substr($rtp, $payload_offs);
  if($debug & DEBUG_RTP_INCOMING)
    printf("Received RTP #%X (%d/%d bytes) -- TS=%d,SSRC=%X,PT=%u%s\n",
      $hdr['seqno'], strlen($payload), strlen($rtp),
      $hdr['timestamp'], $time['ssrc'], $hdr['payloadtype'],
      $hdr['mark'] ? ',MARK' : '');
  // Handle packet

  $payloadtype = $hdr['payloadtype'];
  if($payloadtype != 96)
    printf("RECEIVED RTP PACKET FOR UNKNOWN PAYLOADTYPE %d, TREAD CAREFULLY\n", $payloadtype);

  /* We've now got a sequential RTP packet. */
  // In TCP mode, we don't care about sequence numbers;
  //              they are always assumed to be sequential.
  $mark = $hdr['mark'];

  /* The payload begins with a special prefix, followed by
   * an ASF data packet piece. We want an ASF data packet,
   * so we can put it into the file.
   */
  $asf_hdr_bits = ord($payload[0]);
  $contd = 4;
  if($asf_hdr_bits & 0x20) $contd += 4; // skip relative timestamp
  if($asf_hdr_bits & 0x10) $contd += 4; // skip duration
  if($asf_hdr_bits & 0x08) $contd += 4; // skip locationid

  if($debug & DEBUG_RTP_AV)
  {
    $format = ($asf_hdr_bits & 0x40) ? 'Nlength' : 'Noffset';
    if($asf_hdr_bits & 0x20) $format .= '/Nrelativetimestamp';
    if($asf_hdr_bits & 0x10) $format .= '/Nduration';
    if($asf_hdr_bits & 0x08) $format .= '/Nlocationid';
    $hdr2          = $hdr;
    $hdr2['ssrc']  = sprintf('%X', $hdr2['ssrc']);
    $hdr2['seqno'] = sprintf('%X', $hdr2['seqno']);
    $hdr2['asfhdrbits'] = $asf_hdr_bits;
    $hdr = unpack($format, $payload);
    $hdr = array_merge($hdr2, $hdr);
    $hdr['payloadlength'] = strlen($payload);
    $s = 'ASF packet';
    foreach($hdr as $k=>$v) $s .= ", $k=$v";
    printf("%s\n", $s);
  }
  $asfpacket .= substr($payload, $contd);

  if($mark)
  {
    // packet_finished
    $asfpacket = str_pad($asfpacket, $maxps, chr(0));

    #printf("Writing %d\n", strlen($asfpacket));

    if($asf_hdr_bits & 0x80)
      ++$n_mm_keyframes;

    $n_mm_bytes += strlen($asfpacket);

    fwrite($fp, $asfpacket);
    fflush($fp);

    ++$n_mm_packets;
    $asfpacket = ''; // a new ASF packet begins here
  }
  ++$n_rtp_packets;

  if($verbose)
  {
    printf("%13.3f RTP:%-5d MM:%-5d K:%d | %.1f MB -> %.1f MB\r",
      $hdr['timestamp']/1000.0,
      $n_rtp_packets,
      $n_mm_packets,
      $n_mm_keyframes,
      $n_net_bytes/1048576,
      $n_mm_bytes/1048576);
    flush();
  }
}
fclose($fp);

if($verbose) print "Ending streaming\n";
