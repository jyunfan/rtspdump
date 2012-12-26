<?php
/* rtspdump.php -- MS-RTSP streaming program
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

define('RTSPDUMP_MAIN_INCLUDED', true);
require 'globals.php';
require 'rtsp_io.php';
require 'bit_io.php';
require 'rtp_io.php';
require 'mm_io.php';

/********************************
 * Parse commandline arguments
 */
$opts = @getopt('hVr:o:x:a:d::vb:t:s:',
               Array('help', 'version', 'rtsp:', 'output:',
                     'rtxport:', 'avport:', 'debug::', 'verbose',
                     'buffer:', 'time:', 'seek:' ));
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
    case 'x': case 'rxport':
      if(is_array($values))
        { print "Can only specify one RTX UDP port\n"; exit; }
      $rtx_port = $values;
      break;
    case 'a': case 'avport':
      if(is_array($values))
        { print "Can only specify one A/V UDP port\n"; exit; }
      $av_port = $values;
      break;
    case 'b': case 'buffer':
      if(is_array($values))
        { print "Can only specify buffer size once\n"; exit; }
      $buffer_length_in_packets = $values;
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
Copyright (C) 2010/01-06, Joel Yliluoma - http://iki.fi/bisqwit/
Usage: php rtspdump.php [<options>]
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
 -a, --avport <int>  Manually specify UDP port number for A/V traffic
 -x, --rtxport <int> Manually specify UDP port number for RTX traffic
                       To simplify your firewall rules, you can also
                       specify the same port for both A/V (audio/video)
                       and the RTX (retransmissions).
 -b, --buffer <int>  Specify size of the packet buffer. (Default: 50)
                       The program keeps this number of RTP packets in a buffer
                       at all times in order to not make too hasty (wrong)
                       decisions on requesting the retransmitting of packets.
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

If you want to use TCP mode rather than UDP, choose "tcp" as the
A/V port, e.g. "-a tcp". In this mode, no UDP traffic is generated
or expected, and the RTX port setting is ignored. Note though, that
the TCP mode requires slightly higher bandwidth than the UDP mode.

Note that some PHP versions don't accept long options
  (e.g. --help). Use short ones instead (e.g. -h).
Note on firewall requirements if you use UDP mode (default)):
  You will need to allow your firewall to pass incoming UDP packets.
  If you know the server's UDP ports and that they are constant
  (use option "-d1" and look for "server_port"), you might allow
  all UDP traffic from that host and that port.
  Or you can specify your own ports (rtxport, avport) and allow
  incoming traffic to those particular UDP ports. Or you can do both.
EOF;
  print "\n\n";
  exit;
}

if($av_port === 'tcp' && $buffer_length_in_packets == DEFAULT_BUFFER_LENGTH)
{
  if($verbose) print "TCP mode: Disabling buffering\n";
  $buffer_length_in_packets = 0;
}

/********************************
 * Begin RTSP protocol stuff
 */

function CreateListeningSockets()
{
  global $av_sock, $av_port;
  global $rtx_sock, $rtx_port;
  global $debug;

  if($av_port === 'tcp')
  {
    $av_sock = null;
    $rtx_sock = null;
    $rt_port = null;
  }
  else
  {
    $av_sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_bind($av_sock, '0.0.0.0', $av_port);
    socket_getsockname($av_sock, $tmp, $av_port);

    if($rtx_port != 0 && $rtx_port == $av_port)
    {
      $rtx_sock = $av_sock;
      $rtx_port = $av_port;
    }
    else
    {
      $rtx_sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
      socket_bind($rtx_sock, '0.0.0.0', $rtx_port);
      socket_getsockname($rtx_sock, $tmp, $rtx_port);
    }
  }

  if($debug & DEBUG_MISC)
    print "My rtx_port: $rtx_port; av_port: $av_port\n";
}

/* Connect to the RTSP server */
$rtsp = new RTSP($stream);
$rtsp->Connect();

declare(ticks=1);
function SigTerminate($signal)
{
  print "rtspdump: Got signal $signal\n";
  global $rtsp;
  $rtsp->EndStreaming();
  exit;
}
pcntl_signal(SIGINT,  'SigTerminate');
pcntl_signal(SIGTERM, 'SigTerminate');
pcntl_signal(SIGPIPE, 'SigTerminate');

/////////////////
CreateListeningSockets();

start:
$streams = $rtsp->FindStreams($stream);
$rtsp->SubscribeToStreams($streams);

$rtsp->PrepareStreaming();
$rtsp->BeginStreaming();

/**************************/
/*                        */
/* MAIN LOOP - SAVE VIDEO */
/*                        */
/**************************/

$outfiles = Array();
foreach($streams as $control => $streamcfg)
{
  if(isset($outfiles[ $streamcfg['payloadtype'] ] ))
    continue;

  $fn = $output_file;

  if($streamcfg['mediatype'] != 'x-asf-pf')
  {
    $category = $streamcfg['category'];
    $category = preg_replace('/[^a-zA-Z0-9]+/', '_', $category);
    $fn = preg_replace('@\.([^.]*)$@', ".{$category}.raw", $fn);
  }

  if($verbose) print "Opening output file, $fn\n";

  switch(strtoupper($streamcfg['mediatype']))
  {
    case 'X-ASF-PF':
      $saver = new PacketConstruct_X_ASF_PF($streamcfg, $fn);
      break;
    case 'MPEG4-GENERIC':
      $saver = new PacketConstruct_MPEG4_GENERIC($streamcfg, $fn);
      break;
    case 'H264':
      $saver = new PacketConstruct_H264($streamcfg, $fn);
      break;
    default:
      $saver = new PacketConstruct_Default($streamcfg, $fn);
      break;
  }
  $outfiles[ $streamcfg['payloadtype'] ] = $saver;
}

$readers = Array();
foreach($streams as $control => $streamcfg)
{
  $readers[ $streamcfg['payloadtype'] ] = new RTPpacketSequencer( );
}

$last_wakeup = time()-3;
$run_until = time() + $run_for;

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
        #str_pad('',60)."\r".
        date('Y-m-d H:i:s ')."Sending keep-alive\n";
    $rtsp->SendKeepAlive();
  }

  // Receive request from server
  $reqs = $rtsp->GetRequests();
  if ($reqs !== false) {
    // quick but dirty
    foreach ($reqs as $req) {
      #print "REQ: $req\n";
      if (preg_match("/End-of-Stream Reached/", $req)) {
        if($debug) { print "End of stream reaches\n"; }
        $rtsp->StopStreaming();
      } else if (preg_match("/^ANNOUNCE/", $req)) {
        if($debug) { print "ANNOUNCE\n"; }
        goto start;
      }
    }
  }

  $packet = ReceiveRTP();
  if ($packet === false) {
    //if ($debug) { print "ReceiveRTP does not get packets.\n"; }
    continue;
  }
  $payloadtype = $packet[0]['payloadtype'];

  if(!isset($readers[$payloadtype]))
  {
    printf("RECEIVED RTP PACKET FOR UNKNOWN PAYLOADTYPE %d\n", $payloadtype);
    continue;
  }

  $this_stream_cfg = null;
  foreach($streams as $control => $streamcfg)
    if($streamcfg['payloadtype'] == $payloadtype)
    {
      $this_stream_cfg = $streamcfg;
      break;
    }
  if($this_stream_cfg === null)
  {
    static $payloadwarnings = Array();
    if(!isset($payloadwarnings[$payloadtype]))
    {
      $payloadwarnings[$payloadtype] = true;
      printf("PAYLOAD TYPE %d IS UNKNOWN, SKIPPING THEM\n", $payloadtype);
    }
    continue;
  }
  $saver  = &$outfiles[$payloadtype];
  $reader = &$readers[$payloadtype];
  $reader->HandleIncomingPacket($packet);
  //if ($debug) { print "Start RetrieveNextPacket loop\n"; }
  while(($rtppacket = $reader->RetrieveNextPacket()) !== null)
  {
    /* We've now got a sequential RTP packet. */
    //if(rand(0,250) != 0)
      $saver->DoPacket($rtppacket);
    //else
    //  print "TEST: DROPPING PACKET\n";
    ++$n_rtp_packets;

    if($verbose)
    {
      #printf("%13.3f RTX:%d->%d Drop:%d RTP:%-5d MM:%-5d K:%d | %.1f MB -> %.1f MB\r",
      printf("%13.3f RTX:%d->%d Drop:%d RTP:%-5d MM:%-5d K:%d | %.1f MB -> %.1f MB\n",
        $rtppacket[0]['timestamp']/1000.0,
        $n_retransmit_requested,
        $n_retransmit_received,
        $n_dropped,
        $n_rtp_packets,
        $n_mm_packets,
        $n_mm_keyframes,
        $n_net_bytes/1048576,
        $n_mm_bytes/1048576);
      flush();
    }
  }
  #if ($debug) { print "End RetrieveNextPacket loop\n"; }
  unset($saver);
  unset($reader);
}
unset($readers);
unset($outfiles);

if($verbose) print "Ending streaming\n";
$rtsp->EndStreaming();
